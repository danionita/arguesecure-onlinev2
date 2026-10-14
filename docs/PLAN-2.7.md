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
| D67 | **Two more frameworks join D64's closed list**: `CSF2` (NIST Cybersecurity Framework 2.0), pattern `PR.AA-01` (a Category, `PR.AA`, also matches), and `CRA` (Regulation (EU) 2024/2847), pattern `ART.13(2)` or `ANNEX.I.I(2)(A)` (Annex I, Part I, point (2)(a)), which `ControlId::label()` prints as `Annex I Part I(2)(a)`. DORA and PCI DSS, ranked third and fourth by the research, are not added: the owner dropped them. | Each requires a risk assessment that an ArgueSecure graph can evidence (`docs/research/framework-templates.md`): CSF 2.0's ID.RA Category, CRA Art. 13's product risk assessment. D64 makes adding one a config entry; the codes, like the seven before them, are never renamed, which is why both patterns were written from the source text. |
| D68 | **Each template cites at the level an assessor checks, and no finer**: CSF 2.0, every Subcategory (106), never the Implementation Examples; CRA, Annex I's essential requirements and the manufacturer's obligations in Art. 13–14. | That is the size the existing templates already have (CIS 153 Safeguards, ISO 27001 93 controls), and what a workshop can argue through. Below it, CSF's Implementation Examples are how-to guidance an auditor's workflow uses, a non-goal (PRODUCT.md). |
| D69 | **A template is written from its source text and ships only when that text was read**: NIST CSWP 29 for CSF 2.0, EUR-Lex for CRA, every description a summary in our own words. | A coverage test enumerates each framework's identifiers (`StandardTemplateCoverageTest`); enumerating them from memory would make the test assert a guess. Standards text is copyrighted (the research skill); CSWP 29 is a US government work, but the summary rule keeps every template alike. |

---

## The packages

