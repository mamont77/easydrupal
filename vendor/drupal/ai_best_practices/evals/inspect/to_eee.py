#!/usr/bin/env python3
"""Convert an Inspect ``.eval`` log to an Every Eval Ever (EEE) envelope (#3588916).

Thin wrapper over the upstream ``every-eval-ever[inspect]`` converter — that
package is the source of truth for the EEE v0.2.2 envelope shape. The bespoke
``convert_to_envelope.py`` this used to ship is retired; we adopt upstream and
only adjust the few fields it cannot infer from an ``abp_cli/*`` model path:

  1. Stamp abp's source metadata (organization + first-party relationship).
  2. Normalize ``model_info.developer`` / ``inference_platform``. The upstream
     converter leaves both ``"unknown"`` because the ``abp_cli`` provider prefix
     is not in its provider map; we fill them from the abp provider.
  3. Flatten EEE's ``<dataset>/<developer>/<model>/<uuid>.json`` directory tree
     to a single ``result.json``. EEE's instance-level rows are not dropped —
     they keep their own ``<stem>_samples.jsonl`` beside ``result.json``, and
     the envelope's ``detailed_evaluation_results.file_path`` is rewritten to
     that flat sibling (pass ``--no-samples`` to emit a summary-only envelope).
  4. Split a control-task log into one result per arm (with / without skill);
     the converter would report one accuracy over both.
  5. Name the platform for ``openai-api/<service>/<model>`` runs, and accept
     ``--model-developer`` / ``--model-id`` / ``--inference-platform`` when the
     spec cannot say who made the model.

We call EEE's adapter API directly (``InspectAIAdapter.transform_from_file``) —
the same entry point its own CLI uses — rather than shelling out, so there is no
temp tree to hunt. The EEE version is pinned in requirements-eee.txt, so this
internal import path is stable for the version we ship against.

``every-eval-ever[inspect]`` is a behavioral-only extra; this module is never
imported on the pip-free ``--static`` path.
"""
from __future__ import annotations

import argparse
import json
import math
import shutil
import sys
import tempfile
import uuid
from pathlib import Path

# Published in the envelope's source_metadata.source_organization_name (the org
# that RAN the eval). Sensible default; override with --organization.
ABP_ORG_NAME = "Drupal ai_best_practices"
ABP_ORG_URL = "https://www.drupal.org/project/ai_best_practices"

# abp provider (the segment after ``abp_cli/``) -> (developer, inference_platform).
# These runners go through subscription CLIs, not metered vendor APIs (except
# mistral); the platform string records that provenance honestly rather than
# claiming a bare metered-API run. Keep in step with providers.PROVIDERS.
ABP_PROVIDER_INFO = {
    "claude": ("anthropic", "anthropic (claude subscription CLI)"),
    "codex": ("openai", "openai (codex subscription CLI)"),
    "gemini": ("google", "google (gemini subscription CLI)"),
    "mistral": ("mistralai", "mistral API"),
    # --model can name any vendor's model on Kiro (or "auto"), so the developer stays unknown.
    "kiro": ("unknown", "amazon (kiro CLI, credit-billed)"),
}


def _abp_provider(model_name: str) -> str | None:
    """Pull the abp provider out of an ``abp_cli/<provider>[/<model>]`` name."""
    parts = (model_name or "").split("/")
    if len(parts) >= 2 and parts[0] == "abp_cli":
        return parts[1]
    return None


def normalize_model_info(model_info, model_spec: str | None = None,
                         overrides: dict | None = None) -> None:
    """Fill developer / inference_platform from the model spec, in place.

    ``abp_cli/<provider>``: from ABP_PROVIDER_INFO; ``name`` and ``id`` stay the
    faithful spec. We do NOT synthesize a precise model id — abp's ``claude``
    default does not pin a specific model, so inventing one would be a
    provenance lie.

    ``openai-api/<service>/<model>`` (Inspect's OpenAI-compatible provider): the
    upstream converter drops the service and reports the developer as
    ``openai-api``. The service is the inference platform; the developer cannot
    be known from the spec (a gateway may serve anyone's model), so it is
    ``unknown`` unless ``overrides`` says otherwise.

    ``overrides`` (developer / id / inference_platform) are applied last: the
    operator knows what the gateway served when the spec cannot say.
    """
    provider = _abp_provider(model_info.name)
    info = ABP_PROVIDER_INFO.get(provider)
    if info:
        model_info.developer, model_info.inference_platform = info
    parts = (model_spec or "").split("/")
    if len(parts) >= 3 and parts[0] == "openai-api":
        model_info.name = model_info.id = model_spec
        model_info.developer = "unknown"
        model_info.inference_platform = f"{parts[1]} (OpenAI-compatible API)"
    for key, value in (overrides or {}).items():
        if value:
            setattr(model_info, key, value)


