#!/usr/bin/env python3
"""Regression tests for evals/scoring.py — the shared scoring core.

run-evals.py (main runner) and compare.py (control arm) both grade through this
module, so a silent change here would skew both the headline eval and the A-B
baseline at once. These tests pin the deterministic check + combine behaviour and
confirm every shipped skill's dataset loads with at least one gradable check.

Deterministic, no API calls, CI-safe.  Run:  python3 evals/test_scoring.py

Provenance: the migration from evals.json to the Eval Commons dataset model
(#3588958) was gated by a parity check (compare old vs new grading over a probe
battery) that passed 352/352 before evals.json was removed. This file is its
lasting successor — once evals.json is gone there is no old path left to diff,
so we pin behaviour directly instead.
"""
from __future__ import annotations

import json
import sys
import tempfile
from pathlib import Path

EVALS = Path(__file__).resolve().parent
sys.path.insert(0, str(EVALS))

import scoring
import _migrate_to_commons as migrate


def find_layout_violations(root: Path) -> list[str]:
    """Structural problems in the eval layout under `root`, as readable messages.

    Neither CI job can see a suite that predates the Eval Commons dataset model
    (#3588958): run-evals.py --static reads only static-checks.json, and suite
    discovery here rglobs dataset.yaml. A suite carrying just evals.json is
    invisible to both, passes green, and merges with its behavioral cases dead.

    Takes `root` rather than reading EVALS directly so the guard can be tested
    against synthetic trees instead of only the repo's own (clean) state.
    """
    problems = []
    # Only advertise the EEE export when the converter is actually present:
    # it arrives with the Inspect runner (#3588916), so on a checkout without
    # it the pointer would name a file the contributor cannot open.
    eee = " Once converted, results export to the EEE 0.2.2 envelope via " \
          "evals/inspect/to_eee.py." if (root / "inspect" / "to_eee.py").exists() else ""
    for legacy in sorted(root.rglob("evals.json")):
        suite = legacy.parent.relative_to(root).as_posix()
        problems.append(
            f"{legacy.relative_to(root).as_posix()}: legacy evals.json, removed in "
            f"#3588958. Its cases will not run. Convert with: "
            f"python3 evals/_migrate_to_commons.py --skill {suite} --write.{eee}"
        )
    for dataset in sorted(root.rglob("dataset.yaml")):
        if not (dataset.parent / "rubrics.yaml").exists():
            suite = dataset.parent.relative_to(root).as_posix()
            problems.append(
                f"{dataset.relative_to(root).as_posix()}: no sibling rubrics.yaml, so "
                f"load_cases({suite!r}) cannot resolve any rubric_ref. Add rubrics.yaml "
                f"(one rubric per question), or drop the dataset if the suite is "
                f"static-only."
            )
    return problems


# Discover skills dynamically at any depth (rglob), so nested suites like
# drupal-accessibility/drupal-a11y-dom are covered too, not just top-level dirs.
# Suites missing rubrics.yaml are excluded here and reported by the layout guard
# instead: without this they would raise FileNotFoundError out of load_cases()
# and take the whole run down before any other check reported.
SKILLS = sorted(str(p.parent.relative_to(EVALS)) for p in EVALS.rglob("dataset.yaml")
                if (p.parent / "rubrics.yaml").exists())

failures = []


def schema_validate():
    """Validate every dataset/rubric against the vendored Commons schemas.

    Schemas are vendored at evals/schema/ (see its README), so this runs in CI —
    not just on a dev box with a sibling ai_eval checkout. Skips only if jsonschema
    is unavailable; the CI evals-scoring job installs it.
    """
    try:
        import jsonschema
        import yaml
    except ImportError:
        print("  (schema validation skipped: jsonschema/PyYAML not available)")
        return
    schema_dir = EVALS / "schema"
    ds_path, rb_path = schema_dir / "dataset.schema.json", schema_dir / "rubric.schema.json"
    if not (ds_path.exists() and rb_path.exists()):
        failures.append(f"schema: vendored schemas missing at {schema_dir}")
        return
    import json
    ds_schema = json.loads(ds_path.read_text())
    rb_schema = json.loads(rb_path.read_text())
    for skill in SKILLS:
        sk = EVALS / skill
        try:
            jsonschema.validate(yaml.safe_load((sk / "dataset.yaml").read_text()), ds_schema)
            for r in (yaml.safe_load((sk / "rubrics.yaml").read_text()) or {}).get("rubrics", []):
                jsonschema.validate(r, rb_schema)
        except jsonschema.ValidationError as e:
            failures.append(f"schema/{skill}: {e.message}")
    print(f"  (schema validation ran against {len(SKILLS)} skills)")


def check(name, cond):
    if not cond:
        failures.append(name)