| # | Package | Owns |
|---|---|---|
| **W98** ✓ | Working agreements (D59–D61) | `.claude/skills/**`, `.claude/agents/**`, `.gitignore` (the `.claude` and `CLAUDE.local.md` rules), `CLAUDE.md` ("How work gets done here", the checks-in-containers note), `CLAUDE.local.md.example`, `docs/PRODUCT.md`, `docs/CLAUDE-PROJECT.md`, `tools/dev/checks/**` |
| **W99** ✓ | `CLAUDE.md` under 200 lines | `CLAUDE.md`, `.claude/rules/**`, `.claude/skills/**`, `docs/RELEASE-2.6.0.md` (§7 only), this plan's W99 entry |
| **W100** (built; one step left) | The checks in a Code Project thread | `tools/dev/checks/**`, `docs/CLAUDE-PROJECT.md`, CLAUDE.md's comment on `checks.sh` |
| **W101** ✓ (2.6.1) | Prebuilt images (D62, D63) | `.github/workflows/image.yml`, `docker-compose.yml` (the `image:` lines and the assistant's build and mounts), `docker/assistant/Dockerfile`, `.env.example` (the Image section), `docker-compose.e2e.yml` (its image tag), CLAUDE.md's throwaway-project sentence, `tools/spike/assistant/verify.sh` (the line reading the pin), `tests/Unit/Deployment/**`, `README.md` (Installing, Running it day to day), `docs/specs/deployment.md` (§2.4, §8 Upgrades, §9.3), this plan's W101 entry |
| **W102** ✓ (2.6.2) | Control citations and a coverage view (D64–D66) | a new `node_references` migration, `config/arguesecure.php` (`references`), `app/Domain/References/**`, `app/Http/Requests/Assessment/StoreNodeReferenceRequest.php`, `database/seeders/TemplateAssessmentSeeder.php` and `Support/AssessmentFixtureValidator.php` (references), `database/seeders/templates/standard-*.json` (defence `references` only), `app/Domain/Reporting/ReportAssembler.php` (`controlCoverage`), `app/Domain/Reporting/Export/ReportSectionBuilder.php` (its section), `resources/js/Components/NodeModal/NodeModal.vue` (the tab's node type), `resources/js/Components/NodeModal/tabs/{references.ts,ReferencesTab.vue}`, `resources/js/Components/Report/{ControlCoverageSection.vue,ReportDocument.vue,types.ts}`, `resources/markdown/help/{building,reading-a-report,templates}.md`, `docs/specs/schema.md` §3.12, `docs/specs/ui.md` §9, their tests |
| **W103** ✓ (2.6.3) | Two more standards templates: NIST CSF 2.0 and the EU CRA (D67–D69) | `config/arguesecure.php` (two `frameworks` entries), `app/Domain/References/ControlId.php` (`label()`, for the CRA's Annex Parts), `database/seeders/templates/standard-{nist-csf-2,eu-cra}.json` (new), `database/seeders/TemplateAssessmentSeeder.php` (its docblock's list), `tests/Feature/Seeders/{StandardTemplateCoverageTest,StandardTemplateControlCitationTest}.php`, `tests/Feature/References/ControlReferenceTest.php`, `resources/markdown/help/templates.md`, `README.md` (the standards table), `docs/specs/schema.md` §3.12 (the `control` paragraph) |
| **W105** ✓ (2.6.3) | Chart panels below a childless card are not cut off (bug fix) | `resources/js/Components/Chart/ChartView.vue` (the scroll container's height), `resources/js/Components/Chart/ChartCard.vue` (revealing its two floating panels), `tests/e2e/15-chart-view.spec.ts`, `tests/js/chart/**`, `docs/specs/ui.md` §3.7, this plan's W105 entry |
| W104, W106+ | To be planned from `docs/PRODUCT.md` and research | — |

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

### W103 — Two more standards templates

The seven standards templates leave out the most widely used voluntary
framework and the regulation every maker of connected products sold in the EU
now faces. Both require a risk assessment that a graph can evidence
(`docs/research/framework-templates.md`). W103 adds them in W102's shape:
every outcome or obligation a *todo* defence under the threats it addresses,
citing its control (D64), granular as D68 says, written from the source text
(D69). The owner asked for DORA and PCI DSS too, then dropped them.

- **NIST CSF 2.0** (`standard-nist-csf-2`): the 106 Subcategories of all six
  Functions as defences under three risks (risk taken unknowingly, data
  compromise, disruption) and fourteen threats, plus a cyber-insurance
  transfer. Its description says the ID.RA Category asks for what the
  assessment itself records.
- **EU Cyber Resilience Act** (`standard-eu-cra`): risks of a compromised
  product, of a vulnerability left unhandled, and of enforcement; Annex I's
  essential requirements and Art. 13–14's obligations (45) as defences; the
  application dates in its description.
- **Help, README, schema.md**: the new templates and codes.

**Acceptance:** each new template passes `AssessmentFixtureValidator`; a
`control` reference to each new framework is accepted with a well-formed
control part and answers 422 with a malformed one; `StandardTemplateCoverageTest`
finds every identifier D68 names exactly once in its template, enumerated from
the source text; every defence title in a new template that names a control
cites it (`StandardTemplateControlCitationTest`); all seven checks pass and
the image boots.

### W105 — Chart panels below a childless card are not cut off

Reported by the owner with a screenshot: in the chart view, *Add from
template* and *Suggest* on a card with nothing beneath it opened a panel that
was cut off a few pixels below the card. Both panels float below their card
(`absolute top-full`) inside the chart's scroll container, which clips them,
and that container had `max-h-[calc(100vh-13rem)]` — so it was only as tall
as the chart. A new assessment's root, or a chart collapsed to its root, left
the panel a strip the height of the container's bottom padding. The list view
is unaffected: its panels are in the flow.

The fix keeps the panels where they are and gives them room: the container's
height becomes fixed at the same viewport-bounded value (`h-` for `max-h-`),
so a short chart's panel has the rest of the window; and below the bottom
tier of a chart taller than the window, the card scrolls its panel into view
(`block: 'nearest'`) when it opens and when its content grows. Anchoring the
panels outside the container, as the parent counter's popover does (D31),
was not chosen: the picker is a workspace that stays open while several items
are taken, and an anchored panel would have to follow the card through
scrolling and zoom by measurement. No decision is reopened; no new one is
needed.

**Acceptance:** at 1280×800, with the chart collapsed to its root, the
template picker opened from the root is more than 100px tall and its bottom
edge is inside the scroll container's box (e2e scenario 15); a floating panel
is scrolled into view with `block: 'nearest'` when it opens (Vitest); the page
still does not scroll vertically (scenario 15's existing test).