# The control task (tasks.py) runs every case twice: metadata["arm"] "on" with
# the skill, "off" without it. One accuracy over both arms describes neither, so
# a control log gets one evaluation result per arm.
ARM_LABELS = {"on": "with skill", "off": "without skill"}


def _arm_outcomes(log) -> dict[str, list[tuple[str, bool]]]:
    """(sample id, passed) per arm, one entry per graded run (epochs included).

    Errored or unscored samples are left out, as Inspect's accuracy leaves them
    out. An empty dict means the log has no arms (not a control run).
    """
    from inspect_ai.scorer import CORRECT

    outcomes: dict[str, list[tuple[str, bool]]] = {}
    for s in log.samples or []:
        arm = (s.metadata or {}).get("arm")
        if arm not in ARM_LABELS or s.error is not None or not s.scores:
            continue
        passed = next(iter(s.scores.values())).value == CORRECT
        outcomes.setdefault(arm, []).append((str(s.id), passed))
    return outcomes


def split_control_arms(eee_log, outcomes: dict[str, list[tuple[str, bool]]]) -> None:
    """Replace the single mixed accuracy with one result per arm, in place.

    Each arm result is a copy of the converter's own result (same metric and
    generation config) with that arm's samples, accuracy, standard error
    (sqrt(p(1-p)/n) over runs, as paired_delta.py reports it) and run count.
    """
    if not outcomes or len(eee_log.evaluation_results) != 1:
        return
    base = eee_log.evaluation_results[0]
    results = []
    for arm in ("on", "off"):
        rows = outcomes.get(arm)
        if not rows:
            continue
        r = base.model_copy(deep=True)
        n = len(rows)
        passed = sum(p for _, p in rows)
        acc = passed / n
        label = ARM_LABELS[arm]
        r.evaluation_name = f"{base.evaluation_name} ({label})"
        r.evaluation_result_id = f"{eee_log.evaluation_id}/{arm}"
        ids = sorted({sid for sid, _ in rows})
        r.source_data.sample_ids = ids
        r.source_data.samples_number = len(ids)
        r.source_data.additional_details = {
            **(r.source_data.additional_details or {}), "arm": arm, "condition": label}
        r.score_details.score = acc
        r.score_details.details = {
            **(r.score_details.details or {}), "passed_runs": str(passed), "runs": str(n)}
        if r.score_details.uncertainty is not None:
            r.score_details.uncertainty.num_samples = n
            if r.score_details.uncertainty.standard_error is not None:
                r.score_details.uncertainty.standard_error.value = math.sqrt(acc * (1 - acc) / n)
        results.append(r)
    eee_log.evaluation_results = results


def _sanitize_latency(log_path: Path, tmpdir: str) -> Path:
    """Clamp samples whose ``working_time`` exceeds ``total_time``.

    EEE derives ``latency_ms = (total_time - working_time) * 1000`` into a
    ``ge=0`` field, so an inverted pair makes pydantic reject the sample and
    abort the WHOLE conversion. Real runs should not invert these (working_time
    excludes retries/sleeps), but clock granularity — or a synthetic/instant run
    — can produce a tiny negative. Clamp it rather than let one sample kill the
    export. Returns the original path when nothing needed fixing (no rewrite),
    else a sanitized copy written under ``tmpdir``.
    """
    from inspect_ai.log import read_eval_log, write_eval_log

    log = read_eval_log(str(log_path))
    inverted = 0
    for s in log.samples or []:
        if s.total_time is not None and s.working_time is not None \
                and s.total_time < s.working_time:
            s.total_time = s.working_time
            inverted += 1
    if not inverted:
        return Path(log_path)
    fixed = Path(tmpdir) / "sanitized.eval"
    write_eval_log(log, str(fixed))
    return fixed


