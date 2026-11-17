#!/usr/bin/env python3
"""Run the agentic pilot for one CLI and model.

    python evals/inspect/agentic/run_agentic.py --cli claude --model sonnet --epochs 3
    python evals/inspect/agentic/run_agentic.py --cli codex --model gpt-6-luna --case schema

No model API is called by Inspect itself (the CLI does the work), so Inspect's
model is the mock provider; the CLI and its model are recorded per sample.
"""

import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from inspect_ai import eval as inspect_eval  # noqa: E402
import tasks_agentic  # noqa: E402

ap = argparse.ArgumentParser()
ap.add_argument("--cli", choices=sorted(tasks_agentic.LOGIN), required=True)
ap.add_argument("--model", required=True)
ap.add_argument("--epochs", type=int, default=1)
ap.add_argument("--case", action="append", help="limit to these case ids (repeatable)")
ap.add_argument("--max-sandboxes", type=int, default=3)
ap.add_argument("--log-dir", default="logs")
args = ap.parse_args()

t = tasks_agentic.config_agentic(cli=args.cli, model=args.model)
if args.case:
    t.dataset = t.dataset.filter(lambda s: s.metadata["case_id"] in args.case)
logs = inspect_eval(t, model="mockllm/model", epochs=args.epochs, log_dir=args.log_dir,
                    max_sandboxes=args.max_sandboxes, display="plain", fail_on_error=False)
for log in logs:
    print(log.eval.task, log.status, log.location)
