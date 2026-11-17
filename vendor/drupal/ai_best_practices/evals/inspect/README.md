# Inspect AI runner (abp #3588916)

Native [Inspect AI](https://inspect.aisi.org.uk/) runner for abp behavioral
evals. It reuses the Eval Commons case format (`dataset.yaml` / `rubrics.yaml`)
and the shared scoring core (`evals/scoring.py`) — Inspect provides the
task/scorer/log machinery on top.

## Why a CLI wrapper, not Inspect's native API

Inspect normally talks to provider SDKs (anthropic/openai/google), which bills
metered API tokens. abp runs through the **subscription CLIs** instead. So the
provider here (`provider.py`) is a custom `ModelAPI` (`abp_cli`) that wraps
`evals/providers.py`'s CLI runners — keeping the subscription billing posture
while gaining Inspect's tooling.

**Limitation:** `GenerateConfig` knobs the CLIs do not expose (`temperature`,
`seed`) are not forwarded — provenance for those is not pinnable under the
wrapper. Token counts and cost survive into the `EvalLog` via `ModelUsage`.

**The CLIs would bring the operator's own setup.** A logged-in CLI loads its
user configuration into every call: Codex CLI adds `~/.codex/AGENTS.md` and the
installed skills to the context, in both arms, and no flag turns that off
(checked against codex-cli 0.162). `evals/providers.py` therefore runs the CLIs
answer-only and without that setup:

- `claude`: `--tools ""`, `--disable-slash-commands` and `--setting-sources ""`
  (no tools, no skills, no user settings).
- `codex`: a throwaway `HOME`/`CODEX_HOME` per call holding only the login
  (copied, mode 0600, deleted afterwards; none with `CODEX_API_KEY` or
  `OPENAI_API_KEY`), tools and web search off (web search is the config
  setting `web_search`, on by default), an empty skills catalog. The runner reads the
  context back from Codex's session log into the result (`context`, kept in the
  EvalLog call record) and raises `ContextLeakError` when it finds an AGENTS.md,
  memories, a listed skill or a path into the operator's home.
- `kiro`: an empty throwaway `KIRO_HOME` per call (kiro-cli 2.31 or later; the
  login lives outside it), no tool trusted, so no commands, file reads or web.
  The runner reads the steering from Kiro's session log and the skills and
  agents from its stream-json command list into the result (`context`), and
  raises `ContextLeakError` on any that are not Kiro's own. Older releases do
  not work: kiro-cli 2.16 still read `~/.kiro` steering and skills with
  `KIRO_HOME` set, and has no stream-json output.
- `gemini`: not isolated yet; it still loads the operator's `~/.gemini` setup.

The agent CLIs also get one line after the question, in both arms: they have no
tools or files and should answer in the reply (`ANSWER_ONLY_NOTE`). Without it
they sometimes reply with a fake tool call instead of an answer.

Calling the model through Inspect's own providers needs none of this: the
request holds only what the task sends (the question, plus the skill as the
system message in the `on` arm), and the `.eval` log records it exactly.

```bash
# any provider Inspect supports, e.g. Anthropic or OpenAI with an API key
inspect eval evals/inspect/tasks.py@control -T skill=drupal-gitlab --model anthropic/<model>

# or an OpenAI-compatible gateway such as amazee.ai
export AMAZEE_API_KEY=...   AMAZEE_BASE_URL=https://llm.<region>.amazee.ai/v1
inspect eval evals/inspect/tasks.py@control -T skill=drupal-gitlab --model openai-api/amazee/<model>
```

