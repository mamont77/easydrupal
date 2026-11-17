#!/usr/bin/env python3
"""Run the control task on every skill, then build and upload a public results set (#3588981).

Three stages, each its own command so a maintainer can stop and read between
them:

    # 1. run: every skill with and without its skill, N repeats, one model
    python evals/inspect/publish_results.py run --model abp_cli/claude/sonnet \\
        --label claude-sonnet --out release --epochs 3

    # 2. build: scrubbed logs, a results table, EEE records, a card template
    python evals/inspect/publish_results.py build --out release \\
        --run claude-sonnet=release/runs/claude-sonnet

    # 3. upload: dry run unless --yes; a new dataset is created private
    python evals/inspect/publish_results.py upload --out release \\
        --repo-id <owner>/<name> --yes

``build`` writes ``<out>/logs/<label>/`` (the Inspect logs with local paths
replaced), ``<out>/data/<split>.jsonl`` (one row per graded answer, which the
Hugging Face dataset viewer shows as a table) and
``<out>/eee/<skill>/<developer>/<model>/<uuid>.json`` (Every Eval Ever 0.2.2,
one result per arm, via to_eee.py). It refuses to finish when it finds a home
path or a credential-looking string in what it wrote. ``upload`` runs the same
check and refuses a card that still holds the template's TODO lines.

Needs the behavioral and EEE extras (requirements-behavioral.txt,
requirements-eee.txt); the upload uses huggingface_hub, which the EEE extra
brings, and a token with write access (``hf auth login``).
"""
from __future__ import annotations

import argparse
import json
import re
import shutil
import sys
import uuid
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
sys.path.insert(0, str(HERE))

# What must never reach a public dataset. Home and repo paths are replaced in
# build; these patterns are only detected, and a hit stops the build.
SECRET_PATTERNS = {
    "api key": re.compile(r"sk-(?:ant-)?[A-Za-z0-9_-]{20,}"),
    "bearer token": re.compile(r"Bearer [A-Za-z0-9._-]{20,}"),
    "oauth token": re.compile(r"\"(?:refresh|access)_token\"\s*:\s*\"[^\"]{10,}"),
    "key assignment": re.compile(r"[A-Z_]*API_KEY\s*[=:]\s*['\"]?[A-Za-z0-9_-]{12,}"),
}
TODO_MARK = "TODO(publish)"


def _skills() -> list[str]:
    return sorted(str(p.parent.relative_to(REPO / "evals"))
                  for p in (REPO / "evals").rglob("dataset.yaml")
                  if "canvas-migration" not in p.parts)


def cmd_run(args) -> int:
    import provider  # noqa: F401  registers @modelapi("abp_cli")
    from inspect_ai import eval as inspect_eval
    from tasks import control

    skills = args.skill or _skills()
    logs = inspect_eval([control(skill=s) for s in skills], model=args.model,
                        epochs=args.epochs, max_connections=args.max_connections,
                        log_dir=str(Path(args.out) / "runs" / args.label),
                        display="plain", fail_on_error=False)
    bad = [log.eval.task for log in logs if log.status != "success"]
    print(f"{len(logs) - len(bad)}/{len(logs)} skills ran; logs in {Path(args.out) / 'runs' / args.label}")
    if bad:
        print("not successful (retry with inspect_ai.eval_retry):", ", ".join(bad))
    return 1 if bad else 0


def _replacements(extra: list[str]) -> list[tuple[str, str]]:
    """Local paths to replace, longest first so a repo path wins over $HOME."""
    pairs = [(str(REPO), "<repo>"), (str(Path.home()), "/home/user")]
    for item in extra:
        old, _, new = item.partition("=")
        pairs.append((old, new or "<local>"))
    return sorted(pairs, key=lambda p: -len(p[0]))


def _scrub(text: str, pairs: list[tuple[str, str]]) -> str:
    for old, new in pairs:
        text = text.replace(old, new)
    return text


