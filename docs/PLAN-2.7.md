# ArgueSecure 2.7 — working agreements, then the next product pass

> Follows [`PLAN-2.6.md`](PLAN-2.6.md) and [`RELEASE-2.6.0.md`](RELEASE-2.6.0.md)
> (W92–W97, D52–D58). This plan opens with **W98** and decisions **D59–D61**;
> its product packages are to be planned from `docs/PRODUCT.md` and research
> under `docs/research/`, and added here as W99 onward.
>
> The ground rules in [`CLAUDE.md`](../CLAUDE.md) apply unchanged.

---

## Why

2.6 was built in one long session by one agent that delegated drafts, check
runs and documentation to cheaper sub-agents, and it worked because the
process was spelled out as it went: plan and decisions first, tests with the
code, every check run, guards mutation-tested, measurements committed,
release notes written from the evidence. None of that was written anywhere an
*other* agent would find it except scattered across CLAUDE.md and past plans.

The owner intends to keep developing ArgueSecure with several agents — local
Claude Code sessions, and a Claude Code Project whose cloud threads build,
research and write in parallel — and wants every one of them to work the same
way, aware of the
product's niche, its deployment and its measurements. W98 writes the process
down where agents read it, and gives them a product brief to judge proposals
against.

### What this package does not touch

Application code, tests of application code, the specs, and every decision
before D59.

---

## Decisions (closed)

