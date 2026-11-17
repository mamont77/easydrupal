#!/usr/bin/env python3
"""Launcher for the abp Inspect runner (#3588916).

WHY THIS EXISTS (a no-install alternative to `inspect eval`):
The ``inspect eval FILE@task --model abp_cli/...`` CLI resolves ``--model``
*before* it imports the task file, so the ``@modelapi("abp_cli")`` registration
in ``provider.py`` has not run yet and resolution fails with "Model API abp_cli
... not recognized". The native CLI works only when the provider is installed as
an ``inspect_ai`` entry point (``evals/inspect/pyproject.toml``, editable via
``requirements-behavioral.txt``); ``ensure_entry_points()`` then imports it in
time.

This launcher is the path that needs NO editable install: it imports
``provider`` first (registering the provider in Inspect's global registry) and
only then calls Inspect's ``eval()`` Python API, where ``get_model("abp_cli/
...")`` resolves against the already-registered provider. The resulting
``EvalLog`` is identical to a CLI run and works with ``inspect view``.

Run (needs the behavioral extra + a live CLI; costs subscription tokens):

    python evals/inspect/run.py --skill drupal-gitlab --model claude
    python evals/inspect/run.py --skill drupal-gitlab --model claude/sonnet

``--model`` is the abp provider spec WITHOUT the ``abp_cli/`` prefix (added here).
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import provider  # noqa: F401,E402  importing registers @modelapi("abp_cli")
from inspect_ai import eval as inspect_eval  # noqa: E402
from inspect_ai.scorer import CORRECT  # noqa: E402
from tasks import abp, control  # noqa: E402

import paired_delta  # noqa: E402  (loose-script sibling)


def main() -> int:
    ap = argparse.ArgumentParser(description="Run the abp Inspect task via the abp_cli provider")
    ap.add_argument("--skill", default="drupal-gitlab", help="skill name (dataset under evals/<skill>/)")
    ap.add_argument("--model", default="claude",
                    help="abp provider spec without the abp_cli/ prefix, e.g. claude or claude/sonnet")
    ap.add_argument("--log-dir", default=None, help="EvalLog output dir (default: Inspect's ./logs)")
    ap.add_argument("--epochs", type=int, default=1, help="repeat each sample N times (nondeterminism honesty pass)")
    ap.add_argument("--control", action="store_true",
                    help="run the control arm (each case with AND without the skill) and report the paired delta")
    args = ap.parse_args()

    if args.control and args.epochs != 1:
        # The control arm is a PAIRED test: paired_delta pairs exactly one "on"
        # and one "off" outcome per case. With epochs > 1 each case has N of each,
        # which the pairing drops as non-unique -> n_pairs=0 (a silently empty
        # report). Multiplying pairs across epochs is not a fix either: the epoch
        # copies of a case are correlated, so they would violate McNemar's
        # independence assumption and inflate significance. Reject the combo;
        # aggregating epochs into one per-arm verdict first is possible but
        # unimplemented.
        ap.error("--control cannot be combined with --epochs > 1 (the paired "
                 "delta needs one on/off pair per case); run the control arm "
                 "with --epochs 1.")

    model = f"abp_cli/{args.model}"
    kwargs: dict[str, object] = {"model": model, "epochs": args.epochs}
    if args.log_dir:
        kwargs["log_dir"] = args.log_dir

    task = control(skill=args.skill) if args.control else abp(skill=args.skill)
    logs = inspect_eval(task, **kwargs)

    # Print a compact per-sample pass/fail table for parity comparison. NOTE:
    # with --epochs > 1 each sample appears once PER EPOCH (id, epoch), so the
    # denominator below is sample*epoch rows, not unique cases; the reduced
    # headline metric is Inspect's own accuracy/stderr printed above.
    log = logs[0] if isinstance(logs, list) else logs
    if log.samples:
        epochs = getattr(log.eval.config, "epochs", None) or 1
        unit = "sample-runs" if epochs > 1 else "samples"
        print(f"\n=== {log.eval.task} via {model} ({len(log.samples)} {unit}) ===")
        passed = errored = 0
        for s in sorted(log.samples, key=lambda x: (str(x.id), getattr(x, "epoch", 0))):
            if s.error is not None:
                verdict, ok = "ERROR", False
                errored += 1
            else:
                score = next(iter(s.scores.values())) if s.scores else None
                verdict = score.value if score else "NOSCORE"
                ok = verdict == CORRECT
            passed += int(ok)
            label = f"{str(s.id)}" + (f"/e{s.epoch}" if epochs > 1 else "")
            print(f"  {label:8} {'PASS' if ok else 'FAIL':4} ({verdict})")
        tail = f", {errored} errored" if errored else ""
        print(f"  ---\n  {passed}/{len(log.samples)} passed{tail}")

    # Control arm: the per-sample table above is just the raw arms; the headline
    # is the PAIRED delta + significance across them.
    if args.control and log.samples:
        result = paired_delta.paired_delta(paired_delta.rows_from_eval_log(log))
        print()
        print(paired_delta.format_report(result, title=f"=== control-arm paired delta: {log.eval.task} ==="))

    if log.location:
        print(f"\nEvalLog: {log.location}")
        print("View: inspect view")
    return 0 if log.status == "success" else 1


if __name__ == "__main__":
    raise SystemExit(main())
