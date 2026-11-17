#!/usr/bin/env python3
"""Canonical scoring + case loading for abp behavioral evals.

Single source of truth shared by run-evals.py (main runner) and compare.py
(control arm / A-B). Before this module each carried its own copy of the check
functions, so the headline eval and the baseline comparison could silently
grade "pass" by different rules. They now grade identically by construction.

Cases load from the Eval Commons dataset model:
    evals/<skill>/dataset.yaml   questions[]  (input, criteria, rubric_ref, ...)
    evals/<skill>/rubrics.yaml   rubrics[]    (per-case checks + scoring.combine)
See evals/_migrate_to_commons.py for the field mapping. The deterministic checks
mirror the Commons rubric check kinds (must_contain_any, must_not_contain,
php_lint, markdown_structure); grading is combine: all_pass.

`yaml` is imported lazily inside the loader so importing the check functions
(e.g. from the pip-free run-evals.py --static path) never requires PyYAML.
"""
from __future__ import annotations

import re
import shlex
import subprocess
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
EVALS_DIR = REPO_ROOT / "evals"


def set_repo_root(root: Path) -> None:
    """Point case loading at another repository's skills/ + evals/ (run-evals.py --root).

    Lets a package that ships its own skills and evals (for example one that
    installs this package with Composer) grade them with this runner.
    """
    global REPO_ROOT, EVALS_DIR
    REPO_ROOT = Path(root).resolve()
    EVALS_DIR = REPO_ROOT / "evals"


# --------------------------------------------------------------------------- #
# Deterministic checks — behaviour-preserving lift from run-evals.py/compare.py
# --------------------------------------------------------------------------- #

def _find(response: str, values: list[str], case_sensitive: bool) -> list[str]:
    if case_sensitive:
        return [t for t in values if t in response]
    rl = response.lower()
    return [t for t in values if t.lower() in rl]


def check_must_contain_any(response: str, values: list[str],
                           case_sensitive: bool = False) -> tuple[bool, str]:
    """Pass if the response contains at least one value (empty list = pass)."""
    if not values:
        return True, ""
    found = _find(response, values, case_sensitive)
    if found:
        return True, f"found: {found}"
    return False, f"missing all of: {values}"


def check_must_not_contain(response: str, values: list[str],
                           case_sensitive: bool = False) -> tuple[bool, str]:
    """Pass if the response contains none of the forbidden values."""
    if not values:
        return True, ""
    found = _find(response, values, case_sensitive)
    if found:
        return False, f"unwanted: {found}"
    return True, ""


def check_php_lint(response: str) -> tuple[bool, str]:
    """Extract ```php code blocks and run php -l on each, failing if unavailable."""
    blocks = re.findall(r"```php[^\n]*\n(.*?)```", response, re.DOTALL)
    if not blocks:
        return True, "no PHP blocks"
    errors = []
    for i, block in enumerate(blocks):
        code = block if block.strip().startswith("<?php") else "<?php\n" + block
        try:
            proc = subprocess.run(
                ["php", "-l"], input=code,
                capture_output=True, text=True, timeout=10,
            )
            if proc.returncode != 0:
                errors.append(f"block {i + 1}: {proc.stdout.strip()}")
        except FileNotFoundError:
            return False, "php not found — lint unavailable"
        except subprocess.TimeoutExpired:
            errors.append(f"block {i + 1}: lint timed out")
    if errors:
        return False, "; ".join(errors)
    return True, f"{len(blocks)} block(s) OK"