def _model_info(items: list[str]) -> dict[str, dict]:
    """--model-info label=developer,id,inference platform -> overrides per run label."""
    out = {}
    for item in items:
        label, _, rest = item.partition("=")
        developer, model_id, platform = (rest.split(",", 2) + ["", "", ""])[:3]
        out[label] = {"developer": developer, "id": model_id, "inference_platform": platform}
    return out


def _row(label: str, skill: str, sample) -> dict:
    from inspect_ai.scorer import CORRECT

    md = sample.metadata or {}
    score = next(iter(sample.scores.values())) if sample.scores else None
    return {
        "run": label,
        "skill": skill,
        "question_id": md.get("case_id"),
        "with_skill": md.get("arm") == "on",
        "epoch": sample.epoch,
        "question": sample.input if isinstance(sample.input, str) else str(sample.input),
        "criteria": sample.target if isinstance(sample.target, str) else str(sample.target),
        "answer": sample.output.completion if sample.output else "",
        "passed": bool(score and score.value == CORRECT),
        "grader_detail": (score.explanation if score else "") or "",
        "checks": json.dumps(md.get("checks", []), ensure_ascii=False),
        "error": sample.error.message[:300] if sample.error else "",
    }


def find_leaks(out: Path) -> list[str]:
    """Home paths and credential-looking strings in everything under ``out``."""
    from inspect_ai.log import read_eval_log

    home = str(Path.home())
    found = []
    for f in sorted(p for p in out.rglob("*") if p.is_file()):
        if f.suffix == ".eval":
            text = read_eval_log(str(f), resolve_attachments=True).model_dump_json()
        elif f.suffix in (".json", ".jsonl", ".md", ".html", ".txt", ".yaml", ".yml"):
            text = f.read_text(errors="replace")
        else:
            continue
        rel = f.relative_to(out)
        if home in text:
            found.append(f"{rel}: home path {home}")
        for name, rx in SECRET_PATTERNS.items():
            if rx.search(text):
                found.append(f"{rel}: {name}")
    return found


CARD = f"""---
pretty_name: {TODO_MARK} name
license: other
license_name: gpl-2.0-or-later
license_link: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
tags:
- evaluation
- inspect-ai
- agent-skills
- drupal
configs:
- config_name: default
  data_files:
  - split: {{split}}
    path: data/{{split}}.jsonl
---

# {TODO_MARK} title

{TODO_MARK} What was run (models, routes, date), the pooled result
(`python evals/inspect/paired_delta.py` per log), and the limits. The logs
contain the skills' text from drupal/ai_best_practices, so keep the licence.

| Path | What |
|---|---|
| `data/{{split}}.jsonl` | One row per graded answer (shown as a table) |
| `logs/<run>/` | Inspect logs; open with `inspect view --log-dir logs/<run>` |
| `eee/<skill>/<developer>/<model>/` | Every Eval Ever 0.2.2 records, one result per arm |
"""


def cmd_build(args) -> int:
    from inspect_ai.log import EvalLog, list_eval_logs, read_eval_log, write_eval_log
    import to_eee

    out = Path(args.out)
    pairs = _replacements(args.scrub)
    overrides = _model_info(args.model_info)
    rows = []
    for item in args.run:
        label, _, src = item.partition("=")
        dest = out / "logs" / label
        if dest.exists():
            shutil.rmtree(dest)
        dest.mkdir(parents=True)
        for info in list_eval_logs(src):
            log = read_eval_log(info, resolve_attachments=True)
            clean = EvalLog.model_validate_json(_scrub(log.model_dump_json(), pairs))
            path = dest / Path(info.name).name
            write_eval_log(clean, str(path))
            skill = clean.eval.task_args.get("skill", "")
            rows += [_row(label, skill, s) for s in clean.samples or []]
            env_path = (out / "eee" / skill.replace("/", "__") / "{dev}" / label / f"{uuid.uuid4()}.json")
            tmp = to_eee.to_eee(path, out / "eee" / "_tmp.json", model_overrides=overrides.get(label))
            developer = json.loads(tmp.read_text())["model_info"]["developer"]
            final = Path(str(env_path).replace("{dev}", developer))
            final.parent.mkdir(parents=True, exist_ok=True)
            shutil.move(str(tmp), final)
            sidecar = tmp.with_name("_tmp_samples.jsonl")
            if sidecar.exists():
                samples = final.with_name(f"{final.stem}_samples.jsonl")
                shutil.move(str(sidecar), samples)
                env = json.loads(final.read_text())
                env["detailed_evaluation_results"]["file_path"] = samples.name
                final.write_text(json.dumps(env, indent=2))
    (out / "data").mkdir(parents=True, exist_ok=True)
    rows.sort(key=lambda r: (r["run"], r["skill"], r["question_id"] or "", not r["with_skill"], r["epoch"]))
    with open(out / "data" / f"{args.split}.jsonl", "w", encoding="utf-8") as f:
        for r in rows:
            f.write(json.dumps(r, ensure_ascii=False) + "\n")
    card = out / "README.md"
    if not card.exists():
        card.write_text(CARD.replace("{split}", args.split), encoding="utf-8")
    print(f"{len(rows)} answers from {len(args.run)} run(s) -> {out}")
    leaks = find_leaks(out)
    for leak in leaks:
        print("LEAK:", leak)
    if leaks:
        print("refusing: remove or --scrub these before upload")
        return 1
    if TODO_MARK in card.read_text():
        print(f"next: edit {card} (replace the {TODO_MARK} lines), then upload")
    return 0


