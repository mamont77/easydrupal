#!/usr/bin/env python3
"""Parity test for the Inspect AI runner's scorer (abp #3588916, task 1).

Plain-`python3` harness (no pytest), matching evals/test_scoring.py. Two layers:

1. ACTIVE NOW — drift guard. Every committed ``golden/<skill>.json`` must still
   match what ``scoring.grade()`` produces over that skill's recorded responses.
   This locks the scored behaviour of the real cases and is the reference the
   Inspect scorer has to reproduce. No tokens, no inspect_ai.

2. ACTIVE (needs inspect_ai) — the Inspect-scorer replay. Feeds each recorded
   response through ``tasks.commons_scorer()`` (which wraps ``scoring.grade``)
   via a synthetic ``TaskState`` and asserts the Inspect ``Score`` path returns
   ``CORRECT/INCORRECT`` + detail equal to the golden — proving the lifted
   ``@scorer`` cannot silently diverge from the single source. No tokens. SKIPs
   only when ``inspect_ai`` is absent (e.g. the pip-free ``evals-scoring`` CI
   job); it runs in ``evals-inspect``.

Run: ``python3 evals/inspect/test_scorer_parity.py``
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import scorer_parity as sp  # noqa: E402

failures: list[str] = []


def check(name: str, cond: bool) -> None:
    if not cond:
        failures.append(name)


# --- 1. drift guard over every committed golden -------------------------------
EVALS_DIR = HERE.parent
all_datasets = sorted(p.parent.relative_to(EVALS_DIR).as_posix()
                      for p in EVALS_DIR.rglob("dataset.yaml"))
goldens = sorted(p.stem for p in (HERE / "golden").glob("*.json")) \
    if (HERE / "golden").is_dir() else []
check("golden/at-least-one", len(goldens) > 0)

# Coverage is intentionally partial — a golden needs recorded traces, and those
# cost behavioral-run tokens. Print it so the gap is visible, never silent.
uncovered = [d for d in all_datasets if d not in goldens]
print(f"parity coverage: {len(goldens)}/{len(all_datasets)} datasets have a golden")
if uncovered:
    print(f"  no recorded traces yet (uncovered): {', '.join(uncovered)}")

for skill in goldens:
    problems = sp.verify(skill)
    check(f"parity/{skill}", not problems)
    for p in problems:
        failures.append(f"  drift: {p}")
    # a golden must actually cover cases — an empty battery would pass vacuously
    battery = sp.battery(skill)
    check(f"battery/{skill}/nonempty", len(battery) > 0)


# --- 2. Inspect-scorer replay: prove tasks.commons_scorer() == golden ----------
# Pure scorer test — feeds each recorded response into the Inspect Score path via
# a synthetic TaskState. No model call, no tokens. Requires inspect_ai + tasks.py;
# SKIPs (not fails) only when inspect_ai is absent (the pip-free CI path).
def inspect_replay_status() -> tuple[str, str]:
    """(level, message). SKIP = expected-absent; FAIL = real divergence."""
    try:
        import anyio
        from inspect_ai.model import ModelOutput
        from inspect_ai.scorer import CORRECT, Target
        from inspect_ai.solver import TaskState
    except ImportError:
        return "SKIP", "inspect_ai not installed — behavioral extras only"
    try:
        import tasks
    except ImportError:
        return "SKIP", "evals/inspect/tasks.py not built yet — task 3"

    score_fn = tasks.commons_scorer()
    mismatches = []
    for skill in goldens:
        golden = json.loads((HERE / "golden" / f"{skill}.json").read_text())
        for item in sp.battery(skill):
            state = TaskState(
                model="abp_cli/test",
                sample_id=item["id"],
                epoch=1,
                input="",
                messages=[],
                output=ModelOutput.from_content(
                    model="abp_cli/test", content=item["response"]),
                metadata={"checks": item["checks"], "combine": item["combine"]},
            )
            score = anyio.run(score_fn, state, Target(""))
            got = {"passed": score.value == CORRECT, "explanation": score.explanation}
            want = golden.get(item["id"], {})
            # golden stores "detail"; the scorer maps it to Score.explanation
            if (got["passed"] != want.get("passed")
                    or got["explanation"] != want.get("detail")):
                mismatches.append(
                    f"{skill}/{item['id']}: scorer passed={got['passed']} "
                    f"golden passed={want.get('passed')}")
    if mismatches:
        return "FAIL", "; ".join(mismatches)
    return "PASS", f"scorer == golden across {len(goldens)} skill(s)"


_level, _msg = inspect_replay_status()
print(f"inspect-scorer replay: {_level} ({_msg})")
if _level == "FAIL":
    failures.append(f"inspect-replay: {_msg}")

if failures:
    print(f"FAILED ({len([f for f in failures if not f.startswith('  ')])}):")
    for f in failures:
        print("  -" if not f.startswith("  ") else "  ", f)
    raise SystemExit(1)
print(f"OK — scorer parity holds across {len(goldens)} golden(s)")
