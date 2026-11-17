#!/usr/bin/env python3
"""End-to-end test for publish_results.py build + upload, token-free (#3588981).

Runs a real control eval with a fake model into a temp folder, plants a local
home path in one answer, then checks that build replaces it, writes one table
row per answer and one EEE record per log (two arm results each), refuses a
credential-looking string, and that upload refuses the template card, dry-runs
without --yes and creates a private dataset with it (against a fake API).

Requires inspect_ai + every-eval-ever[inspect]; SKIPs cleanly without them.
"""
from __future__ import annotations

import json
import sys
import tempfile
from argparse import Namespace
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

try:
    from inspect_ai import eval as inspect_eval
    from inspect_ai.log import read_eval_log, write_eval_log
    import every_eval_ever  # noqa: F401  ensure the extra is present
except ImportError as e:
    print(f"SKIP test_publish_results: behavioral extra not installed ({e})")
    raise SystemExit(0)

import provider  # noqa: E402,F401  registers @modelapi("abp_cli")
import providers  # noqa: E402
from tasks import control  # noqa: E402
import publish_results as pr  # noqa: E402

failures: list[str] = []


def check(name: str, cond: bool) -> None:
    if not cond:
        failures.append(name)


def fake_runner(prompt, model=None, cwd=None):
    text = ("Not all projects have migrated to work items; check the legacy queue."
            if len(prompt) > 2000 else "I don't know.")
    return {"response": text, "elapsed": 0.5, "exit_code": 0, "input_tokens": 10,
            "cache_creation_tokens": 0, "cache_read_tokens": 0, "output_tokens": 8,
            "cost_usd": 0.0}


providers.PROVIDERS["claude"] = fake_runner
home = str(Path.home())

with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    inspect_eval(control(skill="drupal-gitlab"), model="abp_cli/claude", epochs=2,
                 log_dir=str(tmp / "runs" / "fake"), log_format="eval", display="none")
    log_file = next((tmp / "runs" / "fake").glob("*.eval"))
    log = read_eval_log(str(log_file))
    log.samples[0].output.completion = f"See {home}/projects/notes.md"
    write_eval_log(log, str(log_file))
    n_samples = len(log.samples)

    out = tmp / "release"
    rc = pr.main(["build", "--out", str(out), "--run", f"fake={tmp / 'runs' / 'fake'}"])
    check("build/ok", rc == 0)
    rows = [json.loads(line) for line in (out / "data" / "final.jsonl").read_text().splitlines()]
    check("build/one-row-per-answer", len(rows) == n_samples)
    check("build/arms-in-table", {r["with_skill"] for r in rows} == {True, False})
    check("build/home-path-replaced", not any(home in r["answer"] for r in rows)
          and any("/home/user/projects/notes.md" in r["answer"] for r in rows))
    check("build/logs-scrubbed", home not in read_eval_log(
        str(next((out / "logs" / "fake").glob("*.eval")))).model_dump_json())
    records = [p for p in (out / "eee").rglob("*.json")]
    check("build/one-eee-record", len(records) == 1)
    env = json.loads(records[0].read_text())
    check("build/eee-two-arms", len(env["evaluation_results"]) == 2)
    sidecar = records[0].with_name(f"{records[0].stem}_samples.jsonl")
    check("build/eee-sidecar", sidecar.exists()
          and env["detailed_evaluation_results"]["file_path"] == sidecar.name)
    check("build/eee-layout", records[0].relative_to(out / "eee").parts[:3]
          == ("drupal-gitlab", "anthropic", "fake"))
    check("build/card-template", pr.TODO_MARK in (out / "README.md").read_text())

    # upload refuses the template card, then dry-runs, then uploads privately.
    calls = []

    class FakeApi:
        def create_repo(self, repo_id, **kw):
            calls.append(("create", repo_id, kw))

        def upload_folder(self, **kw):
            calls.append(("upload", kw["repo_id"], kw))
            return Namespace(commit_url="https://example.invalid/commit")

    up = Namespace(out=str(out), repo_id="someone/results", message="m", yes=True)
    check("upload/refuses-template-card", pr.cmd_upload(up, FakeApi()) == 1 and not calls)
    card = out / "README.md"
    card.write_text(card.read_text().replace(pr.TODO_MARK, "Done"))
    check("upload/dry-run", pr.cmd_upload(Namespace(**{**vars(up), "yes": False}), FakeApi()) == 0
          and not calls)
    check("upload/uploads", pr.cmd_upload(up, FakeApi()) == 0
          and calls[0][0] == "create" and calls[0][2].get("private") is True
          and calls[1][0] == "upload")

    # A credential-looking string stops both build and upload.
    (out / "data" / "leak.txt").write_text("OPENAI_API_KEY=abcdefghijklmnopqrstuvwx")
    check("leak/detected", any("key assignment" in f for f in pr.find_leaks(out)))
    calls.clear()
    check("leak/upload-refused", pr.cmd_upload(up, FakeApi()) == 1 and not calls)

if failures:
    print("FAIL:", ", ".join(failures))
    raise SystemExit(1)
print("OK — publish_results: scrubbed logs, table, EEE per arm, leak guard, private upload")
