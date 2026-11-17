#!/usr/bin/env python3
"""Paired control-arm analysis for the abp Inspect runner (#3588916, task 6).

The control arm answers the question abp actually cares about: *does the skill
help?* It runs every case twice — once with the skill context injected (arm
``on``) and once without (arm ``off``) — and reports a **paired** delta with a
significance test, not two unrelated pass rates.

Why paired, not two independent rates: the same cases run in both arms, so the
arms are correlated. The honest statistic is McNemar's test over the per-case
(on, off) outcomes — it looks only at the cases where the two arms DISAGREE
(discordant pairs) and asks whether the skill flipping fails->passes happens
more than chance. ``compare.py`` reported only a bare point delta (no CI, no
significance); this closes that gap.

No pandas/scipy (neither is in the behavioral extra): McNemar's exact two-sided
p-value is a binomial tail on the discordant pairs, computed with ``math.comb``.

Pure functions over a list of (case_id, arm, passed) rows so the math is unit
testable without running a model. ``main()`` analyzes a recorded ``.eval`` log.
"""
from __future__ import annotations

import math
from collections import defaultdict

from pydantic import BaseModel


class PairedResult(BaseModel):
    """Paired control-arm outcome.

    A pydantic model (not a stdlib dataclass) to match inspect-ai's own
    datatypes — Score / ModelOutput / EvalLog are all pydantic — and so the
    result serializes (``model_dump_json``) cleanly when it later lands in an
    EvalLog or EEE envelope. pydantic is already an inspect-ai dependency, so
    this adds nothing to install. The significance math below stays pure-Python:
    pydantic validates/serializes data, it is not a stats library.
    """

    n_pairs: int          # cases present in BOTH arms
    on_pass: int          # cases passing with skill (arm on)
    off_pass: int         # cases passing without skill (arm off)
    both_pass: int        # concordant pass
    both_fail: int        # concordant fail
    on_only: int          # discordant: on passes, off fails  (skill HELPED)
    off_only: int         # discordant: off passes, on fails  (skill HURT)
    delta: float          # on_rate - off_rate (paired point estimate)
    p_value: float        # McNemar exact two-sided
    on_rate: float
    off_rate: float
    on_stderr: float      # SE of each arm's proportion (sqrt(p(1-p)/n))
    off_stderr: float
    dropped: int          # cases not present in exactly both arms (excluded)


def _binom_two_sided(b: int, c: int) -> float:
    """McNemar exact two-sided p-value over discordant counts b, c.

    Under H0 each of the n=b+c discordant pairs is a fair coin. The two-sided
    exact p is 2 * P(X <= min(b, c)) with X ~ Binomial(n, 0.5), capped at 1.0.
    With no discordant pairs there is no evidence either way -> p = 1.0.
    """
    n = b + c
    if n == 0:
        return 1.0
    k = min(b, c)
    tail = sum(math.comb(n, i) for i in range(k + 1)) / (2 ** n)
    return min(1.0, 2.0 * tail)


def _stderr(passed: int, n: int) -> float:
    if n == 0:
        return 0.0
    p = passed / n
    return math.sqrt(p * (1.0 - p) / n)


def paired_delta(rows: list[tuple[str, str, bool]]) -> PairedResult:
    """Compute the paired control-arm result.

    rows: (case_id, arm, passed) where arm is "on" (skill) or "off" (no skill).
    A case contributes a pair only if it has exactly one "on" and one "off"
    outcome; anything else (missing arm, errored sample, duplicate) is dropped
    and counted, never silently treated as a fail.
    """
    by_case: dict[str, dict[str, list[bool]]] = defaultdict(lambda: {"on": [], "off": []})
    for case_id, arm, passed in rows:
        if arm in ("on", "off"):
            by_case[case_id][arm].append(passed)

    pairs: list[tuple[bool, bool]] = []
    dropped = 0
    for case_id, arms in by_case.items():
        if len(arms["on"]) == 1 and len(arms["off"]) == 1:
            pairs.append((arms["on"][0], arms["off"][0]))
        else:
            dropped += 1

    n = len(pairs)
    both_pass = sum(1 for on, off in pairs if on and off)
    both_fail = sum(1 for on, off in pairs if not on and not off)
    on_only = sum(1 for on, off in pairs if on and not off)
    off_only = sum(1 for on, off in pairs if not on and off)
    on_pass = both_pass + on_only
    off_pass = both_pass + off_only
    on_rate = on_pass / n if n else 0.0
    off_rate = off_pass / n if n else 0.0
    return PairedResult(
        n_pairs=n,
        on_pass=on_pass,
        off_pass=off_pass,
        both_pass=both_pass,
        both_fail=both_fail,
        on_only=on_only,
        off_only=off_only,
        delta=on_rate - off_rate,
        p_value=_binom_two_sided(on_only, off_only),
        on_rate=on_rate,
        off_rate=off_rate,
        on_stderr=_stderr(on_pass, n),
        off_stderr=_stderr(off_pass, n),
        dropped=dropped,
    )


