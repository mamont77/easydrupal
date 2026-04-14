# AGENTS

## Models
- Default:         `claude-sonnet-4-6`
- Heavy reasoning: `claude-opus-4-6`
- Fast/cheap:      `claude-haiku-4-5-20251001`

Read: `.agents/PROJECT.md`

## Pointers
- Dev env:      `.agents/DEV_ENV.md`
- Coding:       `.agents/CODING.md`
- Workflow:     `.agents/WORKFLOW.md`
- Integrations: `.agents/INTEGRATIONS.md`
- Canvas/Figma: `.agents/CANVAS_FIGMA.md`
- AI rules:     `.agents/AI_RULES.md`
- Skills index: `.agents/skills/INDEX.md`

## Output rules
- Prefer code over prose.
- Show diffs / changed blocks only.
- Include file paths.
- No secrets.
- If requirements are ambiguous: ask one short question before coding.
- Don’t edit: `vendor/`, `web/core/`, `web/modules/contrib/`, `web/themes/contrib/`.

## Skills
On session start:
scan `.agents/skills/**/SKILL.md` → build `.agents/skills/INDEX.md`

Before any task:
read `INDEX.md` → pick matching skill → follow its `SKILL.md`

Fallback: `.agents/*.md`
