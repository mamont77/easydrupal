# Evals: capturing-expert-corrections

Tests for the `skills/capturing-expert-corrections/SKILL.md` skill file.

## Static assertions

```bash
python3 evals/run-evals.py --static --skill capturing-expert-corrections
```

Checks that the skill file exists, has the required frontmatter fields, names
the four pipeline steps, includes all six classifications, includes the JSONL
schema fields `reasoning_failure`, `confidence`, `project_id`, and `eval_seed`,
references `continuous-learning-v2`, and stays under 300 lines.

## Behavioral cases

```bash
python3 evals/compare.py --skill capturing-expert-corrections --no-baseline --runs 1
```

The initial cases cover:
- `B01`: a `SKILL_GAP` correction for cache tag invalidation
- `B02`: a `CONFABULATION` correction for `\Drupal::entityManager()`
- `B03`: an `ASSUMPTION_ERROR` correction for controller service usage

These cases check that the agent follows the skill by producing a correction
entry, naming the right classification, recording a `reasoning_failure`, and
proposing remediation tied to the classification.

## From correction to eval case

Start with a correction entry:

```json
{
  "timestamp": "2026-04-06T14:23:00Z",
  "subsystem": "caching",
  "classification": "SKILL_GAP",
  "claimed": "Cache tags are invalidated synchronously",
  "corrected": "Invalidation is two-phase: checksum update is immediate, item eviction is lazy on next read",
  "reasoning_failure": "Assumed invalidation means deletion because most cache systems work that way",
  "confidence": 0.7,
  "project_id": "a1b2c3d4e5f6",
  "eval_seed": {
    "prompt": "Explain how Drupal cache tag invalidation works. Be specific about when stale items are removed.",
    "must_contain_any": ["lazy", "next read", "checksum"],
    "must_not_contain": ["synchronously deleted", "eagerly deletes"]
  }
}
```

Promote it with the helper:

```python
from evals.promote_correction import promote_correction

entry = {
    "timestamp": "2026-04-06T14:23:00Z",
    "subsystem": "caching",
    "classification": "SKILL_GAP",
    "claimed": "Cache tags are invalidated synchronously",
    "corrected": "Invalidation is two-phase: checksum update is immediate, item eviction is lazy on next read",
    "reasoning_failure": "Assumed invalidation means deletion because most cache systems work that way",
    "confidence": 0.7,
    "project_id": "a1b2c3d4e5f6",
    "eval_seed": {
        "prompt": "Explain how Drupal cache tag invalidation works. Be specific about when stale items are removed.",
        "must_contain_any": ["lazy", "next read", "checksum"],
        "must_not_contain": ["synchronously deleted", "eagerly deletes"]
    }
}

question, rubric = promote_correction(entry, case_id="B04", skill="drupal-caching")
```

Pass the target `skill` (drives the bundle + rubric id) and the next free
`case_id` (the repo uses string IDs like `B01`, `B02`; an int is rendered as
`B0N`). Append `question` to `evals/<skill>/dataset.yaml` (`questions[]`) and
`rubric` to `evals/<skill>/rubrics.yaml` (`rubrics[]`) — they are linked by
`question.rubric_ref`.

Resulting `(question, rubric)` pair (Eval Commons model):

```python
# question -> dataset.yaml questions[]
{
    "id": "B04",
    "input": "Explain how Drupal cache tag invalidation works. Be specific about when stale items are removed.",
    "criteria": "Invalidation is two-phase: checksum update is immediate, item eviction is lazy on next read",
    "bundle": "drupal_caching",
    "rubric_ref": "rubric/drupal_caching_b04@1.0",
    "metadata": {"expectations": ["Invalidation is two-phase: checksum update is immediate, item eviction is lazy on next read"]},
}
# rubric -> rubrics.yaml rubrics[]
{
    "id": "drupal_caching_b04",
    "version": "1.0",
    "applicable_bundles": ["drupal_caching"],
    "checks": [
        {"kind": "must_contain_any", "values": ["lazy", "next read", "checksum"]},
        {"kind": "must_not_contain", "values": ["synchronously deleted", "eagerly deletes"]},
    ],
    "scoring": {"combine": "all_pass"},
}
```

Verify the fix:

```bash
python3 evals/compare.py --skill capturing-expert-corrections --no-baseline --runs 1
```

## Why eval_seed

`eval_seed` lets a known correction carry the minimum data needed to become an
eval case without separate manual authoring. It also keeps the bridge between
the correction log and the skill's `dataset.yaml` / `rubrics.yaml` consistent.

## Why confidence and project_id

These fields follow the `continuous-learning-v2` pattern for handling repeat
corrections and scoping them per project. `confidence` accumulates on repeated
sightings, and `project_id` ties a correction to a portable git-remote-based
project identity.
