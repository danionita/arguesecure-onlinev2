# Implementation prompt for 2.5

> Paste this into a fresh Opus session at the repository root. It assumes
> `docs/PLAN-2.5.md` is in the checkout and that the branch point is
> `v2/integration-2.4` at `0f16271`.

---

Implement ArgueSecure 2.5 (W82–W88) per `docs/PLAN-2.5.md`, then hand me a
clean local deployment to test it on. Read `CLAUDE.md` first, then the plan in
full.

## Skills

This repository carries the superpowers skill set. The canonical copy is
`.github/skills/`, mirrored into `.claude/skills/` so the `Skill` tool loads
it. **If the two differ, `.github/skills/` wins — re-copy it.**

Use them:

| Skill | Where |
|---|---|
| `using-superpowers` | First, before anything else. |
| `subagent-driven-development` | The spine of this job — fresh implementer per package, task review after each, broad review at the end. Its `implementer-prompt.md`, `task-reviewer-prompt.md` and `re-review-prompt.md` are the dispatch templates; use them rather than improvising. |
| `using-git-worktrees` | Before dispatching anything. Seven packages, several parallel. |
| `dispatching-parallel-agents` | For the W83/W84/W86 fan-out. |
| `test-driven-development` | Every package. "Write the test with the code" is this repository's phrasing of the same rule. |
| `systematic-debugging` | **W88, mandatory.** Its Iron Law — no fixes without root cause investigation — is exactly D49's reproduce-first requirement. |
| `requesting-code-review` / `receiving-code-review` | After each package, and once over the integration branch. |
| `verification-before-completion` | Before any claim that a package or the release is done. |
| `finishing-a-development-branch` | At the end, after e2e. |

`writing-plans` and `brainstorming` do not apply — the plan exists and its
decisions are closed.

## Part 1 — a fresh local deployment, before any code

Do this first, so there is somewhere to see the work land and so a broken stack
is found now rather than at integration.

**This destroys the local database — 2 users, 7 assessments, 120 nodes. That is
intended.** Back it up anyway; it costs nothing:

```bash
docker compose exec -T db mariadb-dump -u root -p"$DB_ROOT_PASSWORD" \
  --all-databases > ../arguesecure-backup-$(date +%Y%m%d).sql
```

Then:

```bash
docker compose --profile assistant down
rm -rf ./data/mysql ./data/redis
docker compose up --build -d
```

**Delete `data/mysql` and `data/redis` and nothing else.** `data/models` holds
a 2.49 GB model file that must not be re-downloaded, and `data/storage` holds
`app/config/app.key`, written once on first boot and shared by every container
— the entrypoint aborts if it is malformed, and regenerating it invalidates
sessions for no reason here.

The entrypoint migrates and seeds `SystemScaleSeeder` on boot when
`RUN_MIGRATIONS=true`, which it is. It deliberately does **not** seed demo
content. Seed the rest by hand, because 2.5 has items about templates, the list
view and the report that need something to look at:

```bash
docker compose exec -T app php artisan db:seed --class='Database\Seeders\CatalogLibrarySeeder' --force
docker compose exec -T app php artisan db:seed --class='Database\Seeders\TemplateAssessmentSeeder' --force
docker compose exec -T app php artisan db:seed --class='Database\Seeders\SampleContentSeeder' --force
```

There is **no default admin account and you must not create one with a
committed credential.** An empty `users` table triggers the first-run setup
screen; leave it for me and tell me the URL. No password in any file, scratch
files included.

Bring the assistant back up — already configured in `.env`, model mounted at
`data/models`:

```bash
docker compose --profile assistant up -d
```

Per `verification-before-completion`, confirm with output rather than
assertion: `/up` returns 200, seven services healthy, and
`Availability::state()` reports `disabled`. That last one is correct, not a
fault — the instance setting lived in the database you just deleted.

## Part 2 — the packages

**The decision table D39–D51 is closed.** If you think one is wrong, say so and
stop — do not implement the alternative.

**D44 is the one to read twice.** The requirement is *"I don't want code
displayed in the UI — behind the scenes you can use it"*, and that splits into
two jobs. Remove it from every surface, including the exported methodology
table, which prints it as a column today. Keep it in the database, the
catalogue's resolution path and §8.3's payload — an id cannot replace it,
because a custom scale is cloned per assessment and its level ids do not exist
until the clone runs, so a catalogue fixture shipped once cannot name them.
Derive it on create, freeze it on rename. A package that drops the column or
re-derives it on rename has misread the plan, and the second fails silently —
the same shape as W78's silent half.

**Items 21 and 22 are reproduce-first (D49).** Invoke `systematic-debugging`
and obey its Iron Law. W88 carries a specific hypothesis — per-keystroke
autosave whose own success response bumps `updated_at`, so the next keystroke
409s against itself. **It is a hypothesis, not a finding.** A hypothesis handed
to an implementer reads as a diagnosis, which is how a symptom fix gets
written; make the subagent reproduce before it fixes. If it cannot reproduce,
that is a report to me, not a licence to change code until the symptom stops.