def majority_rows(rows: list[tuple[str, str, bool]]) -> list[tuple[str, str, bool]]:
    """Collapse repeated runs (Inspect epochs) to one outcome per (case, arm).

    A case passes an arm when it passed more than half of that arm's runs. The
    runs of one case are not independent, so pairing every epoch separately
    would inflate n and overstate McNemar significance; one majority outcome
    per case keeps the test's one-pair-per-case contract. A tie (even number
    of runs, split evenly) yields no outcome for that arm, so paired_delta()
    drops and counts the case rather than guessing.
    """
    runs: dict[tuple[str, str], list[bool]] = defaultdict(list)
    for case_id, arm, passed in rows:
        runs[(case_id, arm)].append(passed)
    out: list[tuple[str, str, bool]] = []
    for (case_id, arm), outcomes in runs.items():
        passes = sum(outcomes)
        if passes * 2 != len(outcomes):
            out.append((case_id, arm, passes * 2 > len(outcomes)))
    return out


def rows_from_eval_log(log) -> list[tuple[str, str, bool]]:
    """Extract (case_id, arm, passed) rows from an Inspect EvalLog.

    Reads metadata.case_id / metadata.arm set by the ``control`` task. Errored
    samples are skipped (they are not a gradable pass/fail) — paired_delta()
    then drops the case for missing an arm. With ``--epochs N`` each case runs
    N times per arm; majority_rows() reduces those to one outcome per arm, so
    the case is paired rather than dropped.
    """
    from inspect_ai.scorer import CORRECT

    rows: list[tuple[str, str, bool]] = []
    for s in log.samples or []:
        if s.error is not None:
            continue
        md = s.metadata or {}
        case_id, arm = md.get("case_id"), md.get("arm")
        if case_id is None or arm is None:
            continue
        score = next(iter(s.scores.values())) if s.scores else None
        rows.append((case_id, arm, score is not None and score.value == CORRECT))
    return majority_rows(rows)


def format_report(r: PairedResult, title: str = "") -> str:
    lines = []
    if title:
        lines.append(title)
    lines.append(f"  paired cases:   {r.n_pairs}" + (f"  (dropped {r.dropped})" if r.dropped else ""))
    lines.append(f"  with skill:     {r.on_pass}/{r.n_pairs}  ({r.on_rate:.1%} ± {r.on_stderr:.1%})")
    lines.append(f"  without skill:  {r.off_pass}/{r.n_pairs}  ({r.off_rate:.1%} ± {r.off_stderr:.1%})")
    lines.append(f"  paired delta:   {r.delta:+.1%}   (skill helped {r.on_only}, hurt {r.off_only}, "
                 f"agreed {r.both_pass + r.both_fail})")
    sig = "significant" if r.p_value < 0.05 else "NOT significant"
    lines.append(f"  McNemar exact:  p = {r.p_value:.3f}  ({sig} at α=0.05; n={r.on_only + r.off_only} discordant)")
    if r.on_only + r.off_only < 6:
        lines.append("  NOTE: too few discordant pairs for a powered test — treat as directional, not conclusive.")
    return "\n".join(lines)


def main() -> int:
    import argparse

    ap = argparse.ArgumentParser(description="Paired control-arm analysis over a recorded Inspect .eval log")
    ap.add_argument("log", help="path to a .eval log produced by the `control` task")
    args = ap.parse_args()

    from inspect_ai.log import read_eval_log

    log = read_eval_log(args.log)
    result = paired_delta(rows_from_eval_log(log))
    print(format_report(result, title=f"=== control-arm: {log.eval.task} ==="))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