# --- must_contain_any: case-insensitive, empty = pass ---
check("contain/hit", scoring.check_must_contain_any("the FOO bar", ["foo"])[0] is True)
check("contain/miss", scoring.check_must_contain_any("nope", ["foo", "baz"])[0] is False)
check("contain/empty-pass", scoring.check_must_contain_any("anything", [])[0] is True)
check("contain/case-sensitive",
      scoring.check_must_contain_any("FOO", ["foo"], case_sensitive=True)[0] is False)

# --- must_not_contain: forbidden present = fail, empty = pass ---
check("notcontain/clean", scoring.check_must_not_contain("safe text", ["bad"])[0] is True)
check("notcontain/trip", scoring.check_must_not_contain("a BAD thing", ["bad"])[0] is False)
check("notcontain/empty-pass", scoring.check_must_not_contain("x", [])[0] is True)

# --- php_lint: a missing executable is an unavailable check, never a pass ---
_run = scoring.subprocess.run


def missing_php(*args, **kwargs):
    raise FileNotFoundError()


try:
    scoring.subprocess.run = missing_php
    php_missing_ok, php_missing_detail = scoring.check_php_lint("```php\n$ok = true;\n```")
    check("php-lint/missing-php-fails", php_missing_ok is False)
    check("php-lint/missing-php-detail", "php not found" in php_missing_detail)
finally:
    scoring.subprocess.run = _run

# --- markdown_structure incl. the min_paragraphs Layer-5 extension ---
md_ok = "# H1\n## H2\n### H3\n\np one\n\np two\n\n```php\n$x=1;\n```\n"
check("md/headings-ok", scoring.check_markdown_structure(md_ok, {"min_headings": 3})[0] is True)
check("md/headings-short",
      scoring.check_markdown_structure("## only one", {"min_headings": 3})[0] is False)
check("md/min-paragraphs-fail",
      scoring.check_markdown_structure("# H\n\nonly one para", {"min_paragraphs": 5})[0] is False)
check("md/no-h1",
      scoring.check_markdown_structure("# top", {"no_h1": True})[0] is False)

# --- grade(): all_pass / any_pass + the metadata-carried md extension ---
checks_all = [
    {"kind": "must_contain_any", "values": ["alpha"]},
    {"kind": "must_not_contain", "values": ["omega"]},
]
check("grade/all-pass", scoring.grade("alpha only", checks_all, "all_pass")[0] is True)
check("grade/all-one-fails", scoring.grade("alpha omega", checks_all, "all_pass")[0] is False)
check("grade/any-pass", scoring.grade("alpha omega", checks_all, "any_pass")[0] is True)

# md_ok has 3 paragraph lines ("p one", "p two", "$x=1;"); the fail probe has 1.
md_ext = [{"kind": "markdown_structure", "min_headings": 1, "metadata": {"min_paragraphs": 2}}]
check("grade/md-ext-fail", scoring.grade("# h\n\none para", md_ext, "all_pass")[0] is False)
check("grade/md-ext-pass", scoring.grade(md_ok, md_ext, "all_pass")[0] is True)

try:
    scoring.grade("x", [{"kind": "bogus"}], "all_pass")
    check("grade/unknown-kind-raises", False)
except ValueError:
    check("grade/unknown-kind-raises", True)

# --- layout guard: exercised on synthetic trees, then on the real evals/ ---
with tempfile.TemporaryDirectory() as _td:
    _root = Path(_td)

    def _suite(name, *files):
        d = _root / name
        d.mkdir(parents=True)
        for f in files:
            (d / f).write_text("questions: []\n" if f == "dataset.yaml" else "rubrics: []\n")
        return d

    _suite("ok", "dataset.yaml", "rubrics.yaml")
    _suite("nested/deep", "dataset.yaml", "rubrics.yaml")
    check("guard/clean-tree", find_layout_violations(_root) == [])

    (_root / "legacy").mkdir()
    (_root / "legacy" / "evals.json").write_text("{}")
    _p = find_layout_violations(_root)
    check("guard/legacy-detected", len(_p) == 1 and _p[0].startswith("legacy/evals.json:"))
    check("guard/legacy-names-migrator", "_migrate_to_commons.py --skill legacy" in _p[0])

    _suite("orphan", "dataset.yaml")
    _p = find_layout_violations(_root)
    check("guard/orphan-detected", any(x.startswith("orphan/dataset.yaml:") for x in _p))
    check("guard/orphan-names-rubrics", any("no sibling rubrics.yaml" in x for x in _p))
    check("guard/both-reported", len(_p) == 2)

    # Nested legacy suites are caught too — discovery is rglob, so a legacy
    # suite one level down is exactly as invisible to CI as a top-level one.
    (_root / "nested" / "old").mkdir()
    (_root / "nested" / "old" / "evals.json").write_text("{}")
    check("guard/legacy-nested",
          any(x.startswith("nested/old/evals.json:") for x in find_layout_violations(_root)))

    # The EEE pointer is conditional on the converter being present, so the
    # message never names a file this checkout does not have.
    check("guard/no-eee-pointer-without-converter",
          all("to_eee.py" not in x for x in find_layout_violations(_root)))
    (_root / "inspect").mkdir()
    (_root / "inspect" / "to_eee.py").write_text("")
    _legacy = [x for x in find_layout_violations(_root) if "evals.json" in x]
    check("guard/eee-pointer-with-converter",
          bool(_legacy) and all("evals/inspect/to_eee.py" in x for x in _legacy))

