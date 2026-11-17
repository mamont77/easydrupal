#!/usr/bin/env python3
"""Eval runner for ai_best_practices skills.

Two modes:
  python3 evals/run-evals.py --static                          # structural checks only
  python3 evals/run-evals.py --behavioral --skill SKILL_NAME   # live AI checks

CI: --static runs automatically on MRs that touch skills/ or evals/.
See .gitlab-ci.yml (evals-static job).

Static mode runs against the skill file on disk. No API calls. Suitable for CI.
Behavioral mode sends prompts to a provider CLI and checks the response.
The provider defaults to claude but can be switched with --provider:

  python3 evals/run-evals.py --behavioral --skill SKILL_NAME --provider codex
  python3 evals/run-evals.py --behavioral --skill SKILL_NAME --provider gemini --model gemini-2.5-flash

Trace logging (behavioral mode):
  python3 evals/run-evals.py --behavioral --skill SKILL_NAME --output-dir ./traces

  Saves one JSON file per case with the full prompt, raw response, assertions,
  and pass/fail result. Reviewable without re-running.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
from pathlib import Path

from providers import PROVIDER_DEFAULTS, PROVIDERS
# Shared scoring (single source of truth, also used by compare.py). Importing it
# does NOT pull in PyYAML — scoring imports yaml lazily inside load_cases — so the
# pip-free --static path is unaffected.
import scoring

REPO_ROOT = Path(__file__).resolve().parent.parent


def load_static_checks(skill_name: str) -> dict | None:
    path = REPO_ROOT / "evals" / skill_name / "static-checks.json"
    if not path.exists():
        return None
    with open(path) as f:
        return json.load(f)


def read_skill(skill_file: str) -> str:
    path = REPO_ROOT / skill_file
    if not path.exists():
        return ""
    with open(path) as f:
        return f.read()


def parse_frontmatter(content: str) -> dict[str, str] | None:
    """Extract simple top-level YAML frontmatter fields.

    This intentionally handles the Agent Skills frontmatter shape used here
    without introducing a YAML dependency into static CI checks.
    """
    fm_match = re.search(r"^---\n(.*?)\n---", content, re.DOTALL)
    if not fm_match:
        return None

    fields = {}
    for line in fm_match.group(1).splitlines():
        if line.startswith((" ", "\t")):
            continue
        match = re.match(r"^([A-Za-z0-9_-]+):\s*(.*)$", line)
        if match:
            key, value = match.groups()
            fields[key] = value.strip().strip("\"'")
    return fields


def check_agent_skill_frontmatter(
    skill_file: str,
    content: str,
    description_min_chars: int = 40,
) -> tuple[bool, str]:
    """Validate the core Agent Skills frontmatter fields."""
    fields = parse_frontmatter(content)
    if fields is None:
        return False, "No YAML frontmatter found"

    errors = []
    name = fields.get("name", "")
    description = fields.get("description", "")

    if not name:
        errors.append("missing name")
    else:
        if len(name) > 64:
            errors.append("name exceeds 64 characters")
        if not re.match(r"^[a-z0-9]+(?:-[a-z0-9]+)*$", name):
            errors.append("name must use lowercase letters, numbers, and hyphens")
        if skill_file:
            parent_name = Path(skill_file).parent.name
            if name != parent_name:
                errors.append(f"name '{name}' does not match parent directory '{parent_name}'")

    if not description:
        errors.append("missing description")
    else:
        if len(description) > 1024:
            errors.append("description exceeds 1024 characters")
        if len(description) < description_min_chars:
            errors.append(f"description shorter than {description_min_chars} characters")

    if errors:
        return False, "; ".join(errors)

    return True, f"name '{name}' matches parent directory; description is {len(description)} characters"


# ---------------------------------------------------------------------------
# Static assertions
# ---------------------------------------------------------------------------

def run_static(static: dict) -> list[tuple[str, str, bool, str]]:
    results = []
    skill_file = static.get("skill_file", "")
    content = read_skill(skill_file) if skill_file else ""

    for a in static.get("assertions", []):
        aid = a["id"]
        desc = a["description"]
        check = a["check"]

        # Per-check file override: multi-file skills (progressive disclosure) can
        # point a content check at a bundled reference file instead of SKILL.md.
        # The path is relative to the repo root, e.g. "skills/foo/references/bar.md".
        target_content = read_skill(a["file"]) if a.get("file") else content

        if check == "file_exists":
            target = REPO_ROOT / a["path"]
            passed = target.exists()
            detail = f"{'exists' if passed else 'missing'}: {a['path']}"

        elif check == "frontmatter_fields":
            fields = parse_frontmatter(content)
            if fields is None:
                passed = False
                detail = "No YAML frontmatter found"
            else:
                missing = [f for f in a["fields"] if f not in fields]
                passed = len(missing) == 0
                detail = f"Missing: {missing}" if missing else "All fields present"

        elif check == "agent_skill_frontmatter":
            passed, detail = check_agent_skill_frontmatter(
                skill_file,
                content,
                description_min_chars=a.get("description_min_chars", 40),
            )

        elif check == "contains":
            where = f" in {a['file']}" if a.get("file") else ""
            passed = a["text"] in target_content
            detail = (f"Found '{a['text']}'{where}" if passed
                      else f"'{a['text']}' not found{where}")

        elif check == "order":
            first_pos = target_content.find(a["first"])
            second_pos = target_content.find(a["second"])
            if first_pos == -1 or second_pos == -1:
                passed = False
                detail = f"'{a['first']}' at {first_pos}, '{a['second']}' at {second_pos}"
            else:
                passed = first_pos < second_pos
                detail = (
                    f"'{a['first']}' at line {target_content[:first_pos].count(chr(10))+1}, "
                    f"'{a['second']}' at line {target_content[:second_pos].count(chr(10))+1}"
                )

        elif check == "max_lines":
            lines = target_content.count("\n") + (1 if target_content and not target_content.endswith("\n") else 0)
            passed = lines <= a["limit"]
            where = f" ({a['file']})" if a.get("file") else ""
            detail = f"{lines} lines (limit: {a['limit']}){where}"

        else:
            passed = False
            detail = f"Unknown check type: {check}"

        results.append((aid, desc, passed, detail))

    return results


# ---------------------------------------------------------------------------
# Behavioral assertions
# ---------------------------------------------------------------------------

def run_behavioral(
    skill_name: str,
    cases: list[dict],
    output_dir: str | None = None,
    provider: str = "claude",
    model: str | None = None,
    cwd: str | None = None,
) -> list[tuple[str, str, bool, str]]:
    results = []
    skill_file = scoring.skill_file_for(skill_name)
    skill_content = read_skill(skill_file)
    if not skill_content:
        return [("--", "Skill file missing", False, f"{skill_file} not found or empty")]

    # Progressive disclosure: a real agent reads bundled references/ files on
    # demand, so include them in the behavioral context as if it had.
    references_dir = (REPO_ROOT / skill_file).parent / "references"
    if references_dir.is_dir():
        for ref in sorted(references_dir.glob("*.md")):
            rel = ref.relative_to(REPO_ROOT)
            skill_content += f"\n\n---\n\n# Reference: {rel}\n\n{ref.read_text()}"

    if output_dir:
        os.makedirs(output_dir, exist_ok=True)

    runner = PROVIDERS[provider]
    # For claude, leave model unresolved so the user's CLI default applies when --model is omitted.
    resolved_model = model if provider == "claude" else (model or PROVIDER_DEFAULTS[provider])

    # Providers default to a fresh isolated tempdir per subprocess when cwd is
    # None, so the agent under eval cannot mutate the repo (see providers.py).

    for case in cases:
        cid = case["id"]
        prompt = case["prompt"]
        teaching = case.get("criteria", "")

        full_prompt = f"{skill_content}\n\n---\n\n{prompt}"

        result = runner(full_prompt, resolved_model, cwd=cwd)
        response = (result.get("response") or "").strip()
        exit_code = result.get("exit_code", -1)

        if exit_code == -1 and not response:
            results.append((cid, teaching, False, f"{provider} CLI not found or timed out"))
            continue

        if exit_code != 0:
            results.append((cid, teaching, False, f"{provider} exited {exit_code}"))
            continue

        if not response:
            results.append((cid, teaching, False, "Empty response"))
            continue

        passed, detail, per_check = scoring.grade(
            response, case["checks"], case.get("combine", "all_pass"),
        )

        results.append((cid, teaching, passed, detail))

        if output_dir:
            trace = {
                "id": cid,
                "teaching": teaching,
                "provider": provider,
                "model": resolved_model,
                "prompt": prompt,
                "response": response,
                "checks": case["checks"],
                "check_results": [
                    {"kind": k, "passed": p, "detail": d} for k, p, d in per_check
                ],
                "passed": passed,
                "detail": detail,
                "elapsed": result.get("elapsed", 0.0),
                "input_tokens": result.get("input_tokens", 0),
                "output_tokens": result.get("output_tokens", 0),
                "cost_usd": result.get("cost_usd", 0.0),
                "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            }
            trace_path = os.path.join(output_dir, f"{cid}.json")
            with open(trace_path, "w") as f:
                json.dump(trace, f, indent=2)

    return results


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def print_results(results: list[tuple[str, str, bool, str]]) -> int:
    failures = 0
    for aid, desc, passed, detail in results:
        status = "PASS" if passed else "FAIL"
        if not passed:
            failures += 1
        print(f"  [{status}] {aid}: {desc}")
        print(f"         {detail}")
    return failures


def main():
    parser = argparse.ArgumentParser(description="Eval runner for ai_best_practices skills")
    parser.add_argument("--static", action="store_true", help="Run static structural assertions")
    parser.add_argument("--behavioral", action="store_true", help="Run behavioral test cases")
    parser.add_argument("--skill", default=None, help="Skill name to evaluate (default: all)")
    parser.add_argument("--provider", choices=list(PROVIDERS.keys()), default="claude",
                        help="Provider CLI to use for behavioral cases (default: claude)")
    parser.add_argument("--model", default=None,
                        help="Model for behavioral cases (default depends on provider)")
    parser.add_argument("--cwd", default=None,
                        help="Working directory for the provider CLI (default: repo root for claude, tempdir for others)")
    parser.add_argument("--output-dir", default=None, help="Save behavioral traces as JSON to this directory")
    parser.add_argument("--root", default=None,
                        help="Repository whose skills/ and evals/ to evaluate (default: this package)")
    args = parser.parse_args()

    if not args.static and not args.behavioral:
        args.static = True

    if args.root:
        global REPO_ROOT
        REPO_ROOT = Path(args.root).resolve()
        scoring.set_repo_root(REPO_ROOT)

    evals_dir = REPO_ROOT / "evals"
    if not evals_dir.is_dir():
        parser.error(f"no eval suites: {evals_dir} does not exist")
    if args.skill:
        skills = [args.skill]
    else:
        # Discover suites at any depth (rglob), so nested suites like
        # drupal-accessibility/drupal-a11y-dom are covered too, not just
        # top-level dirs. Matches test_scoring.py's recursive discovery.
        # Skill names are evals-relative paths; load_static_checks() and
        # scoring.load_cases() both accept a subpath as the skill name.
        suite_dirs = {
            p.parent for p in evals_dir.rglob("dataset.yaml")
        } | {
            p.parent for p in evals_dir.rglob("static-checks.json")
        }
        skills = [str(d.relative_to(evals_dir)) for d in suite_dirs]
        if not skills:
            parser.error(f"no eval suites found under {evals_dir}")

    total_failures = 0
    # (skill_name, assertion_id, description, detail) for every failed check,
    # so we can reprint them as one summary at the end. CI logs scroll to the
    # bottom, so a buried [FAIL] among hundreds of PASS lines is invisible
    # without this.
    failed_checks: list[tuple[str, str, str, str]] = []

    for skill_name in sorted(skills):
        print(f"\n=== {skill_name} ===\n")

        if args.static:
            static = load_static_checks(skill_name)
            if static:
                print("Static assertions:")
                results = run_static(static)
                total_failures += print_results(results)
                failed_checks += [(skill_name, aid, desc, detail)
                                  for aid, desc, p, detail in results if not p]
                passed = sum(1 for _, _, p, _ in results if p)
                print(f"\n  Score: {passed}/{len(results)}")
            else:
                print("No static-checks.json found, skipping static assertions.")

        if args.behavioral:
            if not scoring.has_dataset(skill_name):
                print("No dataset.yaml found, skipping behavioral assertions.")
                continue
            cases = scoring.load_cases(skill_name)
            trace_dir = None
            if args.output_dir:
                trace_dir = os.path.join(args.output_dir, skill_name)
            if args.provider == "claude" and not args.model:
                model_label = "claude CLI default"
            else:
                model_label = args.model or PROVIDER_DEFAULTS[args.provider]
            print(f"\nBehavioral cases (provider: {args.provider}, model: {model_label}):")
            results = run_behavioral(
                skill_name,
                cases,
                output_dir=trace_dir,
                provider=args.provider,
                model=args.model,
                cwd=args.cwd,
            )
            total_failures += print_results(results)
            failed_checks += [(skill_name, aid, desc, detail)
                              for aid, desc, p, detail in results if not p]
            passed = sum(1 for _, _, p, _ in results if p)
            print(f"\n  Score: {passed}/{len(results)}")
            if trace_dir:
                print(f"  Traces saved to: {trace_dir}")

    print()
    if failed_checks:
        print(f"=== {len(failed_checks)} FAILED CHECK(S) ===\n")
        for skill_name, aid, desc, detail in failed_checks:
            print(f"  [FAIL] {skill_name}: {aid}: {desc}")
            print(f"         {detail}")
        print()
    sys.exit(1 if total_failures > 0 else 0)


if __name__ == "__main__":
    main()