For tool-using (agentic) evals, [Inspect SWE](https://meridianlabs-ai.github.io/inspect_swe/)
runs Claude Code or Codex CLI inside an Inspect sandbox and routes their model
calls back through Inspect, so the same holds there.

## Install (behavioral only)

`inspect-ai` pulls ~40 packages and is **never** needed for the static path.

```bash
python3 -m venv .venv && . .venv/bin/activate
pip install -r evals/requirements-behavioral.txt
```

## Run (costs subscription tokens)

The behavioral install (above) editable-installs `evals/inspect/`, which
registers the `abp_cli` provider as an `inspect_ai` entry point — so the native
CLI works:

```bash
# one skill
inspect eval evals/inspect/tasks.py@abp -T skill=drupal-gitlab --model abp_cli/claude

# pin the underlying model explicitly (otherwise claude uses the CLI default)
inspect eval evals/inspect/tasks.py@abp -T skill=drupal-gitlab --model abp_cli/claude/sonnet

# view the log
inspect view
```

Model strings: `abp_cli/<provider>` or `abp_cli/<provider>/<model>` where
`<provider>` is one of `claude` / `codex` / `gemini` / `mistral` / `kiro`.

`run.py` is a no-install launcher for the same task (handy when the editable
package is not installed); its `--model` omits the `abp_cli/` prefix:

```bash
python evals/inspect/run.py --skill drupal-gitlab --model claude
```

### Control arm — does the skill actually help?

The `control` task runs every case twice: with the skill context (arm `on`) and
without it (arm `off`). The headline is the **paired delta + significance**
across the two arms, not two unrelated pass rates — the same cases run in both
arms, so the honest statistic is McNemar's exact test over the per-case (on, off)
outcomes (`compare.py` reported only a bare point delta). Costs ~2x the `abp`
task.

```bash
# run both arms and print the paired delta
python evals/inspect/run.py --skill drupal-gitlab --model claude --control

# or analyze a previously recorded control run
python evals/inspect/paired_delta.py logs/<...>_abp-control-<skill>.eval

# native CLI also runs the arms (paired math is the run.py/paired_delta step):
inspect eval evals/inspect/tasks.py@control -T skill=drupal-gitlab --model abp_cli/claude
```

The report flags small samples: with few discordant pairs even a large delta is
not significant, and it says so rather than overclaiming. No pandas/scipy — the
exact test is a binomial tail computed in stdlib.

Repeated runs (`--epochs N`) are reduced to one majority outcome per case and
arm before pairing, so each case still counts once in the test. Use an odd N: an
even split has no majority and the case is dropped (and counted as dropped).

### Why the entry point is required (not just `import provider`)

`inspect eval FILE@task --model abp_cli/...` resolves `--model` *before* it
imports the task file, so the `@modelapi("abp_cli")` registration in
`provider.py` has not run yet — without the entry point, resolution fails with
"Model API abp_cli ... not recognized". Inspect auto-discovers a third-party
provider only when it is declared as an `inspect_ai` entry point in **installed
package metadata** (`pyproject.toml` here). The entry point points at
`abp_inspect_register` — a uniquely named shim (so the installed top-level module
does not collide with a generic `provider` in a shared venv) whose import
registers the provider. `get_model` imports it via `ensure_entry_points()`
exactly when it first looks up `abp_cli`. The install must be **editable** so
`provider.py` keeps its real source path and can still reach its sibling loose
scripts (`evals/providers.py`, `evals/scoring.py`). `run.py` sidesteps the
ordering differently — it imports `provider` first, then calls `eval()`.

## Export to Every Eval Ever (Layer 2)

`to_eee.py` converts an Inspect `.eval` log to the cross-harness
[Every Eval Ever](https://github.com/evaleval/every_eval_ever) (EEE) v0.2.2
envelope — abp's Layer-2 interchange format. It is a **thin wrapper over the
upstream `every-eval-ever[inspect]` converter** (the bespoke
`convert_to_envelope.py` is retired); it only adjusts what the upstream
converter cannot infer from an `abp_cli/*` model path:

- stamps abp source metadata (organization + first-party relationship);
- fills `model_info.developer` / `inference_platform` (upstream leaves them
  `"unknown"` for the `abp_cli` prefix) from the abp provider;
- flattens EEE's `<dataset>/<developer>/<model>/<uuid>.json` tree to a single
  `result.json`, keeping the instance-level rows in a `result_samples.jsonl`
  sibling referenced from the envelope (`--no-samples` for a summary-only file);
- splits a `control` log into one result per arm (with / without skill), where
  the converter would report one accuracy over both;
- for `openai-api/<service>/<model>` runs, names the service as the platform
  and takes the developer and exact model from `--model-developer`,
  `--model-id` and `--inference-platform` (a gateway's model name cannot say
  who made the model).

```bash
# layer the EEE export deps on top of the behavioral install
# (needs Python >= 3.12 — every-eval-ever declares Requires-Python >=3.12)
pip install -r evals/requirements-eee.txt

python evals/inspect/to_eee.py logs/<...>.eval -o result.json
```

> The provider sets `ModelOutput.model` to the **bare** model name (Inspect's
> own convention), not `abp_cli/<name>`. A prefixed value made the EEE adapter
> rebuild the path as `abp_cli/abp_cli/claude`; the bare name keeps `model_info`
> clean. `test_provider.py` guards this.

## Publish a results set

`publish_results.py` turns a run into a public dataset in three steps, each a
separate command so you can read the output in between:

```bash
# 1. run every skill with and without its skill, 3 times each, on one model
python evals/inspect/publish_results.py run --model abp_cli/claude/sonnet \
    --label claude-sonnet --out release --epochs 3

# 2. build: logs with local paths replaced, data/final.jsonl (one row per
#    answer, shown as a table on Hugging Face), eee/ records, a card template
python evals/inspect/publish_results.py build --out release \
    --run claude-sonnet=release/runs/claude-sonnet
#    a gateway run says what it served:
#    --model-info haiku=anthropic,anthropic.claude-3-haiku-20240307,"amazee.ai (AWS Bedrock)"

# 3. edit release/README.md (the TODO(publish) lines), then upload
python evals/inspect/publish_results.py upload --out release \
    --repo-id <owner>/<name>          # dry run; add --yes to upload
```

- `build` refuses to finish if it finds a home path or a credential-looking
  string in what it wrote; `upload` checks again and refuses a card that still
  has `TODO(publish)` lines.
- `upload` creates a new dataset **private**. Making it public is a separate
  decision, in the dataset's settings on Hugging Face.
- It needs the EEE extra (which brings `huggingface_hub`) and a write token
  (`hf auth login`).
- Step 1 takes any Inspect model: a subscription CLI (`abp_cli/...`) or one of
  Inspect's own providers (`anthropic/<model>`, `openai-api/<service>/<model>`).
  Report the pooled result with `paired_delta.py` per log; the card is where it
  goes.

The 13 Oct 2026 run published this way:
https://huggingface.co/datasets/gkastanis/drupal-ai-best-practices-skill-evals

## Files

| File | Role |
|------|------|
| `run.py` | launcher — imports `provider` then calls `eval()` (works around the CLI model-resolution order; see above) |
| `provider.py` | `AbpCliAPI(ModelAPI)` — wraps `evals/providers.py` (`@modelapi("abp_cli")`) |
| `tasks.py` | `@task abp(skill=...)` + `@task control(skill=...)` — dataset loader, skill-context solver, `commons_scorer` (wraps `scoring.grade`) |
| `paired_delta.py` | control-arm analysis: paired delta + McNemar exact significance over a control-task EvalLog (no pandas/scipy) |
| `to_eee.py` | Inspect `.eval` → Every Eval Ever v0.2.2 envelope (thin wrapper over upstream `every-eval-ever[inspect]`) |
| `publish_results.py` | run every skill, build a scrubbed public results set (logs, table, EEE records), upload it to Hugging Face |
| `scorer_parity.py` | golden reference + drift guard: `scoring.grade` verdicts over recorded responses |
| `golden/<skill>.json` | frozen verdicts the scorer must reproduce |
| `test_provider.py` | wrapper unit test (fake runner, no tokens) — needs `inspect_ai` |
| `test_scorer_parity.py` | drift guard (pip-free) + scorer replay (needs `inspect_ai`, no tokens) |
| `test_paired_delta.py` | paired-delta math unit test (no tokens; cross-checks scipy if present) |
| `test_to_eee.py` | EEE export end-to-end (fake model, token-free) — needs the EEE extra |
| `test_publish_results.py` | build + upload end-to-end against a fake API (token-free) — needs the EEE extra |

## Tests / CI

- `evals/test_scoring.py` and the drift-guard half of `test_scorer_parity.py`
  run pip-free in the `evals-scoring` CI job.
- `test_provider.py`, the scorer **replay** in `test_scorer_parity.py`, and
  `test_paired_delta.py` need `inspect_ai`; they run in the `evals-inspect` CI
  job (installs `requirements-behavioral.txt`).
- `test_to_eee.py` and `test_publish_results.py` additionally need the EEE
  extra; they run in the
  `evals-eee-export` CI job (installs `requirements-eee.txt` on top). All are
  token-free — they never call a model.
