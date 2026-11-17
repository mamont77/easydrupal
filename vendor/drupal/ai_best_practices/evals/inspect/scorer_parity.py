#!/usr/bin/env python3
"""Scorer-parity reference for the Inspect AI runner (abp #3588916).

The Inspect runner's `@scorer` MUST wrap ``evals/scoring.py`` rather than
reimplement the checks. To prove that wrapping is lossless we freeze, as a golden
snapshot, the verdict ``scoring.grade()`` produces for each recorded response
fixture. When the Inspect scorer lands (task 3) its test replays the same battery
through the Inspect ``Score`` path and asserts an identical pass/fail + detail
against this golden.

Until then the golden doubles as a **drift guard** on ``scoring.py`` and on each
skill's ``dataset.yaml`` / ``rubrics.yaml``: regenerate the battery, diff against
the committed golden, and any change in scored behaviour shows up.

No API calls and no tokens: the responses are recorded traces already on disk at
``evals/<skill>/traces/**/<id>.json`` (each trace carries its case ``id`` and the
model ``response``). Only skills that have BOTH a dataset and recorded traces get
a golden; the test iterates over committed ``golden/*.json`` files, so coverage
grows as real runs are recorded — nothing synthetic is invented here.

Usage::

    python3 evals/inspect/scorer_parity.py            # verify goldens (drift guard)
    python3 evals/inspect/scorer_parity.py --update    # regenerate all goldens
    python3 evals/inspect/scorer_parity.py --update drupal-gitlab   # one skill
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
EVALS_DIR = HERE.parent
GOLDEN_DIR = HERE / "golden"

sys.path.insert(0, str(EVALS_DIR))
import scoring  # noqa: E402  (after sys.path insert)


def battery(skill: str) -> list[dict]:
    """Pair each recorded trace response with its current dataset checks.

    Keyed by the trace's own ``id`` field, so it is robust to how the traces
    directory is nested. Strict on the join so the battery cannot silently
    shrink and still pass:

    * a trace whose ``id`` matches no current case (stale/mis-keyed/renamed)
      raises rather than being dropped;
    * two traces for the same case raise rather than one silently overwriting
      the other (a single recorded run is expected; multi-provider/epoch
      capture would need a richer key and is out of scope here).
    """
    cases = {c["id"]: c for c in scoring.load_cases(skill)}
    traces_dir = EVALS_DIR / skill / "traces"
    items = []
    seen: dict[str, str] = {}
    orphans: list[str] = []
    for tf in sorted(traces_dir.rglob("*.json")):
        trace = json.loads(tf.read_text())
        cid = trace.get("id")
        rel = str(tf.relative_to(EVALS_DIR))
        if cid not in cases:
            orphans.append(f"{rel} (id={cid!r})")
            continue
        if cid in seen:
            raise ValueError(
                f"{skill}: two traces for case {cid!r} ({seen[cid]} and {rel}); "
                "parity expects exactly one recorded trace per active case"
            )
        seen[cid] = rel
        items.append({
            "id": cid,
            "response": trace["response"],
            "checks": cases[cid]["checks"],
            "combine": cases[cid]["combine"],
        })
    if orphans:
        raise ValueError(
            f"{skill}: traces with no current case (remove or re-key them): "
            + ", ".join(orphans)
        )
    return items


def grade_battery(skill: str) -> dict:
    """Verdict map {case_id: {passed, detail}} from scoring.grade over the battery."""
    out = {}
    for item in battery(skill):
        passed, detail, _ = scoring.grade(
            item["response"], item["checks"], item["combine"])
        out[item["id"]] = {"passed": passed, "detail": detail}
    return out


def golden_path(skill: str) -> Path:
    return GOLDEN_DIR / f"{skill}.json"


def skills_with_traces() -> list[str]:
    """Top-level skills that have both a dataset and a non-empty traces dir."""
    found = []
    for d in sorted(EVALS_DIR.iterdir()):
        if not d.is_dir():
            continue
        traces = d / "traces"
        if traces.is_dir() and any(traces.rglob("*.json")) and scoring.has_dataset(d.name):
            found.append(d.name)
    return found


def write_golden(skill: str) -> dict:
    GOLDEN_DIR.mkdir(exist_ok=True)
    data = grade_battery(skill)
    golden_path(skill).write_text(
        json.dumps(data, indent=2, sort_keys=True) + "\n")
    return data


def verify(skill: str) -> list[str]:
    """Return a list of mismatch descriptions ([] means in parity)."""
    gp = golden_path(skill)
    if not gp.exists():
        return [f"{skill}: no golden committed (run --update {skill})"]
    golden = json.loads(gp.read_text())
    current = grade_battery(skill)
    problems = []
    for cid in sorted(set(golden) | set(current)):
        g, c = golden.get(cid), current.get(cid)
        if g is None:
            problems.append(f"{skill}/{cid}: present now, missing from golden")
        elif c is None:
            problems.append(f"{skill}/{cid}: in golden, no longer produced")
        elif g != c:
            # Report the fields that actually moved. Comparing whole dicts but
            # printing only `passed` rendered a detail-only drift as the
            # nonsense "passed=True -> now True", which hides the real cause
            # (e.g. php_lint degrading to "php not found" on a host with no PHP).
            moved = [
                f"{k}: {g.get(k)!r} -> {c.get(k)!r}"
                for k in sorted(set(g) | set(c))
                if g.get(k) != c.get(k)
            ]
            problems.append(f"{skill}/{cid}: " + "; ".join(moved))
    return problems


def main(argv: list[str]) -> int:
    update = "--update" in argv
    named = [a for a in argv if not a.startswith("-")]
    if update:
        skills = named or skills_with_traces()
        if not skills:
            print("no skills with both a dataset and recorded traces")
            return 0
        for sk in skills:
            data = write_golden(sk)
            print(f"wrote golden/{sk}.json ({len(data)} cases)")
        return 0

    # default: verify every committed golden against current scoring
    goldens = sorted(p.stem for p in GOLDEN_DIR.glob("*.json")) if GOLDEN_DIR.is_dir() else []
    if not goldens:
        print("no goldens to verify (run --update first)")
        return 0
    all_problems = []
    for sk in goldens:
        problems = verify(sk)
        if problems:
            all_problems.extend(problems)
        else:
            print(f"OK  {sk} ({len(json.loads(golden_path(sk).read_text()))} cases)")
    if all_problems:
        print("\nPARITY DRIFT:")
        for p in all_problems:
            print(f"  - {p}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
