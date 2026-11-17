#!/usr/bin/env python3
"""Migrate an abp skill's evals.json to the Eval Commons dataset model.

Produces two files per skill, both conforming to the ai_eval Layer 1 schemas:

  evals/<skill>/dataset.yaml   # questions[]  (dataset.schema.json)
  evals/<skill>/rubrics.yaml   # rubrics[]    (each item -> rubric.schema.json)

The mapping is mechanical and lossless, so behavioral pass/fail is preserved
exactly (scorer parity):

  prompt            -> question.input
  expected_output   -> question.criteria
  expectations      -> question.metadata.expectations
  (skill)           -> question.bundle  (snake_case)
  must_contain_any  -> rubric check {kind: must_contain_any, values}
  must_not_contain  -> rubric check {kind: must_not_contain, values}   (if non-empty)
  check_php_lint    -> rubric check {kind: php_lint}                    (only when true)
  check_markdown_structure -> rubric check {kind: markdown_structure, ...rules}
  (grading rule)    -> rubric.scoring.combine: all_pass

Each question references its per-case rubric via rubric_ref. Per-case rubrics
are required because the Commons dataset question has no inline home for
must_contain_any (used by every abp case); putting it in metadata would be
smuggling, not adoption.

Usage:
    python3 evals/_migrate_to_commons.py --skill drupal-gitlab \
        --schema-dir ../ai_eval/schema [--write]

Without --write it prints the YAML and validates; with --write it writes the
two files.

Written for the #3588958 migration, kept as a supported tool. Branches opened
before that migration still carry evals.json and each needs this pass before it
can merge, so this is the documented path for rebasing an old contribution, not
a one-shot to be deleted. test_scoring.py's layout guard points contributors
here by name when it finds a legacy file.
"""
from __future__ import annotations

import argparse
import re
import json
import sys
from pathlib import Path

import yaml

REPO = Path(__file__).resolve().parent.parent
EVALS = REPO / "evals"

# Base-contract fields of the Commons markdown_structure check kind. abp rules
# outside this set (e.g. min_paragraphs) are domain-specific and ride in the
# check's metadata extension slot rather than forcing a Layer 1 schema change.
MD_ALLOWED = {
    "min_headings", "max_heading_level", "required_sections",
    "no_h1", "min_code_blocks", "forbidden_patterns",
}

# Every legacy per-case field has an intentional Commons destination. Refuse an
# unfamiliar key rather than migrating a case while silently changing its grade.
CASE_KEYS = {
    "id", "prompt", "expected_output", "expectations", "must_contain_any",
    "must_not_contain", "check_php_lint", "check_markdown_structure",
}


def _qid(raw) -> str:
    """Question id as a string; ints get the B0N convention used in traces."""
    return f"B{raw:02d}" if isinstance(raw, int) else str(raw)


def _bundle(skill: str) -> str:
    # snake_case, valid against the bundle pattern ^[a-z][a-z0-9_]*$. Handles
    # nested skill paths (e.g. "drupal-accessibility/drupal-a11y-dom") by folding
    # both hyphens and slashes to underscores.
    return re.sub(r"[^a-z0-9]+", "_", skill.lower()).strip("_")


def _rubric_id(skill: str, qid: str) -> str:
    # Rubric ids must be snake_case (^[a-z][a-z0-9_]*$); question ids may not be
    # (some skills use hyphenated descriptive ids), so sanitize for the rubric.
    base = re.sub(r"[^a-z0-9]+", "_", f"{_bundle(skill)}_{qid}".lower())
    return base.strip("_")