for _problem in find_layout_violations(EVALS):
    failures.append(f"layout: {_problem}")

# --- rubric resolution must never silently replace a normalized duplicate ---
with tempfile.TemporaryDirectory() as tmp:
    collision_root = Path(tmp)
    collision_skill = collision_root / "collision"
    collision_skill.mkdir()
    (collision_skill / "dataset.yaml").write_text(
        "questions:\n"
        "  - id: B01\n"
        "    input: prompt\n"
        "    rubric_ref: rubric/duplicate@1.0\n"
    )
    (collision_skill / "rubrics.yaml").write_text(
        "rubrics:\n"
        "  - id: duplicate\n"
        "    version: '1.0'\n"
        "    checks:\n"
        "      - kind: must_contain_any\n"
        "        values: [first]\n"
        "    scoring: {combine: all_pass}\n"
        "  - id: duplicate\n"
        "    version: '1.0'\n"
        "    checks:\n"
        "      - kind: must_contain_any\n"
        "        values: [second]\n"
        "    scoring: {combine: all_pass}\n"
    )
    original_evals_dir = scoring.EVALS_DIR
    try:
        scoring.EVALS_DIR = collision_root
        scoring.load_cases("collision")
        check("load/duplicate-rubric-raises", False)
    except ValueError as error:
        check("load/duplicate-rubric-raises", "duplicate rubric" in str(error))
    finally:
        scoring.EVALS_DIR = original_evals_dir

# --- migration rejects source keys that it cannot represent in Commons ---
with tempfile.TemporaryDirectory() as tmp:
    migration_root = Path(tmp)
    migration_skill = migration_root / "migration"
    migration_skill.mkdir()
    (migration_skill / "evals.json").write_text(json.dumps({"evals": [{
        "id": "case-a",
        "prompt": "prompt",
        "must_contain_any": ["required"],
        "unknown_grader_key": True,
    }]}))
    original_evals = migrate.EVALS
    try:
        migrate.EVALS = migration_root
        migrate.build("migration")
        check("migration/unknown-grader-key-raises", False)
    except SystemExit as error:
        check("migration/unknown-grader-key-raises", "unknown_grader_key" in str(error))
    finally:
        migrate.EVALS = original_evals

with tempfile.TemporaryDirectory() as tmp:
    migration_root = Path(tmp)
    migration_skill = migration_root / "collision"
    migration_skill.mkdir()
    (migration_skill / "evals.json").write_text(json.dumps({"evals": [
        {"id": "case-a", "prompt": "first", "must_contain_any": ["first"]},
        {"id": "case_a", "prompt": "second", "must_contain_any": ["second"]},
    ]}))
    original_evals = migrate.EVALS
    try:
        migrate.EVALS = migration_root
        migrate.build("collision")
        check("migration/normalized-rubric-collision-raises", False)
    except SystemExit as error:
        check("migration/normalized-rubric-collision-raises", "normalize to the same rubric id" in str(error))
    finally:
        migrate.EVALS = original_evals

# --- another repository's skills/ + evals/ can be evaluated (--root) ---
import subprocess

with tempfile.TemporaryDirectory() as tmp:
    other = Path(tmp)
    (other / "skills" / "acme-demo").mkdir(parents=True)
    (other / "skills" / "acme-demo" / "SKILL.md").write_text(
        "---\nname: acme-demo\ndescription: A demonstration skill that lives in another repository.\n---\n\n# Demo\n\nUse the acme toolkit.\n"
    )
    (other / "evals" / "acme-demo").mkdir(parents=True)

    def write_checks(text: str) -> None:
        (other / "evals" / "acme-demo" / "static-checks.json").write_text(json.dumps({
            "skill_file": "skills/acme-demo/SKILL.md",
            "assertions": [
                {"id": "S01", "description": "exists", "check": "file_exists", "path": "skills/acme-demo/SKILL.md"},
                {"id": "S02", "description": "frontmatter", "check": "agent_skill_frontmatter"},
                {"id": "S03", "description": "mentions text", "check": "contains", "text": text},
            ],
        }))

    def run_root() -> subprocess.CompletedProcess:
        return subprocess.run(
            [sys.executable, str(EVALS / "run-evals.py"), "--static", "--root", str(other)],
            capture_output=True, text=True,
        )

    write_checks("acme toolkit")
    passing = run_root()
    check("root/static-passes-on-other-repo", passing.returncode == 0 and "acme-demo" in passing.stdout)
    check("root/only-other-repo-suites", "drupal-gitlab" not in passing.stdout)

    write_checks("text that is not there")
    check("root/static-fails-on-other-repo", run_root().returncode != 0)

    # A mistyped --root, or one with no suites, must fail rather than pass having run nothing.
    for label, root in (("missing", other / "no-such-dir"), ("empty", other / "skills")):
        bad = subprocess.run(
            [sys.executable, str(EVALS / "run-evals.py"), "--static", "--root", str(root)],
            capture_output=True, text=True,
        )
        check(f"root/{label}-root-fails", bad.returncode != 0 and "no eval suites" in bad.stderr)

    original_root = scoring.REPO_ROOT
    try:
        scoring.set_repo_root(other)
        check("root/scoring-follows-root", scoring.EVALS_DIR == other.resolve() / "evals"
              and scoring.skill_file_for("acme-demo") == "skills/acme-demo/SKILL.md")
    finally:
        scoring.set_repo_root(original_root)
    check("root/scoring-restored", scoring.EVALS_DIR == original_root / "evals")

