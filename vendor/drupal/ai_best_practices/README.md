# Drupal AI Best Practices

## Project Vision
`ai_best_practices` exists to make AI-assisted Drupal development work well out of the box.

The Drupal AI ecosystem already has many good ideas, but today they are scattered across different repos, formats, tools, and installation experiences. That creates unnecessary friction for developers who just want a sane default setup and guidance they can trust.

This project aims to be the **opinionated starter experience for AI-assisted Drupal development.** (Think [Drupal CMS](https://new.drupal.org/drupal-cms): a curated starting point assembled from the best available pieces so people can get value quickly.)

We give developers a clear installation path, a practical baseline of Drupal guidance, and a top-level AI entrypoint that helps AI tools behave less like hopelessly confused tourists, and more like knowledgeable and experienced Drupal developers.

Our goal is not to reproduce every experiment in the ecosystem or lock people into one tool. It is to provide a maintained, community-driven default that is useful immediately, grounded in real Drupal practice, and able to improve over time.

## Scope

Universal guidance that applies across Drupal projects: core, contrib, and custom code. Not project-specific configuration.

## Who is this for?

Right now:
- Drupal developers using AI coding tools
- AI developers who may be new to Drupal

Possibly in the future:
- Site builders who want AI assistance grounded in Drupal conventions

## Repository layout

- **[`skills/`](skills/)** — authoritative Agent Skill content (one directory per skill).
- **[`.agents/`](.agents/)** — `AGENTS.md.template` and agent setup notes; the managed region in the template is what the plugin merges into a consumer’s root **`AGENTS.md`**.
- **`src/`** — Composer plugin, console (`drupal-ai`), skills aggregation, and agent file sync.
- **`tests/`** — PHPUnit tests for the PHP code paths.
- **`evals/`** — optional eval assets paired with skills by directory name (see [CONTRIBUTING.md](CONTRIBUTING.md)).
- **[`pages/index.html`](pages/index.html)** — static GitLab Pages landing page. The catalog job writes `.well-known/` next to it; it does not generate HTML.
- **[`scripts/build-agent-skills-index.py`](scripts/build-agent-skills-index.py)** — builds the GitLab Pages discovery index (`.well-known/agent-skills/`).


## Developing this package

Use this path when you are **working in this git repository**, not only when you install the package into a Drupal site:

1. Clone and run **`composer install`** at the repository root (PHP **8.1+**).
2. After changing PHP or tests, run **`vendor/bin/phpunit`** and **`composer cs-fix`** before opening a merge request.

Merge request checks (see [`.gitlab-ci.yml`](.gitlab-ci.yml)): changes under **`skills/`** run **`vendor/bin/agent-skills-validator skills`**; changes under **`src/`**, **`tests/`**, or Composer config run **PHPUnit**. The Pages catalog is rebuilt on the default branch; locally, after `pip install pyyaml`, `python3 scripts/build-agent-skills-index.py --check` validates a full catalog write, and `--dry-run` prints `index.json` to stdout.

To verify the package installs from a local checkout into a disposable Composer project, follow the path-repository smoke test in [CONTRIBUTING.md (Smoke test)](CONTRIBUTING.md#smoke-test-local-checkout-via-path-repository).

## How it works

- **Project configuration** is `ai_best_practices.yaml` at the Composer project root. It is created from [`ai_best_practices.yaml.dist`](ai_best_practices.yaml.dist) when missing, and controls skill discovery and where merged skills land (`skills.output_directory`; **`.agents/skills`**, or **`.claude/skills`** when Claude Code is detected as the file is created).
- **Skills** ship in this repo under [`skills/`](skills/) and in the consumer project under `vendor/drupal/ai_best_practices/skills/`. `composer drupal-ai skills-sync` merges discovered skills into `skills.output_directory` (see `ai_best_practices.yaml`; **`skills-install`** remains an alias).
- **Agent markdown:** after autoload dump, install/update of **`drupal/ai_best_practices`** runs **`skills-sync`**, then writes or updates **`AGENTS.md`** at the project root from [`.agents/AGENTS.md.template`](.agents/AGENTS.md.template): a full copy when **`AGENTS.md`** is missing, or only the region between **`<!-- ai-best-practices:start -->`** and **`<!-- ai-best-practices:end -->`** on later updates so notes outside those markers stay intact. The plugin adds **`CLAUDE.md`** and **`GEMINI.md`** at the project root **when they are missing**; existing files are left unchanged and Composer prints a warning to align them with **`AGENTS.md`** (see [`.agents/SETUP.md`](.agents/SETUP.md)). Skip this deferred work with **`AI_BEST_PRACTICES_SKIP_PACKAGE_SYNC=1`**.

This package is a **Composer plugin** with the **`drupal-ai`** script; it reads **`ai_best_practices.yaml`** (from **`ai_best_practices.yaml.dist`** when missing). It does **not** use drupal-scaffold for these files. Env vars, hooks, and contributor workflow: **[CONTRIBUTING.md](CONTRIBUTING.md)**.

## Installation

### Agent Skills catalog (`npx skills`)

Skills in this repo are published on GitLab Pages as an [Agent Skills discovery 0.2.0](https://github.com/cloudflare/agent-skills-discovery-rfc) index:

- Site: [https://project.pages.drupalcode.org/ai_best_practices/](https://project.pages.drupalcode.org/ai_best_practices/)
- Index: [https://project.pages.drupalcode.org/ai_best_practices/.well-known/agent-skills/index.json](https://project.pages.drupalcode.org/ai_best_practices/.well-known/agent-skills/index.json)

Install (requires a `skills` CLI that understands discovery schema 0.2.0 — older releases only accepted a legacy `files[]` index):

```bash
npx skills add https://project.pages.drupalcode.org/ai_best_practices --skill '*'
```

The Pages URL uses the contrib path-prefix form (`/ai_best_practices`). After the first `create-pages` job on the default branch, set **Deploy → Pages → unique domain** to **off** so that path stays stable instead of a hex subdomain.

Composer install below remains the supported Drupal-project path until skill sync is retired.

### Composer (Drupal project)

From the root of an existing Drupal project (the directory that contains your `composer.json`):

```bash
composer config allow-plugins.drupal/ai_best_practices true
composer require --dev drupal/ai_best_practices:@dev
```

### Verify install

After a successful install or update, you should typically see:

- **`ai_best_practices.yaml`** at the Drupal project root (materialised from the dist file when it was missing), and **`/ai_best_practices.yaml`** in the project's **`.gitignore`** (added when the plugin first creates the file).
- **`AGENTS.md`** at the project root (full copy first time; later updates only replace the `ai-best-practices` HTML comment region).
- Merged skills under **`skills.output_directory`** (**`.agents/skills/`** or **`.claude/skills/`**, see above).
- **`composer drupal-ai skills-list`** exiting **0** and listing discovered skills.

If something is missing, confirm **`allow-plugins`** for `drupal/ai_best_practices` is **true**, that you did not set **`AI_BEST_PRACTICES_SKIP_PACKAGE_SYNC=1`**, and that your **`ai_best_practices.yaml`** is valid YAML. **Invalid or unreadable YAML stops sync and prints an error** — fix the file and re-run `composer drupal-ai skills-sync` (or re-run `composer install` / `composer update`) to resume.

### What to commit

The plugin writes these files into the project root. Installed with **`--dev`** as above, the package is absent under **`composer install --no-dev`**, which therefore never creates or refreshes any of them:

| File | Written when | Advice |
|------|--------------|--------|
| **`ai_best_practices.yaml`** | When missing, copied from [`ai_best_practices.yaml.dist`](ai_best_practices.yaml.dist); the first time, the plugin also adds **`/ai_best_practices.yaml`** to **`.gitignore`** | **Ignore.** Local overlay on the dist file; do not commit it |
| **`AGENTS.md`** | Full copy when missing; later only the region between the `ai-best-practices` markers is refreshed | Commit |
| **`.agents/skills/`** (the default **`skills.output_directory`**) | Every **`skills-sync`** | Commit |
| **`CLAUDE.md`**, **`GEMINI.md`** | Only when missing; existing files are left unchanged | Commit if your team uses those clients |

**`ai_best_practices.yaml` is per developer.** It decides where *your* agent reads and writes skills, and which skills *you* exclude. On a mixed team one person points **`skills.output_directory`** at **`.claude/skills`** for Claude Code while another keeps **`.agents/skills`** for Cursor, and some people swap a shipped skill for their own with **`exclude_skills`**. A committed copy would push one person's layout onto everyone at their next **`skills-sync`**, and some hosts reject the file on deploy. The plugin therefore adds **`/ai_best_practices.yaml`** to **`.gitignore`** when it first creates the file, and each checkout gets a fresh default copy on its next `composer install`. It leaves an existing rule for the file alone, including a `!` negation, and never writes through a symlinked **`.gitignore`**. If the file is already tracked, run `git rm --cached ai_best_practices.yaml` once.

**Shared agent context (default).** Commit **`AGENTS.md`**, the default skills directory, and the tool files your team uses, so teammates, CI, and hosts that install with **`--no-dev`** get the same context without running the plugin. If you point **`skills.output_directory`** somewhere else for your own tool, that directory is yours too: list it in **`.git/info/exclude`** so it stays out of commits.

**Local-only agent context.** If AI tools only run on developer machines, or your deployment tooling rejects the generated files, ignore the rest of them as well. They are written by the `composer install` (or `update`) that installs the package itself, which on a fresh checkout is the first one. Later runs with **`vendor/`** already in place only recreate a missing **`ai_best_practices.yaml`**; a missing **`AGENTS.md`**, **`CLAUDE.md`**, **`GEMINI.md`** or skills directory stays missing, and `composer drupal-ai skills-sync` only restores the skills.

```gitignore
# drupal/ai_best_practices: written when the package is installed (dev dependency)
/AGENTS.md
/CLAUDE.md
/GEMINI.md
/.agents/
```

With this setup:

- Files that are already tracked stay tracked until you run `git rm --cached` on them; ignoring never deletes a local copy.
- To get the files back after losing them (for example `git clean -X`), run `composer reinstall drupal/ai_best_practices`. It reinstalls the package and reruns the sync, and keeps your **`ai_best_practices.yaml`** and the **`allow-plugins`** entry.
- If you changed **`skills.output_directory`**, ignore that path instead of, or as well as, **`/.agents/`**.
- Notes you add to **`AGENTS.md`** outside the managed markers stay on your machine. Put team guidance in a committed file instead.
- Environments that install with **`--no-dev`** have no agent context at all, which is usually what you want for a deployment.

### Uninstall

`composer remove drupal/ai_best_practices` runs plugin cleanup: it removes the **`drupal-ai`** Composer script (and the legacy **`abp`** script when it pointed at this package), the **`allow-plugins`** entry for this package (and prunes empty `scripts` / `config` blocks when applicable), and deletes **`ai_best_practices.yaml`** at the project root. Back up that YAML first if you customized it. The **`/ai_best_practices.yaml`** line stays in **`.gitignore`**; remove it yourself if you like. Generated **`AGENTS.md`**, **`CLAUDE.md`**, **`GEMINI.md`**, and merged skills under your configured output directory are **not** removed automatically (project-owned). If you added your own **`post-install-cmd`** / **`post-update-cmd`** hooks that call `composer drupal-ai` (or the old `composer abp`), update or remove those yourself.

### Commands (after install)

Use the Composer script:

- `composer drupal-ai skills-sync` — sync Agent Skills from discovered sources into `skills.output_directory` (see yaml; alias: `skills-install`).
- `composer drupal-ai skills-list` — list discovered skills (add `--format=xml` when needed).

Automated installs/updates re-sync skills and refresh the managed block in **`AGENTS.md`** as described above; they do **not** overwrite existing **`CLAUDE.md`** / **`GEMINI.md`**.

To skip automatic registration of the **`drupal-ai`** script in the host project’s **`composer.json`**, set **`AI_BEST_PRACTICES_SKIP_COMPOSER_MUTATION=1`** or **`auto_register_composer_scripts: false`** in **`ai_best_practices.yaml`**. To skip **`skills-sync`** and **`AGENTS.md`** sync when this package is installed or updated, set **`AI_BEST_PRACTICES_SKIP_PACKAGE_SYNC=1`**.

Which of the generated files belong in your repository is covered in [What to commit](#what-to-commit).

## Agent setup

In most cases, start your agent from your Drupal project root and it will pick up the context files automatically.

Some tools need a one-line config hint — see [`.agents/SETUP.md`](.agents/SETUP.md) for tool-specific instructions.
