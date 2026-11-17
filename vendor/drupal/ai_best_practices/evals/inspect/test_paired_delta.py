#!/usr/bin/env python3
"""Unit test for the control-arm paired-delta math (abp #3588916, task 6).

Plain-``python3`` harness (no pytest), matching the other abp eval tests. No
tokens, no model. Pins the McNemar exact p-value and the paired aggregation, and
— when scipy happens to be installed — cross-checks the hand-rolled exact test
against ``scipy.stats.binomtest`` so the pure-Python implementation is validated
against a library WITHOUT making scipy a dependency.

Run: ``python3 evals/inspect/test_paired_delta.py`` (needs pydantic, which the
behavioral extra already provides via inspect-ai).
"""
from __future__ import annotations

import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

try:
    import paired_delta as pd
except ImportError as e:  # pydantic missing -> behavioral extra not installed
    print(f"SKIP test_paired_delta: {e}")
    raise SystemExit(0)

failures: list[str] = []


def check(name: str, cond: bool, got=None) -> None:
    if not cond:
        failures.append(f"{name}" + (f" (got {got!r})" if got is not None else ""))


def approx(a: float, b: float, tol: float = 1e-9) -> bool:
    return abs(a - b) <= tol


# --- McNemar exact two-sided, known values -----------------------------------
# Symmetric binomial (p=0.5): two-sided = 2*P(X<=min(b,c)), capped at 1.
check("mcnemar/no-discordant", approx(pd._binom_two_sided(0, 0), 1.0), pd._binom_two_sided(0, 0))
check("mcnemar/5-0", approx(pd._binom_two_sided(5, 0), 2 * 1 / 32), pd._binom_two_sided(5, 0))  # 0.0625
check("mcnemar/6-0", approx(pd._binom_two_sided(6, 0), 2 * 1 / 64), pd._binom_two_sided(6, 0))  # 0.03125
check("mcnemar/symmetric", approx(pd._binom_two_sided(4, 1), pd._binom_two_sided(1, 4)))         # order-free
check("mcnemar/balanced-capped", approx(pd._binom_two_sided(3, 3), 1.0), pd._binom_two_sided(3, 3))
check("mcnemar/5-0-not-sig", pd._binom_two_sided(5, 0) >= 0.05)   # 5 discordant one way is NOT enough at .05
check("mcnemar/6-0-sig", pd._binom_two_sided(6, 0) < 0.05)        # 6 is

# --- stderr of a proportion --------------------------------------------------
check("stderr/zero-n", approx(pd._stderr(0, 0), 0.0))
check("stderr/half", approx(pd._stderr(5, 10), (0.5 * 0.5 / 10) ** 0.5))
check("stderr/all-pass", approx(pd._stderr(10, 10), 0.0))  # p=1 -> 0

# --- paired aggregation: skill clearly helps ---------------------------------
# 6 cases skill-helped (on pass / off fail), 3 cases both pass (concordant).
rows = []
for i in range(6):
    rows += [(f"h{i}", "on", True), (f"h{i}", "off", False)]
for i in range(3):
    rows += [(f"c{i}", "on", True), (f"c{i}", "off", True)]
r = pd.paired_delta(rows)
check("agg/n-pairs", r.n_pairs == 9, r.n_pairs)
check("agg/on-pass", r.on_pass == 9, r.on_pass)
check("agg/off-pass", r.off_pass == 3, r.off_pass)
check("agg/on-only", r.on_only == 6, r.on_only)
check("agg/off-only", r.off_only == 0, r.off_only)
check("agg/both-pass", r.both_pass == 3, r.both_pass)
check("agg/delta", approx(r.delta, 9 / 9 - 3 / 9), r.delta)
check("agg/p-significant", r.p_value < 0.05, r.p_value)

# --- no effect: every case agrees -> delta 0, p = 1 --------------------------
rows2 = []
for i in range(5):
    v = i % 2 == 0
    rows2 += [(f"x{i}", "on", v), (f"x{i}", "off", v)]
r2 = pd.paired_delta(rows2)
check("noeffect/delta-zero", approx(r2.delta, 0.0), r2.delta)
check("noeffect/p-one", approx(r2.p_value, 1.0), r2.p_value)
check("noeffect/discordant-zero", r2.on_only == 0 and r2.off_only == 0)

# --- dropped: a case missing an arm is dropped, never counted as a fail ------
rows3 = [("a", "on", True), ("a", "off", False), ("b", "on", True)]  # b has no off
r3 = pd.paired_delta(rows3)
check("dropped/count", r3.dropped == 1, r3.dropped)
check("dropped/n-pairs", r3.n_pairs == 1, r3.n_pairs)

# --- epochs: repeated runs collapse to one majority outcome per (case, arm) ---
# Before majority_rows, three runs per arm meant three "on" rows per case and
# paired_delta() dropped every case (n_pairs 0) without saying why.
rows4 = (
    [("a", "on", True)] * 3 + [("a", "off", False), ("a", "off", False), ("a", "off", True)]
    + [("b", "on", True), ("b", "on", False), ("b", "on", True)] + [("b", "off", True)] * 3
)
r4 = pd.paired_delta(pd.majority_rows(rows4))
check("epochs/all-paired", r4.n_pairs == 2 and r4.dropped == 0, (r4.n_pairs, r4.dropped))
check("epochs/majority-on", r4.on_pass == 2, r4.on_pass)          # a 3/3, b 2/3
check("epochs/majority-off", r4.off_pass == 1, r4.off_pass)       # a 1/3 fails, b 3/3 passes
check("epochs/one-pair-per-case", r4.on_only + r4.off_only + r4.both_pass + r4.both_fail == 2)
check("epochs/single-run-unchanged", pd.majority_rows(rows) == rows)
# An even split has no majority: the arm yields no outcome and the case is dropped, not guessed.
rows5 = [("c", "on", True), ("c", "on", False), ("c", "off", False), ("c", "off", False)]
r5 = pd.paired_delta(pd.majority_rows(rows5))
check("epochs/tie-dropped", r5.n_pairs == 0 and r5.dropped == 1, (r5.n_pairs, r5.dropped))

# --- pydantic model: serializes cleanly (matters for EvalLog/EEE later) -------
dumped = r.model_dump()
check("model/dump-is-dict", isinstance(dumped, dict) and dumped["n_pairs"] == 9)
check("model/json-roundtrips", pd.PairedResult.model_validate_json(r.model_dump_json()).delta == r.delta)

# --- cross-check vs scipy IF present (validation only; scipy is NOT a dep) ----
try:
    from scipy.stats import binomtest  # type: ignore
    for b, c in [(6, 0), (5, 0), (8, 1), (2, 7), (0, 0)]:
        mine = pd._binom_two_sided(b, c)
        lib = binomtest(min(b, c), b + c, 0.5, alternative="two-sided").pvalue if (b + c) else 1.0
        check(f"scipy-xcheck/{b}-{c}", approx(mine, lib, tol=1e-9), f"mine={mine} lib={lib}")
    print("NOTE: cross-checked McNemar exact against scipy.stats.binomtest")
except ImportError:
    print("NOTE: scipy not installed; skipping library cross-check (pure-Python math still tested above)")

if failures:
    print(f"FAILED ({len(failures)}):")
    for f in failures:
        print("  -", f)
    raise SystemExit(1)
print("OK — paired-delta math: McNemar exact, stderr, aggregation, drops, pydantic model")