def to_eee(
    log_path: str | Path,
    out_path: str | Path,
    *,
    organization: str = ABP_ORG_NAME,
    organization_url: str | None = ABP_ORG_URL,
    relationship: str = "first_party",
    eval_library_version: str | None = None,
    include_samples: bool = True,
    model_overrides: dict | None = None,
) -> Path:
    """Convert one Inspect ``.eval`` log to a flat EEE envelope ``result.json``.

    With ``include_samples`` (default) the instance-level rows are written to
    ``<out_stem>_samples.jsonl`` beside the envelope and referenced from it;
    otherwise a summary-only envelope is produced. A control-task log gets one
    result per arm (with / without skill). ``model_overrides`` sets
    developer / id / inference_platform when the spec cannot say. Returns the
    envelope path. Raises if ``every-eval-ever[inspect]`` is not installed
    (behavioral-only extra) or the log cannot be parsed.
    """
    from every_eval_ever.converters.inspect.adapter import InspectAIAdapter
    from inspect_ai.log import read_eval_log

    log_path = Path(log_path)
    out_path = Path(out_path)
    out_path.parent.mkdir(parents=True, exist_ok=True)

    # EEE always writes the instance-level jsonl into a
    # <parent>/<dataset>/<dev>/<model>/ tree. Point it at a throwaway temp dir,
    # then relocate the single produced file flat beside the envelope so callers
    # get two predictable artifacts, not a tree. Empty parent dir => the adapter
    # skips the jsonl entirely (summary-only).
    with tempfile.TemporaryDirectory() as eee_tmp:
        metadata = {
            "source_organization_name": organization,
            "evaluator_relationship": relationship,
            "source_organization_url": organization_url,
            "source_organization_logo_url": None,
            "eval_library_name": "inspect_ai",
            "eval_library_version": eval_library_version,
            "parent_eval_output_dir": eee_tmp if include_samples else "",
            "file_uuid": str(uuid.uuid4()),
        }
        conv_path = _sanitize_latency(log_path, eee_tmp)
        source = read_eval_log(str(conv_path))
        log = InspectAIAdapter().transform_from_file(conv_path, metadata)
        normalize_model_info(log.model_info, source.eval.model, model_overrides)
        split_control_arms(log, _arm_outcomes(source))

        der = log.detailed_evaluation_results
        if include_samples and der is not None and der.file_path:
            samples_out = out_path.with_name(f"{out_path.stem}_samples.jsonl")
            shutil.move(str(der.file_path), str(samples_out))
            # Reference the flat sibling by name; checksum/total_rows are
            # content-derived, so the move leaves them valid.
            der.file_path = samples_out.name

        payload = log.model_dump(mode="json", exclude_none=True)
        out_path.write_text(json.dumps(payload, indent=2), encoding="utf-8")
    return out_path


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    p.add_argument("log_path", help="path to an Inspect .eval log")
    p.add_argument("-o", "--out", default="result.json",
                   help="output envelope path (default: result.json)")
    p.add_argument("--organization", default=ABP_ORG_NAME)
    p.add_argument("--relationship", default="first_party",
                   choices=["first_party", "third_party", "collaborative", "other"])
    p.add_argument("--eval-library-version", default=None,
                   help="inspect_ai version to record in eval_library.version")
    p.add_argument("--no-samples", dest="include_samples", action="store_false",
                   help="emit a summary-only envelope (no instance-level jsonl)")
    p.add_argument("--model-developer", default=None,
                   help="model_info.developer when the spec cannot say (e.g. a gateway)")
    p.add_argument("--model-id", default=None,
                   help="model_info.id: the exact model the gateway served")
    p.add_argument("--inference-platform", default=None,
                   help="model_info.inference_platform, e.g. 'amazee.ai (AWS Bedrock)'")
    args = p.parse_args(argv)

    out = to_eee(
        args.log_path,
        args.out,
        organization=args.organization,
        relationship=args.relationship,
        eval_library_version=args.eval_library_version,
        include_samples=args.include_samples,
        model_overrides={"developer": args.model_developer, "id": args.model_id,
                         "inference_platform": args.inference_platform},
    )
    print(out)
    return 0


if __name__ == "__main__":
    sys.exit(main())