def check_markdown_structure(response: str, rules: dict) -> tuple[bool, str]:
    """Check markdown structural quality. Deterministic, no LLM calls.

    Supported rules: min_headings, max_heading_level, required_sections,
    forbidden_patterns, min_code_blocks, min_paragraphs, no_h1.
    """
    errors = []

    headings = []
    for line in response.splitlines():
        m = re.match(r"^(#{1,6})\s+(.+)", line)
        if m:
            headings.append((len(m.group(1)), m.group(2).strip()))

    min_h = rules.get("min_headings", 0)
    if min_h and len(headings) < min_h:
        errors.append(f"headings: {len(headings)} < {min_h} required")

    max_level = rules.get("max_heading_level", 6)
    deep = [f"{'#' * lvl} {txt}" for lvl, txt in headings if lvl > max_level]
    if deep:
        errors.append(f"heading too deep: {deep[0]}")

    if rules.get("no_h1", False):
        h1s = [txt for lvl, txt in headings if lvl == 1]
        if h1s:
            errors.append(f"H1 found (use ## or deeper): {h1s[0]}")

    heading_texts_lower = [txt.lower() for _, txt in headings]
    for section in rules.get("required_sections", []):
        if not any(section.lower() in ht for ht in heading_texts_lower):
            errors.append(f"missing section: {section}")

    for pat in rules.get("forbidden_patterns", []):
        if pat in response:
            errors.append(f"forbidden pattern found: {pat!r}")

    n_blocks = len(re.findall(r"```", response)) // 2
    min_cb = rules.get("min_code_blocks", 0)
    if min_cb and n_blocks < min_cb:
        errors.append(f"code blocks: {n_blocks} < {min_cb} required")

    min_para = rules.get("min_paragraphs", 0)
    if min_para:
        para_count = sum(
            1 for line in response.splitlines()
            if line.strip() and not line.startswith("#") and not line.startswith("```")
        )
        if para_count < min_para:
            errors.append(f"paragraphs: {para_count} < {min_para} required")

    if errors:
        return False, "; ".join(errors)

    parts = []
    if headings:
        parts.append(f"{len(headings)} headings")
    if n_blocks:
        parts.append(f"{n_blocks} code blocks")
    return True, ", ".join(parts) if parts else "OK"


# --------------------------------------------------------------------------- #
# Command checks (Eval Commons check_command contract, ai_eval MR !20)
# --------------------------------------------------------------------------- #
# Unlike the text checks above, a command check grades WORKSPACE STATE, not a
# response string: the only oracle is the exit code of a command run in the
# check's sandbox. This module owns the contract semantics (templating, expect,
# sandbox routing); the actual process execution is injected by the caller as
# sandbox_runners so the rules stay testable without ddev and identical across
# every runner that grades this way.

_METADATA_PLACEHOLDER = re.compile(r"\{\{\s*metadata\.([A-Za-z0-9_]+)\s*\}\}")


def _shell_quote_context(command: str, end: int) -> str | None:
    """Return the active shell quote at ``end`` (single, double, or None)."""
    quote = None
    escaped = False
    for char in command[:end]:
        if escaped:
            escaped = False
        elif char == "\\" and quote != "single":
            escaped = True
        elif char == "'" and quote != "double":
            quote = None if quote == "single" else "single"
        elif char == '"' and quote != "single":
            quote = None if quote == "double" else "double"
    return quote


def _escape_metadata_value(value: object, quote: str | None) -> str:
    """Escape one metadata value for its position in a bash command."""
    value = str(value)
    if quote == "single":
        return value.replace("'", "'\"'\"'")
    if quote == "double":
        return (value.replace("\\", "\\\\")
                .replace('"', '\\"')
                .replace("$", "\\$")
                .replace("`", "\\`"))
    return shlex.quote(value)


def resolve_command(command: str, metadata: dict) -> str:
    """Resolve {{ metadata.<key> }} placeholders from the question's metadata.

    A placeholder naming a key the question does not define raises KeyError:
    silently leaving it unresolved would hand the literal braces to the shell,
    which can accidentally pass (e.g. a grep that matches nothing exits 1, but
    a test -d on a brace string could be masked by || chains in the command).
    """
    def _sub(m: re.Match) -> str:
        key = m.group(1)
        if key not in metadata:
            raise KeyError(
                f"command references {{{{ metadata.{key} }}}} but the question "
                f"defines no metadata key {key!r}"
            )
        return _escape_metadata_value(
            metadata[key], _shell_quote_context(command, m.start()))
    return _METADATA_PLACEHOLDER.sub(_sub, command)