def cmd_upload(args, api=None) -> int:
    out = Path(args.out)
    card = out / "README.md"
    if not card.exists() or TODO_MARK in card.read_text():
        print(f"refusing: {card} is missing or still has {TODO_MARK} lines")
        return 1
    leaks = find_leaks(out)
    if leaks:
        print("refusing:", *leaks, sep="\n  ")
        return 1
    files = sorted(p for p in out.rglob("*") if p.is_file())
    total = sum(p.stat().st_size for p in files)
    print(f"{len(files)} files, {total / 1e6:.1f} MB -> datasets/{args.repo_id}")
    if not args.yes:
        print("dry run: nothing uploaded (add --yes)")
        return 0
    if api is None:
        from huggingface_hub import HfApi
        api = HfApi()
    # A new dataset starts private: publishing is a separate, visible decision.
    api.create_repo(args.repo_id, repo_type="dataset", private=True, exist_ok=True)
    commit = api.upload_folder(repo_id=args.repo_id, repo_type="dataset", folder_path=str(out),
                               commit_message=args.message)
    print(commit.commit_url)
    return 0


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    sub = p.add_subparsers(dest="stage", required=True)

    r = sub.add_parser("run", help="run the control task on every skill for one model")
    r.add_argument("--model", required=True, help="Inspect model, e.g. abp_cli/claude/sonnet")
    r.add_argument("--label", required=True, help="run name, used as the folder name")
    r.add_argument("--out", required=True)
    r.add_argument("--skill", action="append", help="limit to these skills (repeatable)")
    r.add_argument("--epochs", type=int, default=3)
    r.add_argument("--max-connections", type=int, default=4)

    b = sub.add_parser("build", help="scrubbed logs, results table and EEE records")
    b.add_argument("--out", required=True)
    b.add_argument("--run", action="append", required=True, metavar="LABEL=LOG_DIR")
    b.add_argument("--split", default="final", help="table split name (data/<split>.jsonl)")
    b.add_argument("--scrub", action="append", default=[], metavar="OLD=NEW",
                   help="extra local string to replace (home and repo paths always are)")
    b.add_argument("--model-info", action="append", default=[],
                   metavar="LABEL=DEVELOPER,ID,PLATFORM",
                   help="EEE model_info for a run whose model spec cannot say (a gateway)")

    u = sub.add_parser("upload", help="upload the built folder as a Hugging Face dataset")
    u.add_argument("--out", required=True)
    u.add_argument("--repo-id", required=True, help="<owner>/<name> on Hugging Face")
    u.add_argument("--message", default="Add eval results")
    u.add_argument("--yes", action="store_true", help="really upload (default: dry run)")

    args = p.parse_args(argv)
    return {"run": cmd_run, "build": cmd_build, "upload": cmd_upload}[args.stage](args)


if __name__ == "__main__":
    sys.exit(main())
