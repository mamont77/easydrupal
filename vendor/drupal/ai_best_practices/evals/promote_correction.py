"""Promote a correction JSONL entry (with eval_seed) into an Eval Commons case.

The behavioral case format is the Eval Commons dataset model — a `dataset.yaml`
question plus a matching `rubrics.yaml` rubric — NOT the old flat `evals.json`
shape. `promote_correction()` returns the `(question, rubric)` pair: append the
question to `evals/<skill>/dataset.yaml` (`questions[]`) and the rubric to
`rubrics.yaml` (`rubrics[]`).

The mapping mirrors `evals/_migrate_to_commons.py` (the one-shot evals.json ->
Commons migrator). That script is transitional and will be deleted, so the
mapping is duplicated here by intent rather than imported (importing it would
also pull PyYAML into this otherwise stdlib-only helper):

    eval_seed.prompt            -> question.input
    entry.corrected             -> question.criteria + metadata.expectations[0]
    (skill)                     -> question.bundle  (snake_case)
    eval_seed.must_contain_any  -> rubric check {kind: must_contain_any, values}
    eval_seed.must_not_contain  -> rubric check {kind: must_not_contain, values}
    (grading rule)              -> rubric.scoring.combine: all_pass

Sample correction entry: see evals/drupal-expert-corrections/README.md.
"""
from __future__ import annotations

import re


def _qid(raw) -> str:
    """Question id as a string; ints get the B0N convention used in traces."""
    return f"B{raw:02d}" if isinstance(raw, int) else str(raw)


_SNAKE = re.compile(r"^[a-z][a-z0-9_]*$")
# Mirrors rubric.schema.json's `version` pattern. Kept in sync by hand: the
# schema is vendored at evals/schema/ and this module must not import it (it
# stays dependency-free, no jsonschema).
_VERSION = re.compile(r"^[0-9]+\.[0-9]+(\.[0-9]+)?$")


def _bundle(skill: str) -> str:
    """snake_case bundle id (hyphens/slashes -> _).

    Raises ValueError if the skill cannot produce an id matching the schema's
    ``^[a-z][a-z0-9_]*$`` (empty after sanitizing, or not starting with a
    letter) — emitting an invalid bundle would only fail later at load/CI time.
    """
    b = re.sub(r"[^a-z0-9]+", "_", skill.lower()).strip("_")
    if not _SNAKE.match(b):
        raise ValueError(
            f"skill {skill!r} yields no schema-valid bundle id "
            f"(got {b!r}; must match ^[a-z][a-z0-9_]*$)"
        )
    return b


def _rubric_id(bundle: str, qid: str) -> str:
    """Rubric id ``<bundle>_<sanitized-qid>``, matching ^[a-z][a-z0-9_]*$.

    ``bundle`` is already validated, so the prefix guarantees a leading letter;
    the question id is sanitized. Raises if the qid sanitizes to empty (that
    would collapse the rubric id onto the bare bundle and risk colliding with
    another case's id).
    """
    qpart = re.sub(r"[^a-z0-9]+", "_", qid.lower()).strip("_")
    if not qpart:
        raise ValueError(
            f"question id {qid!r} sanitizes to empty; cannot build a unique rubric id"
        )
    return f"{bundle}_{qpart}"


def _checks_from_seed(seed: dict) -> list[dict]:
    """eval_seed assertion fields -> Eval Commons rubric checks (omit when empty)."""
    checks: list[dict] = []
    if seed.get("must_contain_any"):
        checks.append({"kind": "must_contain_any", "values": list(seed["must_contain_any"])})
    if seed.get("must_not_contain"):
        checks.append({"kind": "must_not_contain", "values": list(seed["must_not_contain"])})
    return checks


def promote_correction(entry: dict, case_id, skill: str,
                       version: str = "1.0") -> tuple[dict, dict]:
    """Promote a correction entry (with eval_seed) into a Commons (question, rubric).

    Args:
        entry: a `corrections/<skill>.jsonl` object; must carry an ``eval_seed``
            with at least one of ``must_contain_any`` / ``must_not_contain`` (the
            gradable assertions).
        case_id: the question id (e.g. ``"B04"``); an int is rendered as ``B0N``.
        skill: the target skill name — drives the bundle and rubric id.
        version: rubric version (default ``"1.0"``).

    Returns:
        ``(question, rubric)``. Append ``question`` to the skill's
        ``dataset.yaml`` ``questions[]`` and ``rubric`` to ``rubrics.yaml``
        ``rubrics[]``. The two are linked by ``question.rubric_ref``.

    Raises:
        ValueError: the ``eval_seed`` has no gradable checks (a rubric with no
            checks could never fail, so it must not be emitted silently); the
            ``skill`` / ``case_id`` cannot produce a schema-valid bundle / rubric
            id (see ``_bundle`` / ``_rubric_id``); or ``version`` is not semver.
    """
    seed = entry.get("eval_seed") or {}
    qid = _qid(case_id)
    bundle = _bundle(skill)
    rubric_id = _rubric_id(bundle, qid)
    if not _VERSION.match(str(version)):
        raise ValueError(
            f"rubric version {version!r} is not semver (N.N or N.N.N); the schema "
            "rejects it, and load_cases keys rubrics as f'{id}@{version}', so the "
            "rubric_ref would not resolve"
        )

    checks = _checks_from_seed(seed)
    if not checks:
        raise ValueError(
            f"correction {qid!r}: eval_seed has no gradable checks "
            "(need must_contain_any and/or must_not_contain); cannot build a rubric"
        )

    corrected = entry.get("corrected", "")
    question = {
        "id": qid,
        "input": seed.get("prompt", ""),
        "criteria": corrected,
        "bundle": bundle,
        "rubric_ref": f"rubric/{rubric_id}@{version}",
    }
    if corrected:
        question["metadata"] = {"expectations": [corrected]}

    rubric = {
        "id": rubric_id,
        "version": version,
        "applicable_bundles": [bundle],
        "checks": checks,
        "scoring": {"combine": "all_pass"},
    }
    return question, rubric
