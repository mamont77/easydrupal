#!/usr/bin/env python3
"""Inspect AI task for abp behavioral evals (#3588916, task 3).

One parameterized ``@task`` (``abp``) renders a skill's Eval Commons
``dataset.yaml`` / ``rubrics.yaml`` into an Inspect ``Task``:

* each question -> a ``Sample`` (per-case checks + combine in ``Sample.metadata``);
* the skill's ``SKILL.md`` + ``references/*.md`` -> a system message;
* generation runs through the ``abp_cli`` provider (subscription CLIs);
* ``commons_scorer`` grades each sample by WRAPPING ``evals/scoring.py`` — the
  single source of truth the task-1 golden verifies.

A single parameterized task (not one function per skill) keeps it simple; pass
``-T skill=<name>`` to choose the skill.

Run (needs the behavioral extra + a live CLI; costs subscription tokens):

    inspect eval evals/inspect/tasks.py@abp -T skill=drupal-gitlab \
        --model abp_cli/claude
"""
from __future__ import annotations

import sys
from pathlib import Path

from inspect_ai import Task, task
from inspect_ai.dataset import MemoryDataset, Sample
from inspect_ai.model import ChatMessageSystem
from inspect_ai.scorer import (
    CORRECT,
    INCORRECT,
    Score,
    Target,
    accuracy,
    scorer,
    stderr,
)
from inspect_ai.solver import Generate, Solver, TaskState, generate, solver

import provider  # noqa: F401  importing registers @modelapi("abp_cli")

EVALS_DIR = Path(__file__).resolve().parent.parent
REPO_ROOT = EVALS_DIR.parent
sys.path.insert(0, str(EVALS_DIR))
import scoring  # noqa: E402  (loose-script import after sys.path insert)


def _skill_text(skill: str) -> str:
    """SKILL.md + any references/*.md, concatenated as system context.

    Raises when the skill file is missing rather than returning "": a silent
    empty context evaluates the BARE model while reporting the result as the
    skill's (a ~0 control delta reads as "the skill doesn't help"). The old
    runner fails loudly here too (run-evals.py: "Skill file missing").
    """
    skill_md = REPO_ROOT / scoring.skill_file_for(skill)
    if not skill_md.exists():
        raise FileNotFoundError(
            f"Skill file missing for {skill!r}: expected {skill_md}"
        )
    parts: list[str] = [skill_md.read_text()]
    refs = skill_md.parent / "references"
    if refs.is_dir():
        for r in sorted(refs.glob("*.md")):
            parts.append(f"\n\n--- reference: {r.name} ---\n\n{r.read_text()}")
    return "\n".join(parts)


def _samples(skill: str) -> list[Sample]:
    """Eval Commons questions -> Inspect Samples (disabled already filtered)."""
    return [
        Sample(
            id=case["id"],
            input=case["prompt"],
            target=case["criteria"] or "",
            metadata={"checks": case["checks"], "combine": case["combine"]},
        )
        for case in scoring.load_cases(skill)
    ]


def _control_samples(skill: str) -> list[Sample]:
    """Each case twice: arm ``on`` (skill injected) and ``off`` (no skill).

    The base case id + arm travel in ``metadata`` for pairing in paired_delta;
    the Sample ``id`` is suffixed per arm so the two never collide on (id, epoch).
    """
    samples: list[Sample] = []
    for case in scoring.load_cases(skill):
        base = {"checks": case["checks"], "combine": case["combine"], "case_id": case["id"]}
        for arm in ("on", "off"):
            samples.append(
                Sample(
                    id=f'{case["id"]}::{arm}',
                    input=case["prompt"],
                    target=case["criteria"] or "",
                    metadata={**base, "arm": arm},
                )
            )
    return samples


@solver
def skill_context(skill: str) -> Solver:
    """Prepend the skill's SKILL.md + references as a system message.

    Injected as a ChatMessageSystem rather than Inspect's ``system_message``
    solver on purpose: that solver runs ``str.format`` on the template, and
    skill docs contain literal ``{`` / ``}`` that would raise or corrupt.

    Arm-aware for the control task: a sample whose ``metadata["arm"]`` is
    ``"off"`` is the no-skill control and gets NO context. The ``abp`` task sets
    no arm (``None``), so it always injects — unchanged behaviour.
    """
    text = _skill_text(skill)

    async def solve(state: TaskState, generate: Generate) -> TaskState:
        if text and state.metadata.get("arm") != "off":
            state.messages.insert(0, ChatMessageSystem(content=text))
        return state

    return solve


@scorer(metrics=[accuracy(), stderr()])
def commons_scorer():
    """Grade a sample by wrapping scoring.grade — the abp single source.

    The verdict mapping (passed -> CORRECT/INCORRECT, detail -> explanation) is
    pinned by evals/inspect/test_scorer_parity.py against the golden, so this
    cannot silently diverge from the headline runner / control arm.
    """

    async def score(state: TaskState, target: Target) -> Score:
        checks = state.metadata["checks"]
        combine = state.metadata.get("combine", "all_pass")
        passed, detail, per_check = scoring.grade(
            state.output.completion, checks, combine)
        return Score(
            value=CORRECT if passed else INCORRECT,
            answer=state.output.completion,
            explanation=detail,
            metadata={"per_check": [
                {"kind": k, "passed": p, "detail": d} for k, p, d in per_check
            ]},
        )

    return score


@task
def abp(skill: str = "drupal-gitlab") -> Task:
    return Task(
        dataset=MemoryDataset(samples=_samples(skill), name=skill),
        solver=[skill_context(skill), generate()],
        scorer=commons_scorer(),
        name=f"abp-{skill}",
    )


@task
def control(skill: str = "drupal-gitlab") -> Task:
    """Control arm: every case run with the skill (arm ``on``) and without it
    (``off``). The headline signal is the PAIRED delta + significance over the
    two arms — compute it from the EvalLog with ``paired_delta.py`` (run.py
    ``--control`` does both). Costs ~2x the ``abp`` task (each case runs twice).
    """
    return Task(
        dataset=MemoryDataset(samples=_control_samples(skill), name=f"{skill}-control"),
        solver=[skill_context(skill), generate()],
        scorer=commons_scorer(),
        name=f"abp-control-{skill}",
    )
