# Agent setup

These instructions apply to a **consumer Drupal project** after you install **`drupal/ai_best_practices`**: use **`PROJECT_ROOT/AGENTS.md`** (materialised from **`AGENTS.md.template`** in the package; the plugin refreshes only the region between the **`ai-best-practices`** HTML comment markers on update). The same plugin adds **`CLAUDE.md`** and **`GEMINI.md`** at the project root **when they are missing**; if you already have those files, align them with **`AGENTS.md`** yourself (see the Composer warning on install/update).

## Skill discovery

`drupal/ai_best_practices` ships skills in `vendor/drupal/ai_best_practices/skills/` — the
Composer-managed source location. During `composer install` and `composer update` the plugin
copies those skills to `skills.output_directory` from `ai_best_practices.yaml`. That is
`.agents/skills/`, or `.claude/skills/` when the yaml was created from Claude Code, which
loads project skills only from `.claude/skills/`.

AI clients that follow the [Agent Skills specification](https://agentskills.io/specification)
discover and load skills automatically from `.agents/skills/`. You do not need to list skills
in `AGENTS.md` for those clients. For clients that do not yet support automatic discovery,
add explicit skill references to `AGENTS.md` directly (outside the managed markers).

Commit the skills output directory and `AGENTS.md` to version control so all team members and CI environments
share the same skills and AI context. Also commit tool-specific files such as `CLAUDE.md`
and `GEMINI.md` if your team uses those clients. To keep agent context local to each developer
instead, ignore the generated files as described in the package README under
[What to commit](../README.md#what-to-commit).

---

Most tools automatically pick up context files when you start them from your Drupal project root. A few need a one-line config hint to know where to look.

## Auto-detected (no config needed)

| Tool | File it reads |
|------|--------------|
| Claude Code | `CLAUDE.md` |
| Gemini CLI | `GEMINI.md` |
| OpenAI Codex | `AGENTS.md` |
| GitHub Copilot | `AGENTS.md` |

## Requires config

### Aider

Add to `.aider.conf.yml` in your project root:

```yaml
read: AGENTS.md
```

### Cursor

Add to `.cursor/rules/drupal.mdc` in your project root:

```
@AGENTS.md
```

### Windsurf

Add to `.windsurfrules` in your project root:

```
See AGENTS.md for Drupal-specific guidance.
```

### Gemini CLI

Add to `.gemini/settings.json` in your project root:

```json
{ "context": { "fileName": "AGENTS.md" }}
```

---

Using a tool not listed here? Check whether it supports `AGENTS.md` natively — many do. If not, consult its docs for how to point it at a context file, and consider [contributing](../CONTRIBUTING.md) the instructions back here.
