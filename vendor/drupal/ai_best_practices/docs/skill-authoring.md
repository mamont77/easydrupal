# Writing Drupal Agent Skills

> This page is the **declarative** decision framework (is this a skill? where
> should it live?). Its **procedural** companion is the
> [`drupal-skill-authoring`](../skills/drupal-skill-authoring/SKILL.md) skill,
> which agents load automatically when writing or reviewing a skill — it covers
> the frontmatter rules that fail CI, the 500-line guideline, and this repo's
> eval checklist.

## Scope

Drupal skills should follow the
[Agent Skills specification](https://agentskills.io/specification). This page
does not define a Drupal-specific skill format. It defines Drupal-specific
guidance for deciding when to create a skill, where it should live, and how to
avoid duplicating canonical documentation.

The only Drupal-wide required frontmatter fields are the Agent Skills required
fields: `name` and `description`. Do not require Drupal-specific frontmatter
such as `drupal-version`, `status`, `owner`, or `last-reviewed`.

## Skills are procedural guidance

Docs are declarative. Skills are procedural.

Use a skill when an agent needs task-specific decision ordering, checks,
defaults, gotchas, or escalation cues.

Do not use a skill to recreate Drupal.org documentation, API docs, change
records, or module documentation. A skill should include enough context to route
the agent correctly, then point to canonical sources for details.

## Where skills should live

- Site/project-specific skill: the site or project repository.
- Contrib module skill: the contrib module repository.
- Standalone cross-cutting skill: its own Drupal.org project.
- Cross-cutting Drupal workflow skill: `ai_best_practices`, unless another
  project is the clearer owner.
- Skill authoring or meta guidance: `ai_best_practices`.
- Temporary or personal skill: local user setup.
- Full documentation or tutorial content: Drupal.org docs, API docs, change
  records, or module docs.

`ai_best_practices` should be the home for shared skills that have no better
owner. Module-specific skills belong in the module. Project-specific skills
belong in the project. Personal skills belong in personal setup.

## Skills vs. docs vs. AGENTS.md vs. MCP

Use documentation when the content is human-facing or the canonical source of
truth.

Use `AGENTS.md` when the content is always relevant to agents working in the
repository, such as setup commands, test commands, coding conventions, and
repository expectations. The [AGENTS.md](https://agents.md/) project describes
it as a README for agents: a predictable place for project context and
instructions.

Use a skill when the content is reusable, task-specific procedural guidance that
should be loaded only when relevant.

Use optional Agent Skills frontmatter fields only when they have a concrete
purpose. For example, use `license` for distribution terms and `compatibility`
for runtime requirements such as required tools, network access, a local Drupal
checkout, or a specific agent/client environment. Most skills do not need it.

Do not use `compatibility` as a broad substitute for Drupal version metadata.
When Drupal, module, or tool versions matter, the skill should instruct the
agent to inspect the local project, installed package versions, source code, and
canonical docs or change records.

Lifecycle, ownership, freshness, and package/version metadata should be handled
by the owning project, issue or merge request process, release/package metadata,
registry, or future package-manager conventions.

Use a Model Context Protocol (MCP)
[resource](https://modelcontextprotocol.io/specification/draft/server/resources)
when an agent needs structured or changing context. MCP resources let servers
share data that provides context to language models, such as files, database
schemas, or application-specific information.

Use an MCP [tool](https://modelcontextprotocol.io/specification/draft/server/tools)
when an agent needs to execute an action. MCP tools let servers expose actions
that language models can invoke, such as querying databases, calling APIs, or
performing computations.

Use an MCP
[prompt](https://modelcontextprotocol.io/specification/draft/server/prompts)
when a user should explicitly choose a reusable workflow. MCP prompts let
servers expose structured prompt templates to clients for user selection and
reuse.

## Admission checklist for `ai_best_practices` skills

Before adding a shared skill here, answer:

1. Who owns this behavior?
2. Does this belong in `ai_best_practices`, or closer to a site, module, or
   project?
3. Why is this a skill instead of documentation?
4. Why is this a skill instead of `AGENTS.md`?
5. Why is this a skill instead of an MCP resource, tool, or prompt?
6. What canonical Drupal sources does it point to?
7. What agent failure or decision-ordering problem does it solve?
8. How can we evaluate whether it improves agent behavior?

## Good candidates for `ai_best_practices`

- Skill-authoring and meta guidance.
- Drupal.org contribution workflow.
- Automated testing decision guidance.
- General Drupal development workflow defaults.
- Security and common AI mistake guardrails.
- Developer tooling guidance, if thin and tied to the default setup.

## Bad candidates for `ai_best_practices`

- Module-specific workflows.
- Agency conventions.
- Site-local deployment patterns.
- Persona duplicates.
- Skills that mostly copy a Drupal.org documentation page.
- One skill per subsystem, documentation page, or tool unless there is a real
  procedural workflow.

## Canonical sources

A skill should point agents to canonical Drupal sources instead of copying them.

Canonical sources include:

- Drupal.org documentation.
- API documentation.
- Change records.
- Module documentation.
- Local project code.
- Existing examples in the repository.

## Examples

Documentation:
"How Drupal cache invalidation works."

Skill:
"When debugging cache invalidation, first check cache tags, then contexts, then
max-age, then render cache or Dynamic Page Cache behavior, then local
invalidation code."

`AGENTS.md`:
"In this repository, run tests with `ddev phpunit`."

MCP resource:
"Expose enabled modules, config schema, or current site state."

MCP tool:
"Run Drush, query Drupal.org, inspect config, or execute a project command."

MCP prompt:
"Start a guided issue-summary-writing workflow selected by the user."

## Review expectations

Shared skills should be reviewable, but Drupal should not define extra required
skill frontmatter for lifecycle or versioning.

Ownership, freshness, release status, and versioning should normally be handled
by the owning project, issue or merge request process, Git history, releases,
registries, or future package-manager conventions.

If uncertainty changes what an agent should do, explain that uncertainty in the
skill body.

## Related issues

- [#3585585](https://www.drupal.org/project/ai_best_practices/issues/3585585)
  for this guidance.
- [#3585395](https://www.drupal.org/project/ai_best_practices/issues/3585395)
  for naming conventions.
- [#3585595](https://www.drupal.org/project/ai_best_practices/issues/3585595)
  for eval framework discussion.
- [#3585532](https://www.drupal.org/project/ai_best_practices/issues/3585532)
  and
  [#3585533](https://www.drupal.org/project/ai_best_practices/issues/3585533)
  for `AGENTS.md` and tool-specific context files.
- [#3565489](https://www.drupal.org/project/ai/issues/3565489) for broader
  first-class agent-skills discussion.
