#!/usr/bin/env python3
"""A/B comparison of skill effectiveness.

Two modes:
  1. Editing a skill (default): compares the old version against your changes.
     Save the old version first, then edit the skill file and run compare.
  2. Proving a skill helps: compares no-skill baseline against the skill.
     Use --no-baseline to run this mode.

Both configs use identical flags — the only variable is which skill content
is prepended to the prompt. The --setting-sources "" flag ensures no user
settings, plugins, or CLAUDE.md files contaminate the run.

Usage:
    # Editing workflow: compare old version vs your changes
    cp skills/drupal-automated-testing/SKILL.md /tmp/old-skill.md
    # ... edit skills/drupal-automated-testing/SKILL.md ...
    python3 evals/compare.py --skill drupal-automated-testing --baseline /tmp/old-skill.md --runs 3

    # Proving a skill helps: no-skill baseline vs skill
    python3 evals/compare.py --skill drupal-automated-testing --no-baseline --runs 3

    # Test on a different model
    python3 evals/compare.py --skill drupal-writing-documentation --no-baseline --model haiku

    # Compare multiple models in one run
    python3 evals/compare.py --skill drupal-automated-testing --no-baseline --models sonnet haiku --runs 3

    # Run against a different provider
    python3 evals/compare.py --skill drupal-automated-testing --no-baseline --provider codex --runs 1

    # Provider-specific model selection
    python3 evals/compare.py --skill drupal-automated-testing --no-baseline --provider gemini --model gemini-2.5-flash

    # Dry run
    python3 evals/compare.py --skill drupal-automated-testing --dry-run
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import time
from pathlib import Path

from providers import ISOLATION_MODES, PROVIDER_DEFAULTS, PROVIDERS
# Shared scoring + case loading (single source of truth with run-evals.py).
import scoring

REPO_ROOT = Path(__file__).resolve().parent.parent
EVALS_DIR = REPO_ROOT / "evals"
SKILLS_DIR = REPO_ROOT / "skills"


def load_evals(skill_name: str) -> list[dict]:
    if not scoring.has_dataset(skill_name):
        print(f"Error: evals/{skill_name}/dataset.yaml not found", file=sys.stderr)
        sys.exit(1)
    return scoring.load_cases(skill_name)


def load_skill(skill_name: str) -> str:
    # Flat file: skills/{name}.md
    flat = SKILLS_DIR / f"{skill_name}.md"
    if flat.exists():
        with open(flat) as f:
            return f.read()
    # Directory layout: skills/{name}/SKILL.md
    skill_dir = SKILLS_DIR / skill_name
    skill_file = skill_dir / "SKILL.md"
    if skill_file.exists():
        with open(skill_file) as f:
            return f.read()
    print(f"Error: skill not found as {flat} or {skill_file}", file=sys.stderr)
    sys.exit(1)


def run_config(cases: list[dict], skill_content: str | None,
               model: str, provider: str, config_name: str, run_id: int,
               output_dir: Path | None = None,
               cwd: str | None = None) -> list[dict]:
    """Run all cases for one config, one run."""
    results = []

    for case in cases:
        cid = case["id"]
        prompt = case["prompt"]

        if skill_content:
            full_prompt = f"{skill_content}\n\n---\n\n{prompt}"
        else:
            full_prompt = prompt

        result = PROVIDERS[provider](full_prompt, model, cwd=cwd)
        response = result["response"]

        if result["exit_code"] != 0 or not response:
            passed = False
            detail = f"exit={result['exit_code']}, empty={not response}"
        else:
            passed, detail, _ = scoring.grade(
                response, case["checks"], case.get("combine", "all_pass"))

        case_result = {
            "case_id": cid,
            "config": config_name,
            "run": run_id,
            "passed": passed,
            "elapsed": result["elapsed"],
            "detail": detail,
            "response_chars": len(response),
            "input_tokens": result["input_tokens"],
            "cache_creation_tokens": result["cache_creation_tokens"],
            "cache_read_tokens": result["cache_read_tokens"],
            "output_tokens": result["output_tokens"],
            "cost_usd": result["cost_usd"],
        }
        results.append(case_result)

        status = "PASS" if case_result["passed"] else "FAIL"
        cost_str = f"${case_result['cost_usd']:.4f}" if case_result["cost_usd"] else "$?.????"
        cache_str = ""
        if case_result["cache_read_tokens"]:
            cache_str = f", cache:{case_result['cache_read_tokens']}"
        tokens_str = f"{case_result['input_tokens']}in/{case_result['output_tokens']}out{cache_str}"
        print(f"  [{status}] {config_name}/run{run_id}/{cid} ({result['elapsed']}s, {tokens_str}, {cost_str})")

        if output_dir:
            trace_dir = output_dir / config_name / f"run{run_id}"
            trace_dir.mkdir(parents=True, exist_ok=True)
            trace = {**case_result, "prompt": prompt, "response": response[:2000],
                 "response_truncated": len(response) > 2000}
            with open(trace_dir / f"{cid}.json", "w") as f:
                json.dump(trace, f, indent=2)

    return results


def compute_stats(results: list[dict], config_name: str, n_runs: int) -> dict:
    """Aggregate pass rates, tokens, and cost across runs."""
    case_ids = sorted(set(r["case_id"] for r in results))
    per_case = {}
    for cid in case_ids:
        case_results = [r for r in results if r["case_id"] == cid]
        passes = sum(1 for r in case_results if r["passed"])
        per_case[cid] = {
            "passes": passes,
            "runs": len(case_results),
            "pass_rate": passes / len(case_results) if case_results else 0,
        }

    total_passes = sum(1 for r in results if r["passed"])
    total_results = len(results)
    avg_elapsed = sum(r["elapsed"] for r in results) / total_results if total_results else 0
    total_input_tokens = sum(r.get("input_tokens", 0) for r in results)
    total_cache_creation = sum(r.get("cache_creation_tokens", 0) for r in results)
    total_cache_read = sum(r.get("cache_read_tokens", 0) for r in results)
    total_output_tokens = sum(r.get("output_tokens", 0) for r in results)
    total_cost = sum(r.get("cost_usd", 0) for r in results)

    return {
        "config": config_name,
        "n_runs": n_runs,
        "n_cases": len(case_ids),
        "total_results": total_results,
        "total_pass_rate": total_passes / total_results if total_results else 0,
        "total_passes": total_passes,
        "avg_elapsed": round(avg_elapsed, 1),
        "total_input_tokens": total_input_tokens,
        "total_cache_creation_tokens": total_cache_creation,
        "total_cache_read_tokens": total_cache_read,
        "total_output_tokens": total_output_tokens,
        "avg_input_tokens": round(total_input_tokens / total_results) if total_results else 0,
        "avg_output_tokens": round(total_output_tokens / total_results) if total_results else 0,
        "total_cost_usd": round(total_cost, 4),
        "avg_cost_usd": round(total_cost / total_results, 4) if total_results else 0,
        "per_case": per_case,
    }


def print_comparison(baseline_stats: dict, treatment_stats: dict,
                     skill_name: str, model: str):
    """Print the comparison table."""
    print(f"\n{'=' * 70}")
    print(f"  COMPARISON: {skill_name} | model: {model}")
    print(f"{'=' * 70}\n")

    case_ids = sorted(set(
        list(baseline_stats["per_case"].keys())
        + list(treatment_stats["per_case"].keys())
    ))

    b_label = baseline_stats["config"][:14]
    t_label = treatment_stats["config"][:14]

    print(f"{'Case':<8} {b_label:>14} {t_label:>14} {'Delta':>10}")
    print(f"{'-' * 8} {'-' * 14} {'-' * 14} {'-' * 10}")

    for cid in case_ids:
        b = baseline_stats["per_case"].get(cid, {})
        t = treatment_stats["per_case"].get(cid, {})
        b_rate = b.get("pass_rate", 0)
        t_rate = t.get("pass_rate", 0)
        delta = t_rate - b_rate
        delta_str = f"{delta:+.0%}" if delta != 0 else "="

        b_str = f"{b.get('passes', 0)}/{b.get('runs', 0)} ({b_rate:.0%})"
        t_str = f"{t.get('passes', 0)}/{t.get('runs', 0)} ({t_rate:.0%})"
        print(f"{cid:<8} {b_str:>14} {t_str:>14} {delta_str:>10}")

    b_total = baseline_stats["total_pass_rate"]
    t_total = treatment_stats["total_pass_rate"]
    total_delta = t_total - b_total

    print(f"{'-' * 8} {'-' * 14} {'-' * 14} {'-' * 10}")
    print(f"{'TOTAL':<8} {b_total:>13.0%}  {t_total:>13.0%}  {total_delta:>+9.0%}")
    print(f"{'Avg time':<8} {baseline_stats['avg_elapsed']:>12.1f}s  {treatment_stats['avg_elapsed']:>12.1f}s")
    print()

    print(f"  {'':>20} {b_label:>14} {t_label:>14} {'Delta':>10}")
    print(f"  {'Avg input tokens':>20} {baseline_stats['avg_input_tokens']:>14,} {treatment_stats['avg_input_tokens']:>14,} {treatment_stats['avg_input_tokens'] - baseline_stats['avg_input_tokens']:>+10,}")
    print(f"  {'Avg output tokens':>20} {baseline_stats['avg_output_tokens']:>14,} {treatment_stats['avg_output_tokens']:>14,} {treatment_stats['avg_output_tokens'] - baseline_stats['avg_output_tokens']:>+10,}")
    b_cache = baseline_stats.get("total_cache_read_tokens", 0)
    t_cache = treatment_stats.get("total_cache_read_tokens", 0)
    if b_cache or t_cache:
        print(f"  {'Cache read tokens':>20} {b_cache:>14,} {t_cache:>14,} {t_cache - b_cache:>+10,}")
    print(f"  {'Total cost':>20} ${baseline_stats['total_cost_usd']:>13.4f} ${treatment_stats['total_cost_usd']:>13.4f} ${treatment_stats['total_cost_usd'] - baseline_stats['total_cost_usd']:>+9.4f}")
    print(f"  {'Avg cost/question':>20} ${baseline_stats['avg_cost_usd']:>13.4f} ${treatment_stats['avg_cost_usd']:>13.4f} ${treatment_stats['avg_cost_usd'] - baseline_stats['avg_cost_usd']:>+9.4f}")
    print()


def print_cross_model_summary(all_model_results: dict, skill_name: str):
    """Print a cross-model summary table comparing all models."""
    print(f"\n{'=' * 70}")
    print(f"  CROSS-MODEL SUMMARY: {skill_name}")
    print(f"{'=' * 70}\n")

    print(f"{'Model':<10} {'Baseline':>10} {'Treatment':>10} {'Delta':>8} {'Avg In':>8} {'Avg Out':>9} {'Avg Cost':>10}")
    print(f"{'-' * 10} {'-' * 10} {'-' * 10} {'-' * 8} {'-' * 8} {'-' * 9} {'-' * 10}")

    for model, mr in all_model_results.items():
        b = mr["baseline"]
        t = mr["treatment"]
        delta = mr["delta"]
        delta_str = f"{delta:+.0%}" if delta != 0 else "="

        print(
            f"{model:<10}"
            f" {b['total_pass_rate']:>9.0%} "
            f" {t['total_pass_rate']:>9.0%} "
            f" {delta_str:>8}"
            f" {t['avg_input_tokens']:>7,}"
            f" {t['avg_output_tokens']:>9,}"
            f" ${t['avg_cost_usd']:>8.4f}"
        )

    print()

    benefiting = [m for m, mr in all_model_results.items() if mr["delta"] > 0]
    neutral = [m for m, mr in all_model_results.items() if mr["delta"] == 0]
    regressing = [m for m, mr in all_model_results.items() if mr["delta"] < 0]

    if benefiting:
        print(f"  Skill helps:    {', '.join(benefiting)}")
    if neutral:
        print(f"  No effect:      {', '.join(neutral)}")
    if regressing:
        print(f"  Skill hurts:    {', '.join(regressing)}")
    print()


def main():
    parser = argparse.ArgumentParser(
        description="A/B comparison of skill effectiveness",
    )
    parser.add_argument("--skill", required=True,
                        help="Skill name to compare (e.g., drupal-automated-testing, drupal-writing-documentation)")
    parser.add_argument("--baseline", default=None,
                        help="Path to old version of the skill file (for editing workflow)")
    parser.add_argument("--no-baseline", action="store_true",
                        help="Compare no-skill vs skill (for proving a skill helps)")
    parser.add_argument("--runs", type=int, default=1,
                        help="Runs per config (default: 1, recommend: 3)")
    parser.add_argument("--provider", choices=list(PROVIDERS.keys()), default="claude",
                        help="Provider CLI to use (default: claude)")
    parser.add_argument("--model", default=None,
                        help="Model to test (default depends on provider)")
    parser.add_argument("--models", nargs="+", default=None,
                        help="Multiple models to compare (e.g., --models sonnet haiku opus)")
    parser.add_argument("--output-dir", default=None,
                        help="Save traces to this directory")
    parser.add_argument("--dry-run", action="store_true",
                        help="Show what would run")
    parser.add_argument("--cwd", default=None,
                        help="Working directory for provider CLI execution (default: repo root). "
                             "Use a temp dir to prevent the model from seeing eval files.")
    args = parser.parse_args()

    if args.models and args.model is not None:
        parser.error("--model and --models are mutually exclusive")
    if len(set(args.models or [])) != len(args.models or []):
        parser.error("--models contains duplicate model names")
    default_model = PROVIDER_DEFAULTS[args.provider]
    models = args.models if args.models else [args.model or default_model]

    cases = load_evals(args.skill)
    skill_content = load_skill(args.skill)
    output_dir = Path(args.output_dir) if args.output_dir else None

    # Determine baseline content.
    if args.no_baseline:
        # No-skill vs skill: proves the skill has value.
        baseline_content = None
        baseline_label = "no skill"
        treatment_label = "with skill"
    elif args.baseline:
        # Old version vs new version: measures editing impact.
        baseline_path = Path(args.baseline)
        if not baseline_path.exists():
            print(f"Error: baseline file not found: {baseline_path}", file=sys.stderr)
            sys.exit(1)
        with open(baseline_path) as f:
            baseline_content = f.read()
        baseline_label = f"old ({baseline_path.name})"
        treatment_label = "new (current)"
    else:
        # Default: use git to get the previous committed version.
        baseline_content = _git_baseline(args.skill)
        if baseline_content is None:
            print("No --baseline path provided and git baseline not available.")
            print("Use --baseline /path/to/old-skill.md or --no-baseline for no-skill comparison.")
            sys.exit(1)
        baseline_label = "old (git)"
        treatment_label = "new (working copy)"

    if args.dry_run:
        n_models = len(models)
        total = len(cases) * 2 * args.runs * n_models
        est_min = total * 30 / 60
        print(f"Would run {len(cases)} cases x 2 configs x {args.runs} runs x {n_models} model(s) = {total} invocations")
        print(f"Skill: {args.skill} | Provider: {args.provider} | Models: {', '.join(models)}")
        print(f"Comparison: {baseline_label} vs {treatment_label}")
        print(f"Estimated time: ~{est_min:.0f} min")
        print()
        for case in cases:
            cid = case["id"]
            lint = " [php-l]" if any(c["kind"] == "php_lint" for c in case["checks"]) else ""
            print(f"  {cid}: {case['prompt'][:70]}...{lint}")
        return

    # Resolve working directory for claude -p.
    run_cwd = args.cwd
    if run_cwd:
        run_cwd = str(Path(run_cwd).resolve())

    print(f"\nA/B Comparison: {args.skill}")
    print(f"Provider: {args.provider} | Models: {', '.join(models)} | Runs: {args.runs} | Cases: {len(cases)}")
    print(f"Comparison: {baseline_label} vs {treatment_label}")
    print(f"Isolation: {ISOLATION_MODES.get(args.provider, 'unknown')}")
    print(f"CWD: {run_cwd or 'fresh tempdir per call (provider default)'}\n")

    all_model_results = {}

    for model in models:
        if len(models) > 1:
            print(f"\n{'#' * 70}")
            print(f"  MODEL: {model}")
            print(f"{'#' * 70}")

        model_output_dir = output_dir / model if output_dir and len(models) > 1 else output_dir

        all_baseline = []
        all_treatment = []

        for run_id in range(1, args.runs + 1):
            print(f"\n--- Run {run_id}/{args.runs} ---\n")

            print(f"Config: {baseline_label}")
            all_baseline.extend(
                run_config(cases, baseline_content, model, args.provider,
                           "baseline", run_id, model_output_dir,
                           cwd=run_cwd))

            print(f"\nConfig: {treatment_label}")
            all_treatment.extend(
                run_config(cases, skill_content, model, args.provider,
                           "treatment", run_id, model_output_dir,
                           cwd=run_cwd))

        baseline_stats = compute_stats(all_baseline, baseline_label, args.runs)
        treatment_stats = compute_stats(all_treatment, treatment_label, args.runs)

        print_comparison(baseline_stats, treatment_stats, args.skill, model)

        all_model_results[model] = {
            "baseline": baseline_stats,
            "treatment": treatment_stats,
            "delta": round(
                treatment_stats["total_pass_rate"] - baseline_stats["total_pass_rate"], 4,
            ),
        }

    if len(models) > 1:
        print_cross_model_summary(all_model_results, args.skill)

    # Save results JSON.
    results_dir = EVALS_DIR / args.skill
    timestamp = time.strftime("%Y%m%d-%H%M%S")
    results_file = results_dir / f"comparison-{timestamp}.json"

    if len(models) == 1:
        # Single-model: backwards-compatible JSON format.
        model = models[0]
        mr = all_model_results[model]
        payload = {
            "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
            "skill": args.skill,
            "provider": args.provider,
            "model": model,
            "n_runs": args.runs,
            "comparison": f"{baseline_label} vs {treatment_label}",
            "isolation": ISOLATION_MODES.get(args.provider, "unknown"),
            "baseline": mr["baseline"],
            "treatment": mr["treatment"],
            "delta": mr["delta"],
        }
    else:
        # Multi-model: per-model results + summary.
        payload = {
            "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
            "skill": args.skill,
            "provider": args.provider,
            "models": models,
            "n_runs": args.runs,
            "comparison": f"{baseline_label} vs {treatment_label}",
            "isolation": ISOLATION_MODES.get(args.provider, "unknown"),
            "per_model": {
                m: {
                    "baseline": mr["baseline"],
                    "treatment": mr["treatment"],
                    "delta": mr["delta"],
                }
                for m, mr in all_model_results.items()
            },
            "summary": {
                m: {
                    "baseline_pass_rate": mr["baseline"]["total_pass_rate"],
                    "treatment_pass_rate": mr["treatment"]["total_pass_rate"],
                    "delta": mr["delta"],
                    "total_cost_usd": round(
                        mr["baseline"]["total_cost_usd"] + mr["treatment"]["total_cost_usd"], 4,
                    ),
                }
                for m, mr in all_model_results.items()
            },
        }

    with open(results_file, "w") as f:
        json.dump(payload, f, indent=2)
    print(f"Results saved to {results_file}")


def _git_baseline(skill_name: str) -> str | None:
    """Try to get the last committed version of the skill from git."""
    # Try flat file, then directory layout.
    candidates = [
        f"skills/{skill_name}.md",
        f"skills/{skill_name}/SKILL.md",
    ]
    for skill_path in candidates:
        try:
            proc = subprocess.run(
                ["git", "show", f"HEAD:{skill_path}"],
                capture_output=True, text=True, timeout=5,
                cwd=str(REPO_ROOT),
            )
            if proc.returncode == 0 and proc.stdout.strip():
                return proc.stdout
        except (FileNotFoundError, subprocess.TimeoutExpired):
            pass
    return None


if __name__ == "__main__":
    main()
