# Vendored Eval Commons schemas

These are **copies** of the Drupal Eval Commons Layer 1 JSON Schemas, vendored so
this repo can validate its `dataset.yaml` / `rubrics.yaml` files in CI without
depending on a sibling `ai_eval` checkout.

| File | Validates |
|------|-----------|
| `dataset.schema.json` | `evals/<skill>/dataset.yaml` (questions) |
| `rubric.schema.json`  | each rubric in `evals/<skill>/rubrics.yaml` |

**Canonical source:** `ai_eval/schema/` on git.drupalcode.org (project/ai_eval),
the `$id` in each file. Both are self-contained — every `$ref` is internal
(`#/$defs/...`), so they validate offline with no cross-file resolution.

Provenance: both files are byte-identical copies of ai_eval tag `1.1.0-alpha1`
(`05c9dc9`), re-vendored 2026-10-20. The tag holds the schema sync from ai_eval
MR !97 (#3594805):
- each `$id` names the release tag (the old `raw/1.0.x/` URLs return 404);
- `dataset.schema.json` adds the optional `caller_uid`, `user_name`,
  `expected.items` and `metadata.inputs`;
- in `rubric.schema.json`, `judges.model_id` is optional (omit it to use the
  site judge model).
All changes are additive or loosen a rule, so existing datasets and rubrics stay valid.

**Keep in sync:** if the upstream L1 schemas change, re-copy them here and re-run
`python3 evals/test_scoring.py`. This is a pragmatic mirror, not a fork; the
canonical definition stays in ai_eval.