def _checks_for(case: dict, skill: str, qid: str) -> list[dict]:
    checks: list[dict] = []
    if case.get("must_contain_any"):
        checks.append({"kind": "must_contain_any", "values": case["must_contain_any"]})
    if case.get("must_not_contain"):  # omit when empty list / absent
        checks.append({"kind": "must_not_contain", "values": case["must_not_contain"]})
    if case.get("check_php_lint"):  # only when truthy; false == no check today
        checks.append({"kind": "php_lint"})
    ms = case.get("check_markdown_structure")
    if ms:
        # Base-contract rules inline; abp-specific rules (e.g. min_paragraphs) ride
        # in the check's metadata slot — a Layer 5 domain extension on top of the
        # base contract, not a Layer 1 schema change. The runner reads both.
        base = {k: v for k, v in ms.items() if k in MD_ALLOWED}
        ext = {k: v for k, v in ms.items() if k not in MD_ALLOWED}
        check = {"kind": "markdown_structure", **base}
        if ext:
            check["metadata"] = ext
        checks.append(check)
    if not checks:
        raise SystemExit(f"{skill}/{qid}: no gradable checks; cannot build a rubric.")
    return checks


def _validate_case_keys(case: dict, skill: str) -> None:
    """Fail closed when a legacy grader field has no Commons mapping."""
    unknown = sorted(set(case) - CASE_KEYS)
    if unknown:
        case_id = case.get("id", "<missing id>")
        raise SystemExit(
            f"{skill}/{case_id}: unsupported legacy eval key(s): "
            f"{', '.join(unknown)}"
        )


def build(skill: str) -> tuple[dict, list[dict]]:
    data = json.loads((EVALS / skill / "evals.json").read_text())
    bundle = _bundle(skill)
    questions, rubrics = [], []
    rubric_sources: dict[str, str] = {}
    for case in data.get("evals", []):
        _validate_case_keys(case, skill)
        qid = _qid(case["id"])
        rid = _rubric_id(skill, qid)
        if rid in rubric_sources:
            raise SystemExit(
                f"{skill}: question ids {rubric_sources[rid]!r} and {qid!r} "
                f"normalize to the same rubric id {rid!r}"
            )
        rubric_sources[rid] = qid
        q = {
            "id": qid,
            "input": case["prompt"],
            "criteria": case.get("expected_output", ""),
            "bundle": bundle,
            "rubric_ref": f"rubric/{rid}@1.0",
        }
        if case.get("expectations"):
            q["metadata"] = {"expectations": case["expectations"]}
        questions.append(q)
        rubrics.append({
            "id": rid,
            "version": "1.0",
            "applicable_bundles": [bundle],
            "checks": _checks_for(case, skill, qid),
            "scoring": {"combine": "all_pass"},
        })
    return {"questions": questions}, rubrics


def validate(dataset: dict, rubrics: list[dict], schema_dir: Path) -> None:
    import jsonschema
    ds_schema = json.loads((schema_dir / "dataset.schema.json").read_text())
    rb_schema = json.loads((schema_dir / "rubric.schema.json").read_text())
    jsonschema.validate(dataset, ds_schema)
    for r in rubrics:
        jsonschema.validate(r, rb_schema)
    print(f"validated: dataset ({len(dataset['questions'])} q) + "
          f"{len(rubrics)} rubric(s) — OK", file=sys.stderr)


def _dump(obj) -> str:
    return yaml.safe_dump(obj, sort_keys=False, allow_unicode=True, width=100)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--skill", required=True)
    ap.add_argument("--schema-dir", default=str(EVALS / "schema"),
                    help="dir with dataset.schema.json + rubric.schema.json (default: vendored evals/schema)")
    ap.add_argument("--write", action="store_true", help="write the two YAML files")
    args = ap.parse_args()

    dataset, rubrics = build(args.skill)
    validate(dataset, rubrics, Path(args.schema_dir))

    rubrics_doc = {"rubrics": rubrics}
    if args.write:
        (EVALS / args.skill / "dataset.yaml").write_text(_dump(dataset))
        (EVALS / args.skill / "rubrics.yaml").write_text(_dump(rubrics_doc))
        print(f"wrote evals/{args.skill}/dataset.yaml + rubrics.yaml", file=sys.stderr)
    else:
        print("# ===== dataset.yaml =====")
        print(_dump(dataset))
        print("# ===== rubrics.yaml =====")
        print(_dump(rubrics_doc))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
