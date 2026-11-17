#!/usr/bin/env python3
"""End-to-end test for the EEE wrapper (to_eee.py), token-free (#3588916, task 7).

Self-contained: injects a fake runner into providers.PROVIDERS, runs a real
Inspect eval to a temp .eval log (no API calls), converts it with to_eee(), and
asserts the envelope is clean — flat result.json + result_samples.jsonl, no
doubled model prefix, abp developer / inference_platform filled. With jsonschema
present the envelope is validated against EEE's bundled schema.

Requires inspect_ai + every-eval-ever[inspect] (behavioral extras); SKIPs
cleanly without them. Run with the behavioral venv.
"""
from __future__ import annotations

import json
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

try:
    from inspect_ai import eval as inspect_eval
    from inspect_ai.log import read_eval_log, write_eval_log
    import every_eval_ever  # noqa: F401  ensure the extra is present
except ImportError as e:
    print(f"SKIP test_to_eee: behavioral extra not installed ({e})")
    raise SystemExit(0)

import provider  # noqa: E402  registers @modelapi("abp_cli")
import providers  # noqa: E402
from tasks import abp  # noqa: E402
import to_eee  # noqa: E402

failures: list[str] = []


def check(name: str, cond: bool) -> None:
    if not cond:
        failures.append(name)


# The abp_cli model binds its runner once and Inspect reuses the model across
# evals, so later tests change the answer through this hook, not the registry.
RESPOND = None


def fake_runner(prompt, model=None, cwd=None):
    # Content is irrelevant to envelope shape; mention an MR so the grader has
    # something to bite on. No sleep => instant, hence the timing clamp below.
    return {
        "response": RESPOND(prompt) if RESPOND else
        "Open a merge request (MR) on the issue fork with glab.",
        "elapsed": 0.5, "exit_code": 0,
        "input_tokens": 10, "cache_creation_tokens": 0,
        "cache_read_tokens": 0, "output_tokens": 8, "cost_usd": 0.0,
    }


providers.PROVIDERS["claude"] = fake_runner

with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    logs = inspect_eval(
        abp(skill="drupal-gitlab"),
        model="abp_cli/claude",
        log_dir=str(tmp / "logs"),
        log_format="eval",
        display="none",
    )
    run = logs[0]
    check("eval/succeeded", run.status == "success")
    # Provider fix: output.model is the BARE name, so the Inspect log is not
    # doubled. (Regression-guarded in test_provider.py too.)
    check("eval/output-model-bare", run.samples[0].output.model == "claude")

    eval_file = next((tmp / "logs").glob("*.eval"))

    # Regression guard for the latency clamp: deliberately INVERT one sample's
    # timing (working_time > total_time) so EEE would compute a negative
    # latency_ms and reject it. to_eee()'s _sanitize_latency must absorb this and
    # still produce a valid envelope — i.e. the defense lives in to_eee, not here.
    src = read_eval_log(str(eval_file))
    assert src.samples, "expected at least one sample to invert"
    s0 = src.samples[0]
    s0.total_time = (s0.total_time or 0.0)
    s0.working_time = s0.total_time + 1.0  # guaranteed inversion
    write_eval_log(src, str(eval_file))

    out = to_eee.to_eee(eval_file, tmp / "result.json")

    # 1. Flat output: result.json + result_samples.jsonl, no <dataset>/<dev>/...
    #    directory tree (only the logs/ .eval, envelope, and samples sibling).
    check("eee/single-file", out.name == "result.json" and out.exists())
    samples = out.with_name("result_samples.jsonl")
    check("eee/samples-sibling", samples.exists())
    stray = [p.relative_to(tmp) for p in tmp.rglob("*")
             if p.is_file() and p.suffix == ".json" and p != out]
    check("eee/no-json-tree", stray == [])

    env = json.loads(out.read_text())
    der = env.get("detailed_evaluation_results") or {}
    # Envelope references the flat sibling by name (not an absolute tree path).
    check("eee/samples-ref", der.get("file_path") == "result_samples.jsonl")
    check("eee/samples-rows", der.get("total_rows", 0) > 0)

    mi = env["model_info"]
    # 2. No doubled provider prefix (the bug this whole task chased).
    check("eee/model-name-clean", mi["name"] == "abp_cli/claude")
    check("eee/model-id-clean", "abp_cli/abp_cli" not in mi["id"])
    # 3. developer / inference_platform normalized, not "unknown".
    check("eee/developer", mi["developer"] == "anthropic")
    check("eee/platform", "subscription CLI" in mi["inference_platform"])
    # source metadata stamped.
    check("eee/org", env["source_metadata"]["source_organization_name"] == to_eee.ABP_ORG_NAME)
    check("eee/relationship", env["source_metadata"]["evaluator_relationship"] == "first_party")
    check("eee/schema-version", env.get("schema_version") == "0.2.2")

    # 4. summary-only mode: no jsonl, no detailed_evaluation_results.
    out2 = to_eee.to_eee(eval_file, tmp / "summary.json", include_samples=False)
    env2 = json.loads(out2.read_text())
    check("eee/summary-no-samples", env2.get("detailed_evaluation_results") is None)
    check("eee/summary-no-jsonl", not out2.with_name("summary_samples.jsonl").exists())

    # 5. Validate both envelopes against upstream EEE's bundled schema.
    try:
        import jsonschema
        schema_path = Path(every_eval_ever.__file__).parent / "schemas" / "eval.schema.json"
        if schema_path.exists():
            schema = json.loads(schema_path.read_text())
            jsonschema.validate(env, schema)
            jsonschema.validate(env2, schema)
            check("eee/schema-valid", True)
        else:
            print("NOTE: EEE bundled schema not found; skipping schema validation")
    except ImportError:
        print("NOTE: jsonschema not installed; skipping schema validation")
    except Exception as e:  # noqa: BLE001
        failures.append(f"eee/schema-valid ({e})")