def run_command_check(check: dict, metadata: dict, sandbox_runners: dict,
                      timeout: int = 1800) -> tuple[bool, str, dict]:
    """Execute one kind:command check. Returns (passed, detail, record).

    sandbox_runners maps sandbox id -> callable(command, timeout) returning
    (exit_code, output). Per the contract, a sandbox id this executor has no
    runner for is REJECTED loudly, never silently rerouted; same for expect
    modes beyond the schema's exit_zero/fail_to_pass.
    """
    sandbox = check.get("sandbox", "ddev")
    if sandbox not in sandbox_runners:
        raise ValueError(
            f"unsupported sandbox id {sandbox!r} for check {check.get('id')!r} — "
            f"this executor runs {sorted(sandbox_runners)} (contract: reject, "
            "never mis-route)"
        )
    expect = check.get("expect", "exit_zero")
    if expect not in ("exit_zero", "fail_to_pass"):
        raise ValueError(
            f"unsupported expect {expect!r} for check {check.get('id')!r}"
        )
    setup = check.get("setup", "none")
    if setup not in ("none", "revert_fix", "apply_patch"):
        raise ValueError(
            f"unsupported setup {setup!r} for check {check.get('id')!r}"
        )
    command = resolve_command(check["command"], metadata)
    runner = sandbox_runners[sandbox]
    baseline_exit_code = None
    baseline_output = None
    baseline_executor_error = None
    if expect == "fail_to_pass":
        transition = getattr(runner, "run_fail_to_pass", None)
        if setup == "none" or transition is None:
            raise ValueError(
                f"check {check.get('id')!r} uses fail_to_pass/setup={setup!r}, "
                "but its sandbox runner provides no explicit state-transition executor"
            )
        before, after = transition(command, timeout, setup)
        baseline_exit_code, baseline_output = before
        baseline_executor_error = getattr(before, "executor_error", None)
        exit_code, output = after
        executor_error = getattr(after, "executor_error", None)
        passed = (baseline_exit_code not in (0, 126, 127)
                  and exit_code == 0)
    else:
        if setup != "none":
            raise ValueError(
                f"check {check.get('id')!r} declares setup={setup!r}, but setup "
                "is only meaningful with expect='fail_to_pass'"
            )
        result = runner(command, timeout)
        exit_code, output = result
        executor_error = getattr(result, "executor_error", None)
        passed = exit_code == 0
    detail = f"exit {exit_code} (expect {expect}, sandbox {sandbox})"
    record = {
        "id": check.get("id"),
        "sandbox": sandbox,
        "command": command,
        "expect": expect,
        "setup": setup,
        "baseline_exit_code": baseline_exit_code,
        "baseline_output": baseline_output,
        "baseline_executor_error": baseline_executor_error,
        "exit_code": exit_code,
        "output": output,
        "executor_error": executor_error,
        "passed": passed,
    }
    return passed, detail, record


def grade_command_checks(checks: list[dict], metadata, sandbox_runners: dict,
                         combine: str = "all_pass",
                         timeout: int = 1800) -> tuple[bool, str, list[dict]]:
    """Grade a rubric's command checks; the command-check twin of grade().

    metadata is a dict, or a callable(check) -> dict when the metadata view
    depends on the check (e.g. staged paths differ between ddev and host
    sandboxes). Combine mirrors grade(): all_pass / any_pass only.
    """
    if combine not in ("all_pass", "any_pass"):
        raise ValueError(
            f"unsupported scoring.combine: {combine!r} "
            "(command checks are binary; use all_pass/any_pass)"
        )
    records = []
    for chk in checks:
        if chk.get("kind") != "command":
            raise ValueError(
                f"grade_command_checks got kind {chk.get('kind')!r}; "
                "mixed-kind rubrics are not supported"
            )
        meta = metadata(chk) if callable(metadata) else metadata
        _passed, _detail, record = run_command_check(
            chk, meta, sandbox_runners, timeout=timeout)
        records.append(record)
    passes = [r["passed"] for r in records]
    passed = all(passes) if combine == "all_pass" else any(passes)
    detail = "; ".join(
        f"{r['id']}: exit {r['exit_code']}" for r in records) or "OK"
    return passed, detail, records


# --------------------------------------------------------------------------- #
# Rubric grading
# --------------------------------------------------------------------------- #

# Reserved keys on a check object that are not markdown_structure rule fields.
_CHECK_META_KEYS = {"kind", "id", "description", "metadata"}