# --- command checks (check_command contract): templating, expect, sandboxes ---
check("cmd/template",
      scoring.resolve_command('run "{{ metadata.dir }}" {{metadata.n}}',
                              {"dir": "a b", "n": 3}) == 'run "a b" 3')
try:
    scoring.resolve_command("x {{ metadata.missing }}", {})
    check("cmd/template-missing-raises", False)
except KeyError:
    check("cmd/template-missing-raises", True)

_danger = '$HOME `printf exploited` $(printf also_exploited)'
_resolved = scoring.resolve_command('printf "%s" "{{ metadata.source }}"',
                                    {"source": _danger})
_proc = subprocess.run(["bash", "-c", _resolved], capture_output=True, text=True)
check("cmd/template-shell-metacharacters-literal",
      _proc.returncode == 0 and _proc.stdout == _danger)

# Fake runners record what they were asked to run; no processes involved.
ran = []
def _ok(command, timeout):
    ran.append(("ok", command))
    return 0, "fine"
def _bad(command, timeout):
    ran.append(("bad", command))
    return 3, "boom"
RUNNERS = {"ddev": _ok, "host": _bad}

_chk = {"kind": "command", "id": "c1", "sandbox": "ddev",
        "command": "test -d {{ metadata.d }}", "expect": "exit_zero"}
p, d, rec = scoring.run_command_check(_chk, {"d": "/tmp"}, RUNNERS)
check("cmd/exit-zero-pass", p is True and rec["command"] == "test -d /tmp")
p, _, rec = scoring.run_command_check({**_chk, "sandbox": "host"}, {"d": "/x"}, RUNNERS)
check("cmd/exit-zero-fail", p is False and rec["exit_code"] == 3)
class _TransitionRunner:
    def __call__(self, command, timeout):
        return 0, "after"

    def run_fail_to_pass(self, command, timeout, setup):
        return (3, "before"), (0, "after")

p, _, rec = scoring.run_command_check(
    {**_chk, "expect": "fail_to_pass", "setup": "revert_fix"},
    {"d": "/x"}, {"ddev": _TransitionRunner()})
check("cmd/fail-to-pass", p is True and rec["baseline_exit_code"] == 3)
# A baseline that could not run the command (126 not executable, 127 not
# found) is not a real failure, so it proves nothing about the change.
for _executor_code in (126, 127):
    class _BrokenBaseline(_TransitionRunner):
        def run_fail_to_pass(self, command, timeout, setup, code=_executor_code):
            return (code, "executor failure"), (0, "after")
    p, _, _ = scoring.run_command_check(
        {**_chk, "expect": "fail_to_pass", "setup": "revert_fix"},
        {"d": "/x"}, {"ddev": _BrokenBaseline()})
    check(f"cmd/fail-to-pass-rejects-baseline-{_executor_code}", p is False)
check("cmd/default-sandbox",
      scoring.run_command_check({k: v for k, v in _chk.items() if k != "sandbox"},
                                {"d": "/tmp"}, RUNNERS)[0] is True)

for bad_check, name in [
    ({**_chk, "sandbox": "kubernetes"}, "cmd/unknown-sandbox-rejected"),
    ({**_chk, "sandbox": None}, "cmd/missing-sandbox-rejected"),
    ({**_chk, "expect": "fail_to_pass", "setup": "revert_fix"},
     "cmd/fail-to-pass-needs-transition-runner"),
    ({**_chk, "setup": "apply_patch"}, "cmd/setup-not-ignored"),
    # The vendored schema lists exit_nonzero as invalid; reject it, never guess.
    ({**_chk, "expect": "exit_nonzero"}, "cmd/exit-nonzero-rejected"),
]:
    try:
        scoring.run_command_check(bad_check, {"d": "/tmp"}, RUNNERS)
        check(name, False)
    except ValueError:
        check(name, True)