# --- control task: one EEE result per arm, not one accuracy over both ---
from types import SimpleNamespace  # noqa: E402
from tasks import control  # noqa: E402
from inspect_ai.scorer import CORRECT  # noqa: E402


def arm_answer(prompt):
    # With the skill (the prompt carries SKILL.md) answer what passes gitlab
    # B07; without it, nothing that passes. The arms must differ.
    return ("Not all projects have migrated to work items; check the legacy queue."
            if len(prompt) > 2000 else "I don't know.")


RESPOND = arm_answer
with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    logs = inspect_eval(control(skill="drupal-gitlab"), model="abp_cli/claude",
                        epochs=2, log_dir=str(tmp / "logs"), log_format="eval",
                        display="none")
    check("control/succeeded", logs[0].status == "success")
    eval_file = next((tmp / "logs").glob("*.eval"))

    # Independent count from the log itself.
    expected = {}
    for s in read_eval_log(str(eval_file)).samples:
        arm = s.metadata["arm"]
        ok = next(iter(s.scores.values())).value == CORRECT
        n, p = expected.get(arm, (0, 0))
        expected[arm] = (n + 1, p + ok)

    env = json.loads(to_eee.to_eee(eval_file, tmp / "control.json").read_text())
    results = env["evaluation_results"]
    arms = {r["source_data"]["additional_details"].get("arm"): r for r in results}
    check("control/two-results", len(results) == 2 and set(arms) == {"on", "off"})
    for arm, (n, p) in expected.items():
        r = arms.get(arm, {})
        sd = r.get("score_details", {})
        check(f"control/{arm}-score", abs(sd.get("score", -1) - p / n) < 1e-9)
        check(f"control/{arm}-runs", sd.get("uncertainty", {}).get("num_samples") == n)
        check(f"control/{arm}-ids-own-arm",
              all(i.endswith(f"::{arm}") for i in r.get("source_data", {}).get("sample_ids", [])))
        check(f"control/{arm}-named", to_eee.ARM_LABELS[arm] in r.get("evaluation_name", ""))
    check("control/arms-differ", expected["on"][1] / expected["on"][0]
          != expected["off"][1] / expected["off"][0])
    try:
        import jsonschema
        schema_path = Path(every_eval_ever.__file__).parent / "schemas" / "eval.schema.json"
        if schema_path.exists():
            jsonschema.validate(env, json.loads(schema_path.read_text()))
    except ImportError:
        pass
    except Exception as e:  # noqa: BLE001
        failures.append(f"control/schema-valid ({e})")

# --- model_info for Inspect's OpenAI-compatible provider, and overrides ---
mi = SimpleNamespace(name="openai-api/claude-3-haiku", id="openai-api/claude-3-haiku",
                     developer="openai-api", inference_platform="openai-api")
to_eee.normalize_model_info(mi, "openai-api/amazee/claude-3-haiku")
check("model/openai-api-name", mi.name == "openai-api/amazee/claude-3-haiku")
check("model/openai-api-developer-unknown", mi.developer == "unknown")
check("model/openai-api-platform", mi.inference_platform == "amazee (OpenAI-compatible API)")
to_eee.normalize_model_info(mi, "openai-api/amazee/claude-3-haiku",
                            {"developer": "anthropic", "id": None})
check("model/override-developer", mi.developer == "anthropic")
check("model/override-none-ignored", mi.id == "openai-api/amazee/claude-3-haiku")

if failures:
    print("FAIL:", ", ".join(failures))
    raise SystemExit(1)
print("OK — to_eee: flat envelope + samples, no double prefix, abp model_info filled")