def grade(response: str, checks: list[dict],
          combine: str = "all_pass") -> tuple[bool, str, list[tuple]]:
    """Grade a response against a rubric's checks. Returns (passed, detail, per_check).

    Supports the check kinds abp uses (must_contain_any, must_not_contain,
    php_lint, markdown_structure) and combine all_pass / any_pass. Other Commons
    combine modes (weighted_avg, min, max, median) need numeric scores, not the
    binary checks abp emits, so they raise rather than grade silently wrong.
    """
    per_check = []
    for chk in checks:
        kind = chk["kind"]
        if kind == "must_contain_any":
            p, d = check_must_contain_any(
                response, chk.get("values", []), chk.get("case_sensitive", False))
        elif kind == "must_not_contain":
            p, d = check_must_not_contain(
                response, chk.get("values", []), chk.get("case_sensitive", False))
        elif kind == "php_lint":
            p, d = check_php_lint(response)
        elif kind == "markdown_structure":
            base = {k: v for k, v in chk.items() if k not in _CHECK_META_KEYS}
            # metadata carries Layer 5 domain extensions (e.g. min_paragraphs) but
            # must only ADD keys, never override a first-class base-contract rule —
            # so base wins on any collision.
            rules = {**chk.get("metadata", {}), **base}
            p, d = check_markdown_structure(response, rules)
        else:
            raise ValueError(f"unsupported check kind: {kind!r}")
        per_check.append((kind, p, d))

    passes = [p for _, p, _ in per_check]
    if combine == "all_pass":
        passed = all(passes)
    elif combine == "any_pass":
        passed = any(passes)
    else:
        raise ValueError(
            f"unsupported scoring.combine: {combine!r} "
            "(abp checks are binary; use all_pass/any_pass)"
        )
    detail = "; ".join(f"{k}: {d}" for k, _p, d in per_check if d) or "OK"
    return passed, detail, per_check


# --------------------------------------------------------------------------- #
# Dataset loading (Eval Commons model)
# --------------------------------------------------------------------------- #

def _ref_key(ref: str) -> str:
    """rubric/<id>@<version>  ->  <id>@<version> (the resolution key)."""
    parts = ref.split("/", 1)
    if len(parts) != 2:
        raise ValueError(
            f"malformed rubric_ref {ref!r}: expected 'rubric/<id>@<version>'"
        )
    return parts[1]


def _index_rubrics(skill: str, records: list[dict]) -> dict[str, dict]:
    """Index exact rubric references without silently overwriting duplicates."""
    rubrics = {}
    for rubric in records:
        key = f"{rubric['id']}@{rubric['version']}"
        if key in rubrics:
            raise ValueError(
                f"{skill}: duplicate rubric definition for {key!r}"
            )
        rubrics[key] = rubric
    return rubrics


def load_cases(skill: str) -> list[dict]:
    """Load a skill's behavioral cases from dataset.yaml + rubrics.yaml.

    Returns a list of dicts: id, prompt, criteria, expectations, checks, combine.
    Disabled questions are filtered out per the Commons loader contract.
    """
    import yaml  # lazy: keep the pip-free --static path free of PyYAML

    sk = EVALS_DIR / skill
    dataset = yaml.safe_load((sk / "dataset.yaml").read_text()) or {}
    rubric_doc = yaml.safe_load((sk / "rubrics.yaml").read_text()) or {}
    # Key by id@version so a rubric_ref resolves to the exact version it names;
    # duplicate ids with different versions stay distinct and exact duplicates
    # fail rather than silently resolving to the final YAML entry.
    rubrics = _index_rubrics(skill, rubric_doc.get("rubrics", []))

    cases = []
    for q in dataset.get("questions", []):
        if q.get("disabled"):
            continue
        key = _ref_key(q["rubric_ref"])
        if key not in rubrics:
            raise KeyError(
                f"{skill}: rubric_ref {q['rubric_ref']!r} not found in rubrics.yaml"
            )
        rub = rubrics[key]
        meta = q.get("metadata") or {}
        cases.append({
            "id": q["id"],
            "prompt": q["input"],
            "criteria": q.get("criteria", ""),
            "expectations": meta.get("expectations", []),
            # Full question metadata: command-check runners resolve
            # {{ metadata.<key> }} and read run_group/phase from here.
            "metadata": meta,
            "checks": rub["checks"],
            "combine": rub.get("scoring", {}).get("combine", "all_pass"),
        })
    return cases


def has_dataset(skill: str) -> bool:
    return (EVALS_DIR / skill / "dataset.yaml").exists()


def skill_file_for(skill: str) -> str:
    """Repo-relative path to a skill's source file (directory or flat layout)."""
    if (REPO_ROOT / "skills" / skill / "SKILL.md").exists():
        return f"skills/{skill}/SKILL.md"
    return f"skills/{skill}.md"