| # | Decision | Rationale |
|---|---|---|
| D59 | **The process is encoded in the repository as Claude Code skills and sub-agent definitions** — `.claude/skills/` (work-package, release, assistant-model, research) and `.claude/agents/` (check-runner on haiku; docs-writer, test-writer, researcher on sonnet) — committed, with the rest of `.claude/` still ignored. | Instructions pasted into one tool reach only that tool. The repository is what every Claude Code session, on any machine, reads; skills are what an agent picks up at the moment it starts a task; a sub-agent's `model:` makes the cheaper model the default rather than something to remember. |
| D60 | **Private context lives in `CLAUDE.local.md`, which is never committed**; `CLAUDE.local.md.example` shows its shape. | The repository may become public. The deployment host's address, paths and layout are needed by agents on the maintainer's machine and by nobody else. |
| D61 | **`docs/PRODUCT.md` is the yardstick for proposals**: the niche (argumentation-based collaborative risk identification; evidencing frameworks' risk-assessment requirements), the principles, and the non-goals. | Without it, research produces good ideas for a different product — most often a GRC suite. With it, "which job does this serve, and is it a non-goal?" is a question any agent can answer before a package is written. |
| D62 | **Releases publish prebuilt images to GHCR** — `ghcr.io/<owner>/arguesecure` and `…/arguesecure-assistant`, `linux/amd64`, from `.github/workflows/image.yml` on `v*` tags (`X.Y.Z`, `X.Y`, `latest`) and on manual runs (`edge`). `docker-compose.yml` names them with `image:` and keeps `build:`; `ARGUESECURE_VERSION` picks the tag. | Deploying meant a clone and a ten-minute build on the host, and upgrading meant `git pull` and another build — on Unraid, from a terminal, because Compose Manager's Update only pulls. The image was already host-agnostic since W27 (the Reverb key and the websocket origin are resolved at run time, deployment.md §2.1), so publishing it is the missing step, not a redesign. Plain `docker` commands rather than the `docker/*` actions: fewer third-party pins holding a `packages:write` token. The registry namespace names the project's publisher the way the README's clone URL does, and `ARGUESECURE_IMAGE` overrides it. |
| D63 | **The assistant's launcher and catalogue are baked into a thin image `FROM` the same digest-pinned llama.cpp**, replacing the `./docker/assistant` bind mount. | The mount was the one thing that still tied a deployment to a checkout; without one, Docker creates the directory empty and hides the files. The llama.cpp build, flags and presets are unchanged (D56, D52), so the 2.6 measurements still describe what runs; `verify.sh` reads the pin from the new Dockerfile. |
| D64 | **A defence cites a framework control as a `node_references` row of kind `control`**, identifier `FRAMEWORK:CONTROL` (`ISO27001-2022:A.5.23`, `NIS2:ART.21(2)(D)`), from a closed list of frameworks in `config/arguesecure.php`, each with its own pattern for the control part and one link, to the framework rather than the control. **Defences only.** | The validate-and-link mechanism, the `(kind, identifier)` index ("which assessments cite A.5.23?"), the cloner and the fixtures already carry references; a new table would duplicate all four. A closed list is what makes the identifier structured: free text is what `other` already is. The link is to the framework because the standards' text is not free to deep-link and EUR-Lex anchors are not a stable contract. Defences only, because a control is what reduces a risk (CLAUDE.md), and so no citation can sit where the coverage view does not look. |
| D65 | **The coverage view lists what the assessment cites, not what a framework contains.** Per framework, each cited control with the defences citing it and their status; a control reads *implemented* when any citing defence is, *planned* when none is but one is todo, *shelved* otherwise; a citing defence linked to no attack is flagged. It never says "compliant", and it lists no uncited control. | Listing uncited controls means shipping and maintaining every framework's catalogue, which the standards templates already are: an assessment started from one cites every control. Status, not strength or ALE: whether a control is in place is what a framework asks; how much it reduces is the rankings' job. The wording is PRODUCT.md's "not a compliance certificate". |
| D66 | **`include_shelved` binds the coverage view too.** Off, shelved citing defences are left out, a control cited only by shelved ones is not listed, and a framework left with none is not listed either, but the section says how many controls were left out. | The option exists so rejected controls do not reach a document sent to an auditor unasked (`ReportSectionBuilder`). Dropping them silently would make an exclusion read as an omission; the count keeps the gap visible without listing it. |

---

## The packages

| # | Package | Owns |
|---|---|---|
| **W98** ✓ | Working agreements (D59–D61) | `.claude/skills/**`, `.claude/agents/**`, `.gitignore` (the `.claude` and `CLAUDE.local.md` rules), `CLAUDE.md` ("How work gets done here", the checks-in-containers note), `CLAUDE.local.md.example`, `docs/PRODUCT.md`, `docs/CLAUDE-PROJECT.md`, `tools/dev/checks/**` |
| **W99** ✓ | `CLAUDE.md` under 200 lines | `CLAUDE.md`, `.claude/rules/**`, `.claude/skills/**`, `docs/RELEASE-2.6.0.md` (§7 only), this plan's W99 entry |
| **W100** (built; one step left) | The checks in a Code Project thread | `tools/dev/checks/**`, `docs/CLAUDE-PROJECT.md`, CLAUDE.md's comment on `checks.sh` |
| **W101** ✓ (2.6.1) | Prebuilt images (D62, D63) | `.github/workflows/image.yml`, `docker-compose.yml` (the `image:` lines and the assistant's build and mounts), `docker/assistant/Dockerfile`, `.env.example` (the Image section), `docker-compose.e2e.yml` (its image tag), CLAUDE.md's throwaway-project sentence, `tools/spike/assistant/verify.sh` (the line reading the pin), `tests/Unit/Deployment/**`, `README.md` (Installing, Running it day to day), `docs/specs/deployment.md` (§2.4, §8 Upgrades, §9.3), this plan's W101 entry |
| **W102** ✓ (2.6.2) | Control citations and a coverage view (D64–D66) | a new `node_references` migration, `config/arguesecure.php` (`references`), `app/Domain/References/**`, `app/Http/Requests/Assessment/StoreNodeReferenceRequest.php`, `database/seeders/TemplateAssessmentSeeder.php` and `Support/AssessmentFixtureValidator.php` (references), `database/seeders/templates/standard-*.json` (defence `references` only), `app/Domain/Reporting/ReportAssembler.php` (`controlCoverage`), `app/Domain/Reporting/Export/ReportSectionBuilder.php` (its section), `resources/js/Components/NodeModal/NodeModal.vue` (the tab's node type), `resources/js/Components/NodeModal/tabs/{references.ts,ReferencesTab.vue}`, `resources/js/Components/Report/{ControlCoverageSection.vue,ReportDocument.vue,types.ts}`, `resources/markdown/help/{building,reading-a-report,templates}.md`, `docs/specs/schema.md` §3.12, `docs/specs/ui.md` §9, their tests |
| W103+ | To be planned from `docs/PRODUCT.md` and research | — |

### W98 — Working agreements

- **Skills.** `work-package` (open, build, finish a package — the definition
  of done), `release` (plan and cut a release — PLAN, RELEASE, CLAUDE.md,
  README, in-app help, amendments, tag), `assistant-model` (measure before
  changing the assistant; which harness answers which question; the red-team
  record), `research` (methodology, standards, competitors, market — judged
  against PRODUCT.md, written to `docs/research/`).
- **Sub-agents** with their model in frontmatter: `check-runner` (haiku),
  `docs-writer`, `test-writer`, `researcher` (sonnet). Each states what it
  must never do (commit, invent a number, reach a real host from a test).
- **`docs/PRODUCT.md`** — the product brief (D61).
- **`CLAUDE.local.md.example`** — the private-context template (D60).
- **`.gitignore`** — `.claude/` stays ignored except `skills/`, `agents/`
  and `rules/` (the last for W99's path-scoped rules); `CLAUDE.local.md`
  is ignored.
- **`tools/dev/checks/checks.sh`** — the seven checks in containers, for a
  machine with Docker and no PHP or Node, including the reason it runs them
  against a volume copy rather than a bind mount.
- **`docs/CLAUDE-PROJECT.md`** — how to set up the Claude Code Project whose
  threads work on this repository: prerequisites, the project instructions
  (with private deployment facts, which cloud threads cannot get from the
  gitignored `CLAUDE.local.md`), models, environment and the first batch —
  checked against Anthropic's documentation.

**Acceptance:** `git check-ignore` tracks `.claude/skills/**` and
`.claude/agents/**` and ignores the rest of `.claude/` and `CLAUDE.local.md`;
the skills and agents load in a Claude Code session (they appear in its
skill and agent lists); `tools/dev/checks/checks.sh` is ShellCheck-clean and
runs the seven checks from a clean machine with only Docker; no committed
file contains a host address, a personal path or a secret.

**Done.** `git check-ignore` tracks the skills, agents and rules and ignores
`.claude/settings.local.json`, `.claude/worktrees/` and `CLAUDE.local.md`;
every skill and agent file has valid frontmatter (`name`, `description`,
and `model` on each agent); ShellCheck is clean on `checks.sh`; the checks
ran from a clean state (Pest 1,212 passed, 2 skipped); a search of the
tracked tree for the deployment host's address, paths and user name finds
nothing (the CPU model is named, as measurement context). One pre-existing
example path in `docs/specs/deployment.md` §2 mirrors the maintainer's
layout; that file is not this package's.

### W99 — `CLAUDE.md` under 200 lines

Anthropic's guidance targets under about 200 lines per `CLAUDE.md`, because a
longer one is followed less reliably; at 2.6 it is 245 lines and 23 KB, much
of it history. Keep in `CLAUDE.md` only what every session needs — the
product in two lines, ground rules, commands, the security rules, the
domain model's traps. Move the rest where it loads when relevant: history to
the release documents it came from, file-scoped rules to `.claude/rules/`
with path globs, workflows to skills.
**Acceptance:** `CLAUDE.md` under 200 lines; nothing removed from it is
lost — each rule moved names its new home in the commit; a session asked a
question the old file answered still answers it.

**Done.** 245 lines and 23.1 KB became 153 lines and 12.3 KB. Moved, not
dropped:

| What | Now in |
|---|---|
| Release-by-release history (2.0–2.6) | The RELEASE documents it came from; a pointer to the newest |
| The work since 2.5.1, which had no release document | `RELEASE-2.6.0.md` §7 (the release that shipped it; ownership extended to that section for this reason) |
| The assistant's runtime, presets, measure-first rule, `MEASURED:` pins | `.claude/rules/assistant.md` — the assistant's code, runtime, help corpus, tests, spikes |
| Pinned defects in full: markers, the open pin, the 2.4 and W80 history, presence assertions | `.claude/rules/pinned-tests.md` (`tests/**`); the rule itself stays in CLAUDE.md in one paragraph |
| Identity columns in full: the example, the three packages it broke, `replicate()` | `.claude/rules/identity-columns.md` (PHP in `app/`, `database/`, `tests/`); one line stays |
| End-to-end tests | `.claude/rules/e2e.md`; one sentence stays under Commands |
| Legacy reference points | `.claude/rules/legacy.md` (the files they are the ancestors of) |
| A release's CLAUDE.md step | The `release` skill: update "Where things stand" in place, keep the story in the RELEASE file, stay under 200 lines |

A script checked that every one of the old file's 144 code spans survives in
CLAUDE.md, a rule or a skill; the only ones that do not are links to three
specific old plans and releases, replaced by "the newest". Rule
frontmatter follows the `paths:` format in Anthropic's memory documentation.
Whether a live session answers from a path-scoped rule is exercised by
W100's thread, the first session to start from this CLAUDE.md.

### W100 — The checks in a Code Project thread

Prove that a cloud thread in the default environment can run all seven
checks — through `tools/dev/checks/checks.sh` or natively with MariaDB in
Docker — and, if setup is slow, write the environment's setup script into
`docs/CLAUDE-PROJECT.md`. The first task to give the Project.
**Acceptance:** a thread's final message shows seven passing checks from its
own VM, and the time its setup took.

**Built, and verified in an emulation of the VM; the real thread is the
last step.** Reading the environment documentation against `checks.sh`
found three things a cloud thread would have hit:

1. **A fresh clone has no `.env`**, and every artisan command aborts
   without one (the reverb broadcaster resolves as routes load). The laptop
   never showed it because its dev stack had written one. `checks.sh setup`
   now writes `.env` from `.env.example` with generated keys, the way CI
   and `docker/entrypoint.sh` do, after confirming git ignores it.
2. **Container mode is the slow way on a VM that has PHP 8.3 and Node 22.**
   `checks.sh` now runs **natively** when `php`, `composer` and `npm`
   are on PATH (what CI does), MariaDB and Redis still in Docker, published
   on loopback; container mode is unchanged for a machine without them.
   `CHECKS_MODE` overrides the choice.
3. **The VM's PHP may lack extensions the suite needs**, and pecl — how the
   check image builds phpredis — is not on the Trusted allowlist.
   `tools/dev/checks/cloud-setup.sh` is the environment's setup script:
   it installs only the missing `php8.3-*` packages (and ShellCheck) from
   Ubuntu's archive and pre-pulls the service images, for the environment
   cache. `checks.sh setup` preflights the same list plus
   `composer check-platform-reqs`, which is how `gd`
   (`phpoffice/phpspreadsheet`) was found — a first run without it stopped
   with the package name instead of failing tests.

Also learned: a project thread reads `.claude/settings.json` (hooks,
permissions) only when the project has **one** repository, so the guide now
says to add only this one, and the setup lives in the environment's script
rather than a SessionStart hook.

**Evidence.** In an Ubuntu 24.04 container with its own Docker daemon, PHP
8.3 CLI without extensions, Composer 2 and Node 22 (an approximation of the
VM, not the VM): the setup script 59 s the first time (24 s once packages
were cached), `checks.sh setup` 86 s from a fresh copy, then all seven
checks green natively in 395 s — Pest 1,212 passed, 2 skipped — with
`git status` clean afterwards. ShellCheck is clean on both scripts. Each
new guard was mutation-tested there: a `.env` git would commit is refused
and not written; checks before setup say "run setup first" instead of
failing with exit 127; a database container that publishes no port (one
from before W100) is refused in native mode; a missing extension names the
`apt-get` line. Container mode re-ran on the laptop unchanged.

**Left:** the first Project thread runs the prompt in
`docs/CLAUDE-PROJECT.md` §6 on the real VM and reports the seven results
and both timings; a difference from the emulation becomes a W100 follow-up.

### W101 — Prebuilt images

Asked for by the owner while deploying on Unraid: a deployment should not
need a clone and a build. Publish the application and assistant images on
every release (D62), bake the assistant's two files into its image so no
service mounts anything from a checkout (D63), and point the compose file
at the published names while keeping source builds working.

**Acceptance:**

1. A directory holding only `docker-compose.yml` and a `.env` boots the
   stack from the published images: `/up` answers, `/setup` returns 200, and
   `assistant-install list` reads the catalogue from inside the assistant
   image. `image.yml` runs exactly this, with `--pull never`, before it
   pushes anything.
2. A source checkout still builds and boots with `docker compose up --build
   -d`, and CI's `docker` and `e2e` jobs are unchanged and green.
3. `tests/Unit/Deployment/ComposeImageDeployTest.php`: the four PHP services
   name the application image and the two assistant services the assistant
   image, by their specified values; every buildable service names an image;
   no service bind-mounts a path that is not a variable; the assistant's
   base is digest-pinned. Each guard mutation-tested.
4. README and deployment.md describe installing and upgrading from the
   image, and the private-repository login.


**Built; acceptance 1 and 2 are proven by this package's own pull request
CI** (`image.yml` runs on it without pushing; `ci.yml`'s `docker` and `e2e`
jobs build from source), because the cloud thread that built it cannot build
the application image: its egress policy refuses `pecl` and GitHub archive
downloads. What ran there: the seven checks green (Pest 1,217 passed, 2
skipped; Vitest 904); the five new guards each mutation-tested (a wrong image
on `reverb`, a wrong installer image, `scheduler` without an image, the old
`./docker/assistant` mount restored, an unpinned `FROM`, a missing `COPY` —
each red, each restored); and an image-only deployment of stand-in images
from a directory holding only `docker-compose.yml` and `.env`, with
`--pull never`, which started every service from the local images without
attempting a build and ran the assistant's launcher from inside its image.
The tag logic was exercised against sample tags: a later `v2.6.1` publishes
`2.6.1` and `2.6` but not `latest`.

Code review found three things, all fixed: a source upgrade's plain `build`
skips the profile-gated assistant, whose launcher now lives in its image
(documented: `--profile assistant` on both commands); every compose project on
a host now builds to one image name, so a throwaway or e2e build could retag
the image a real stack runs (e2e gets its own `:e2e` tag, CLAUDE.md's
throwaway check sets `ARGUESECURE_VERSION`); and an older patch release
would have moved `latest` backwards (only the newest release moves it).

### W102 — Control citations and a coverage view

PRODUCT.md's third job is showing that a framework's risk-assessment
requirement was met, and through 2.6 the only link between a defence and the
control it implements was the defence's title ("A.5.23 Information security
for use of cloud services"). A title can be edited into anything, cannot be
searched across assessments, and a report cannot group by it. W102 makes the
citation structured (D64), seeds it into the seven standards templates, and
adds a coverage view to the report (D65, D66): per framework, each cited
control, the defences citing it and whether they are in place.

- **`control` reference kind** (D64): `FRAMEWORK:CONTROL`, normalized to
  uppercase like every other identifier, validated against the framework's
  own pattern, its link derived from the framework, never taken from input.
  Frameworks: `ISO27001-2022`, `ISO42001-2023`, `EUAIACT`, `GDPR`, `NIS2`,
  `SOC2`, `CIS8`.
- **Templates**: every defence in `standard-*.json` whose title names a
  control cites it; the insurance transfers cite the clause their title
  names. Seeded only where the template has not been seeded before
  (`TemplateAssessmentSeeder`'s existence rule, unchanged).
- **Report**: a *Control coverage* section, on screen and in HTML, Word and
  Excel, after Transfers; absent when nothing cites a control.
- **References tab**: on a defence, *Control* is the kind offered first.

**Acceptance:** a `control` reference to an unknown framework, or with a
control part its framework's pattern rejects, or on a node that is not a
defence, answers 422, and a URL sent with one is ignored; every defence title
in the standards templates that names a control carries that control as a
reference, checked against the title by a test; a report of an assessment
started from a standards template lists every cited control under its
framework with the status D65 specifies, and honours `include_shelved` as
D66 does; all seven checks pass.