# grade_command_checks: combine + callable metadata view (per-sandbox paths)
two = [dict(_chk, id="a"), dict(_chk, id="b", sandbox="host")]
p, _, recs = scoring.grade_command_checks(
    two, lambda c: {"d": f"/{c['sandbox']}"}, RUNNERS, "all_pass")
check("cmd/combine-all-fail", p is False)
check("cmd/meta-callable",
      recs[0]["command"] == "test -d /ddev" and recs[1]["command"] == "test -d /host")
p, _, _ = scoring.grade_command_checks(two, {"d": "/z"}, RUNNERS, "any_pass")
check("cmd/combine-any-pass", p is True)
try:
    scoring.grade_command_checks(
        [{"kind": "must_contain_any"}], {}, RUNNERS, "all_pass")
    check("cmd/mixed-kind-rejected", False)
except ValueError:
    check("cmd/mixed-kind-rejected", True)

# load_cases hands each question's metadata to command-check runners.
check("cmd/load-cases-carries-metadata",
      all(isinstance(c.get("metadata"), dict)
          for s in SKILLS for c in scoring.load_cases(s)))

# --- codex provider: answer only, throwaway home, context read back from the session log ---
import os
import providers

with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    bin_dir, src_home = tmp / "bin", tmp / "operator-codex"
    bin_dir.mkdir()
    src_home.mkdir()
    (src_home / "auth.json").write_text('{"token": "operator"}')
    (src_home / "AGENTS.md").write_text("operator's personal instructions")
    seen = tmp / "seen.json"
    # The fake reads FAKE_BLOCK (extra context to log) and FAKE_REFRESH (rewrite the login).
    fake = bin_dir / "codex"
    fake.write_text(f"""#!{sys.executable}
import json, os, stat, sys
from pathlib import Path
home = Path(os.environ["CODEX_HOME"])
auth = home / "auth.json"
json.dump({{"argv": sys.argv[1:], "HOME": os.environ["HOME"], "CODEX_HOME": str(home),
           "auth": auth.read_text() if auth.exists() else None,
           "auth_mode": oct(stat.S_IMODE(auth.stat().st_mode)) if auth.exists() else None}},
          open({str(seen)!r}, "w"))
prompt = sys.stdin.read()
Path({str(seen)!r} + ".prompt").write_text(prompt)
if os.environ.get("FAKE_REFRESH"):
    if os.environ.get("FAKE_OPERATOR_CHANGED"):
        Path({str(src_home / "auth.json")!r}).write_text('{{"token": "operator-relogged"}}')
    auth.write_text('{{"token": "refreshed"}}')
if not os.environ.get("FAKE_NO_LOG"):
    log = home / "sessions" / "2026" / "10" / "13" / "rollout-x.jsonl"
    log.parent.mkdir(parents=True)
    msgs = [{{"role": "developer", "text": "<skills_instructions>\\n## Skills\\nNone available."}}]
    if os.environ.get("FAKE_BLOCK"):
        msgs.append({{"role": "user", "text": os.environ["FAKE_BLOCK"]}})
    msgs.append({{"role": "user", "text": prompt}})
    with log.open("w") as f:
        f.write(json.dumps({{"type": "session_meta", "payload": {{"base_instructions": {{"text": "base"}}}}}}) + "\\n")
        for m in msgs:
            f.write(json.dumps({{"type": "response_item", "payload": {{"type": "message", "role": m["role"],
                    "content": [{{"type": "input_text", "text": m["text"]}}]}}}}) + "\\n")
print(json.dumps({{"type": "item.completed", "item": {{"type": "agent_message", "text": "Use drush cr."}}}}))
print(json.dumps({{"type": "turn.completed", "usage": {{"input_tokens": 10, "output_tokens": 3}}}}))
""")
    fake.chmod(0o755)
    saved = {k: os.environ.get(k) for k in ("PATH", "CODEX_HOME", "CODEX_API_KEY", "OPENAI_API_KEY",
                                           "FAKE_BLOCK", "FAKE_REFRESH", "FAKE_NO_LOG", "FAKE_OPERATOR_CHANGED")}
    os.environ.update(PATH=f"{bin_dir}{os.pathsep}{saved['PATH']}", CODEX_HOME=str(src_home))
    for k in ("CODEX_API_KEY", "OPENAI_API_KEY"):
        os.environ.pop(k, None)

    def codex_run(**fake_env):
        for k in ("FAKE_BLOCK", "FAKE_REFRESH", "FAKE_NO_LOG", "FAKE_OPERATOR_CHANGED"):
            os.environ.pop(k, None)
        os.environ.update(fake_env)
        try:
            return providers.run_codex("How do I clear caches?", "gpt-test"), None
        except providers.ContextLeakError as e:
            return None, e

    try:
        result, err = codex_run()
        s = json.loads(seen.read_text())
        args = s["argv"]
        check("codex/clean-answer", err is None and result["response"] == "Use drush cr.")
        check("codex/answer-only", "--full-auto" not in args
              and args[args.index("--sandbox") + 1] == "read-only"
              and all(f in args for f in ("shell_tool", "skill_search", "hooks", "memories")))
        check("codex/empty-skill-catalog", "skills.max_context_tokens=1" in args)
        check("codex/no-web-search", 'web_search="disabled"' in args)
        check("codex/answer-in-reply-note", Path(str(seen) + ".prompt").read_text()
              == "How do I clear caches?" + providers.ANSWER_ONLY_NOTE)
        check("codex/throwaway-home", s["CODEX_HOME"] != str(src_home)
              and Path(s["CODEX_HOME"]).parent == Path(s["HOME"]))
        check("codex/login-copied-private", s["auth"] == '{"token": "operator"}' and s["auth_mode"] == "0o600")
        check("codex/throwaway-home-removed", not Path(s["HOME"]).exists())
        check("codex/context-recorded", [b["kind"] for b in result["context"]["blocks"]]
              == ["codex_base_instructions", "skills_list", "prompt"] and result["context"]["leaks"] == [])

        _, err = codex_run(FAKE_BLOCK="# AGENTS.md instructions\n<INSTRUCTIONS>personal</INSTRUCTIONS>")
        check("codex/agents-md-refused", err is not None and "agents_md" in str(err))
        _, err = codex_run(FAKE_BLOCK="<skills_instructions>\n- drupal-code-review: Review code (file: /x/SKILL.md)")
        check("codex/listed-skill-refused", err is not None and "skills list names 1" in str(err))
        _, err = codex_run(FAKE_BLOCK=f"<environment_context>cwd {Path.home()}/projects</environment_context>")
        check("codex/home-path-refused", err is not None and str(Path.home()) in str(err))
        _, err = codex_run(FAKE_NO_LOG="1")
        check("codex/unverifiable-refused", err is not None and "cannot be checked" in str(err))

        codex_run(FAKE_REFRESH="1")
        check("codex/refreshed-login-written-back", (src_home / "auth.json").read_text() == '{"token": "refreshed"}')
        (src_home / "auth.json").write_text('{"token": "operator"}')
        codex_run(FAKE_REFRESH="1", FAKE_OPERATOR_CHANGED="1")
        check("codex/newer-operator-login-kept",
              (src_home / "auth.json").read_text() == '{"token": "operator-relogged"}')
        (src_home / "auth.json").write_text('{"token": "operator"}')

        os.environ["OPENAI_API_KEY"] = "test-key"
        codex_run()
        check("codex/api-key-no-login-copy", json.loads(seen.read_text())["auth"] is None)
    finally:
        for k, v in saved.items():
            if v is None:
                os.environ.pop(k, None)
            else:
                os.environ[k] = v