### Subagents, and which model each gets

One implementer per package, dispatched per `subagent-driven-development`, each
given **its own section of the plan plus `CLAUDE.md`'s ground rules** — never
the whole plan in the hope it finds its scope, and never your session history.

Spend the expensive model where the risk is, not evenly:

| Package | Model | Why |
|---|---|---|
| W82 — action bar, sidebar | `sonnet` | Vue composition against chrome W81 already factored out. |
| W83 — report as a view | `sonnet` | One real trap (the guest path), and it is written down. |
| W84 — zoom, density, centring | `sonnet` | Mechanical, but read W69's `CANVAS_BASE_SCALE` docblock before touching a constant. |
| W85 — the node card | `haiku` | Genuinely small: mount an existing trigger on a second card, swap a chip. Escalate to `sonnet` if the three-gate test fights it. |
| W86 — list, dialogs, settings | `sonnet` | Four independent small changes. |
| W87 — scales | **`opus`** | Largest package, a schema migration, and D44's trap. Do not economise here. |
| W88 — the modal's two defects | **`opus`** | Reproduce-first debugging of an intermittent race. A cheap model will find a plausible story. |

Task reviewers may run on `sonnet` except for W87 and W88, which get `opus`
reviewers — a reviewer cheaper than the implementer is theatre.

Sequencing from the plan: W82 first; then W83, W84 and W86 in parallel; W85
then W88; W87 independent and can start immediately.

**No two packages own the same file.** A subagent that needs a file another
package owns must stop and report rather than edit it — **surface that to me,
do not arbitrate it yourself.** 2.4 produced eight such collisions and every one
was a real decision. This overrides `subagent-driven-development`'s "continuous
execution": ownership collisions and closed-decision challenges are the two
things worth interrupting me for.

### Per package

- Branch `v2/w<NN>-<slug>`, commit with the package id in the message, never to
  `master`. **Commit each coherent unit as you go** — a session can end without
  warning, and 2.4 lost work three times to that.
- **Mutation-test every guard** — neuter it, confirm the test goes red, restore
  it. Assert the specified value, not that something is present. W82's print
  rules and W87's three assistant switches are both the shape W68 caught: a
  test that stayed green while the thing it guarded was removed.
- Run the full check, and actually run it: `vendor/bin/pint --test`,
  `vendor/bin/phpstan analyse`, `php vendor/bin/pest`, `npm run lint:check`,
  `npm run format:check`, `npm run type-check`, `npm run test:unit`.
- Update the specs in the same commit as the code they describe.
- Pin, as a test asserting today's behaviour, any defect found in a file the
  package does not own. The table is empty as of 2.4.0; that is a fact about
  today, not a rule.

### Environment

- Run Pest on the host: `php vendor/bin/pest`. The `app` container is a
  production build with no dev dependencies and no source mount.
- Use the Write tool for multi-line files. A large Bash heredoc times out and
  wedges the shell here.
- Give each parallel package its own database and Redis DB. Real environment
  variables override `phpunit.xml`.
- **Do not junction `node_modules` into a worktree — run `npm ci` in it.** A
  recursive worktree delete follows the junction and empties the target. This
  happened in 2.4 and cost the main checkout its `node_modules`.
- `tests/e2e/**` is not part of `php artisan test`. Do not run it per package.
- Work against the Part 1 stack; do not point tests at it.

### The assistant is available this time

Unlike 2.4, this machine has a working runtime: `ASSISTANT_ENABLED=true`,
`gemma-3-4b-it-Q4_K_M` mounted from `data/models`, started with the `assistant`
compose profile. Measured here: help 96s cold / 9.7s warm, suggestions 31s.

So W85's and W88's assistant work can be exercised end to end, and the
assistant e2e gap 2.4 left under *Still open* is closeable in this plan. Do not
download weights at run time and do not commit a model file.

## Part 3 — ending

Merge onto a single integration branch. Dispatch the broad whole-branch review
(`requesting-code-review`), then run the full e2e suite once against a
throwaway stack per `tests/e2e/README.md` — its own warning applies: a
double-digit failure count means re-run before investigating, and find the
earliest failure rather than the loudest. 2.4's first e2e run returned 105
failures with a single root cause.

Then redeploy the local stack from the integration branch so what I am testing
is what you built, write `docs/RELEASE-2.5.0.md` in the shape of
`RELEASE-2.4.0.md` including a "Still open" section, and use
`finishing-a-development-branch` to present the integration options — but
**report back before tagging anything**.

Report honestly: if a check fails, show me the output; if you skipped
something, say which and why.