# --- claude provider: answer only, no tools and no skills ---
_orig_run = providers.subprocess.run
_claude_cmd = {}
def _fake_claude_run(cmd, **kw):
    _claude_cmd["cmd"] = cmd
    _claude_cmd["input"] = kw.get("input")
    return providers.subprocess.CompletedProcess(cmd, 0, stdout='{"result": "ok"}', stderr="")
providers.subprocess.run = _fake_claude_run
try:
    providers.run_claude("q", "sonnet")
finally:
    providers.subprocess.run = _orig_run
_c = _claude_cmd["cmd"]
check("claude/no-tools", _c[_c.index("--tools") + 1] == "")
check("claude/no-skills", "--disable-slash-commands" in _c and _c[_c.index("--setting-sources") + 1] == "")
check("claude/answer-in-reply-note", _claude_cmd["input"] == "q" + providers.ANSWER_ONLY_NOTE)

# --- every shipped skill loads with >=1 gradable check per case ---
for skill in SKILLS:
    cases = scoring.load_cases(skill)
    check(f"load/{skill}/nonempty", len(cases) > 0)
    for c in cases:
        check(f"load/{skill}/{c['id']}/has-checks", len(c.get("checks", [])) >= 1)
        check(f"load/{skill}/{c['id']}/has-prompt", bool(c.get("prompt")))

# --- kiro provider: Kiro CLI v3 headless, answer-only, empty KIRO_HOME, context audited ---
import contextlib
import io
import os
import signal
import time
import providers


def kiro_fake(answer="answered", steering=(), commands=(("context-gatherer", "custom-agent", "bundled"),),
              stderr=(), truncated=False, session_log=True, log_dir=None):
    """Source of a fake kiro-cli: stream-json on stdout, a session log under $KIRO_HOME."""
    events = [{"type": "runStarted", "data": {"engine": "v3"}}]
    events.append({"type": "sessionUpdate", "data": {"update": {
        "sessionUpdate": "available_commands_update",
        "availableCommands": [{"name": n, "_meta": {"kiro": {"type": t, "resource": {"source": {"origin": o}}}}}
                              for n, t, o in commands]}}})
    for chunk in (answer[: len(answer) // 2], answer[len(answer) // 2:]):
        events.append({"type": "sessionUpdate", "data": {"update": {
            "sessionUpdate": "agent_message_chunk", "content": {"type": "text", "text": chunk}}}})
    events.append({"type": "runFinished", "data": {
        "status": "success", "finalText": answer[:3] if truncated else answer, "finalTextTruncated": truncated}})
    session = [{"payload": {"type": "session_start", "content": "You are Kiro.",
                            "steeringDocuments": [{"id": f"file:///{n}.md", "displayName": n, "scope": "global"}
                                                  for n in steering]}}]
    return (
        f"#!{sys.executable}\n"
        "import json, os, select, sys\n"
        # Reads stdin for at most a second, so a provider that leaves stdin open fails fast.
        "ready = select.select([sys.stdin], [], [], 1)[0]\n"
        "stdin = sys.stdin.read() if ready else '<stdin left open>'\n"
        f"log_dir = {str(log_dir)!r}\n"
        "if log_dir != 'None':\n"
        "    open(os.path.join(log_dir, 'argv.txt'), 'w').write('\\0'.join([os.getcwd(), *sys.argv[1:]]) + '\\0')\n"
        "    open(os.path.join(log_dir, 'stdin.txt'), 'w').write(stdin)\n"
        "    home = os.environ.get('KIRO_HOME', '')\n"
        "    open(os.path.join(log_dir, 'kiro_home.txt'), 'w').write(home + '\\0' + (repr(os.listdir(home)) if home else ''))\n"
        f"if {session_log!r} and os.environ.get('KIRO_HOME'):\n"
        "    d = os.path.join(os.environ['KIRO_HOME'], 'sessions', 'w1', 'sess_1')\n"
        "    os.makedirs(d)\n"
        f"    open(os.path.join(d, 'messages.jsonl'), 'w').write(''.join(json.dumps(e) + '\\n' for e in {session!r}))\n"
        f"for line in {list(stderr)!r}:\n"
        "    print(line, file=sys.stderr)\n"
        f"for e in {events!r}:\n"
        "    print(json.dumps(e))\n"
    )


def kiro_run(fake_source, prompt="x", model="claude-haiku-4.5"):
    """Run the kiro provider against a fake CLI; returns (result or exception, captured stderr)."""
    fake.write_text(fake_source)
    fake.chmod(0o755)
    surfaced = io.StringIO()
    os.environ["PATH"] = f"{bin_dir}{os.pathsep}{original_path}"
    try:
        with contextlib.redirect_stderr(surfaced):
            try:
                return providers.PROVIDERS["kiro"](prompt, model), surfaced.getvalue()
            except providers.ContextLeakError as e:
                return e, surfaced.getvalue()
    finally:
        os.environ["PATH"] = original_path


with tempfile.TemporaryDirectory() as tmp:
    bin_dir = Path(tmp)
    fake = bin_dir / "kiro-cli"
    original_path = os.environ["PATH"]

    # Eval prompts start with the skill file, so with its "---" frontmatter.
    prompt = "---\nname: demo\n---\n\nHow do I clear caches?"
    result, _ = kiro_run(kiro_fake("Use drush cr.", log_dir=bin_dir), prompt)
    logged = (bin_dir / "argv.txt").read_text().split("\0")[:-1]  # NUL-separated: the prompt has newlines
    cwd, args = logged[0], logged[1:]
    kiro_home, home_listing = (bin_dir / "kiro_home.txt").read_text().split("\0")
    check("kiro/registered", "kiro" in providers.PROVIDER_DEFAULTS and "kiro" in providers.ISOLATION_MODES)
    check("kiro/v3-headless", args[:1] == ["chat"] and "--no-interactive" in args
          and args[args.index("--agent-engine") + 1] == "v3")
    check("kiro/no-tools-trusted", "--trust-tools=" in args and "--trust-all-tools" not in args)
    check("kiro/stream-json", args[args.index("--output-format") + 1] == "stream-json")
    check("kiro/model-passed", args[args.index("--model") + 1] == "claude-haiku-4.5")
    # "auto" lets Kiro pick a model per task, so two runs could test different models.
    check("kiro/default-model-is-fixed", providers.PROVIDER_DEFAULTS["kiro"] not in ("", "auto"))
    # The prompt goes on stdin: no argv size limit, and its "---" frontmatter is not
    # parsed as an option. Like the other agent CLIs, it is told to answer in its reply.
    check("kiro/prompt-on-stdin", (bin_dir / "stdin.txt").read_text() == prompt + providers.ANSWER_ONLY_NOTE
          and prompt not in args and "--" not in args)
    check("kiro/isolated-cwd", Path(cwd).resolve() != Path.cwd().resolve() and "eval-kiro-" in cwd)
    # Each call gets its own empty KIRO_HOME, so ~/.kiro steering, skills and agents stay out.
    check("kiro/empty-kiro-home", "eval-kiro-home-" in kiro_home and home_listing == "[]"
          and Path(kiro_home) != Path.home() / ".kiro")
    check("kiro/kiro-home-removed", not Path(kiro_home).exists())
    check("kiro/answer-from-final-text", isinstance(result, dict) and result["exit_code"] == 0
          and result["response"] == "Use drush cr.")
    check("kiro/context-recorded", isinstance(result, dict) and result["context"].get("leaks") == []
          and any(b["kind"] == "kiro_base_instructions" for b in result["context"]["blocks"]))

    os.environ["PATH"] = str(bin_dir / "empty")
    try:
        check("kiro/missing-cli", providers.PROVIDERS["kiro"]("x", "auto")["exit_code"] == -1)
    finally:
        os.environ["PATH"] = original_path

    result, _ = kiro_run(kiro_fake("A long answer.", truncated=True))
    check("kiro/truncated-final-text-uses-chunks", isinstance(result, dict) and result["response"] == "A long answer.")

    # Context that is not Kiro's own means the "no skill" arm is not skill-free: refuse the answer.
    leak, _ = kiro_run(kiro_fake(steering=("my-rules",)))
    check("kiro/steering-leak-raises", isinstance(leak, providers.ContextLeakError) and "my-rules" in str(leak))
    leak, _ = kiro_run(kiro_fake(commands=(("my-skill", "skill", "user"),)))
    check("kiro/skill-leak-raises", isinstance(leak, providers.ContextLeakError) and "my-skill" in str(leak))
    # A kiro-cli that writes no session log under KIRO_HOME (2.16 did not) cannot be checked.
    leak, _ = kiro_run(kiro_fake(session_log=False))
    check("kiro/no-session-log-raises", isinstance(leak, providers.ContextLeakError))

    # Kiro logs [INFO], [tool] and [denied] lines on stderr, and Node prints an
    # ExperimentalWarning for its SQLite module. Anything else, such as an
    # error naming the model, must reach the person running the eval.
    result, surfaced = kiro_run(kiro_fake(stderr=(
        "[INFO] Auth: host-mediated",
        "[tool] Run Command",
        "[denied] tool permission approval is not supported in non-interactive mode",
        "[ERROR] (node:84828) ExperimentalWarning: SQLite is an experimental feature and might change at any time",
        "(Use `node --trace-warnings ...` to show where the warning was created)",
        "WARNING: could not set model claude-haiku-4.5, continuing with auto",
    )))
    check("kiro/stderr-warning-surfaced", "could not set model claude-haiku-4.5" in surfaced
          and isinstance(result, dict) and result["response"] == "answered")
    check("kiro/routine-stderr-quiet", not any(tag in surfaced for tag in ("[INFO]", "[tool]", "[denied]",
                                                                          "ExperimentalWarning", "--trace-warnings")))

    # Older kiro-cli releases exit after answering but leave their agent server running
    # with our stdout inherited. The provider must return when kiro-cli exits, not when
    # that pipe closes, and must not leave the server behind.
    pid_file = bin_dir / "server.pid"
    server = kiro_fake().replace("import json, os, select, sys\n", "import json, os, select, subprocess, sys\n", 1) \
        + f"open({str(pid_file)!r}, 'w').write(str(subprocess.Popen(['sleep', '30']).pid))\n"
    start = time.monotonic()
    result, _ = kiro_run(server)
    check("kiro/returns-when-cli-exits", isinstance(result, dict) and result["response"] == "answered"
          and time.monotonic() - start < 10)
    server_pid, server_gone = int(pid_file.read_text()), False
    for _ in range(20):  # a killed orphan stays visible until init reaps it
        try:
            os.kill(server_pid, 0)
        except ProcessLookupError:
            server_gone = True
            break
        time.sleep(0.1)
    if not server_gone:
        os.kill(server_pid, signal.SIGKILL)
    check("kiro/no-server-left-behind", server_gone)

# --- the EEE exporter knows every provider (its table must stay in step with PROVIDERS) ---
sys.path.insert(0, str(EVALS / "inspect"))
import to_eee  # stdlib-only at import time; the EEE extras load lazily

check("eee/provider-info-covers-providers",
      set(to_eee.ABP_PROVIDER_INFO) == set(providers.PROVIDERS))

schema_validate()

if failures:
    print(f"FAILED ({len(failures)}):")
    for f in failures:
        print("  -", f)
    raise SystemExit(1)
print(f"OK — all scoring + load checks passed across {len(SKILLS)} skills")
