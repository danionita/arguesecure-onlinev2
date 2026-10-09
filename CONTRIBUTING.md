# Contributing to ArgueSecure

ArgueSecure 2 is a rewrite on Laravel 12, Inertia and Vue 3; the 1.x
application survives only in git history, at the `v1-final` tag. Start with
[`docs/PLAN.md`](docs/PLAN.md) — the master plan, the settled decisions and
the work-package table — then the latest release notes
([`docs/RELEASE-2.5.0.md`](docs/RELEASE-2.5.0.md), whose "Still open" is the
list to read before proposing anything), and the relevant file under
[`docs/specs/`](docs/specs) for whatever area you're touching. If the specs
and this file disagree, the specs win.

## Setting up a development environment

Requirements: Docker and Docker Compose for the stack; PHP 8.3 (with
`pdo_mysql`, `redis`, `intl`, `bcmath`, `zip`, `pcntl`, `gd`), Composer and
Node 22 to run the checks.

```bash
git clone https://github.com/danionita/arguesecure-online-v2.git
cd arguesecure-online-v2
cp .env.example .env
docker compose up --build -d
composer install
npm ci
```

See the [README](README.md) for what `docker compose up` does. The `app`
image is a production build without development dependencies, so the checks
run on the host: `docker-compose.override.yml` publishes MariaDB and Redis on
`127.0.0.1`, and the test suite reads its connection from a `.env.testing`
you write yourself — `phpunit.xml`'s comments say exactly what goes in it.
Never commit that file; no credential belongs in a tracked file.

## Ground rules

1. **Read the spec for the area you're changing** before writing code —
   [`docs/specs/schema.md`](docs/specs/schema.md) for database/model/policy
   work, [`docs/specs/calculation-engine.md`](docs/specs/calculation-engine.md)
   (normative — implement exactly what's written) for anything touching
   quantification, [`docs/specs/ui.md`](docs/specs/ui.md) for Vue/layout/
   interaction, [`docs/specs/security.md`](docs/specs/security.md) for auth
   and input handling, [`docs/specs/deployment.md`](docs/specs/deployment.md)
   for Docker/CI/operations.
2. **Every route touching an assessment goes through `AssessmentPolicy`.**
   No exceptions — the legacy application's unauthorized `show` endpoints
   are a defect this rewrite exists to fix.
3. **Never write a secret, key, password, or credential into any file** —
   including seeders, factories, tests, fixtures, and documentation. There
   is no default admin account and none should ever be seeded.
4. **Never `$request->all()`.** Use `validated()` or an explicit
   `only([...])`.
5. **Write the test with the code.** A change that adds a route adds its
   authorization test in the same commit.
6. **Business logic goes in `app/Domain/`**, not in controllers and never
   in views.
7. **Free and open-source dependencies only**, ever. Never reintroduce
   `dompdf`, `laravelcollective/html`, `lord/laroute`, `laravel-elixir`,
   `swiftmailer`, `socket.io`, jQuery, Bootstrap, or memcached — all were
   removed for good reasons documented in `docs/specs/security.md`.

## Checks before opening a change

All of these must pass:

```bash
vendor/bin/pint --test           # code style
vendor/bin/phpstan analyse       # static analysis, level 6+
php artisan test                 # Pest
npm run lint:check               # ESLint, --max-warnings 0 (npm run lint autofixes)
npm run format:check             # Prettier
npm run type-check               # vue-tsc --noEmit
npm run test:unit                # Vitest
```

And deployability, which is a non-functional requirement checked throughout
development, not only at the end:

```bash
docker compose up --build -d && curl -f http://localhost:8000/up
```

CI runs on Linux and is case-sensitive; local development on Windows or
macOS is not. Get file-path casing exactly right in anything referenced from
PHP or TypeScript — `resources/js/Pages/...` component paths in particular —
because a mismatch is invisible locally and fails only in CI.

## Architecture, briefly

```
┌──────────────────────────────────────────────────────────────┐
│  docker compose                                              │
│  app        nginx + php-fpm 8.3   (Laravel 12 + Inertia)     │
│  reverb     php artisan reverb:start        (websockets)     │
│  queue      php artisan queue:work          (recalc jobs)    │
│  scheduler  php artisan schedule:work       (cleanup)        │
│  db         mariadb:11                                       │
│  redis      redis:7-alpine    (cache + queue + locks)        │
└──────────────────────────────────────────────────────────────┘
```

Requests are server-routed by Laravel and rendered by Vue through Inertia —
there is no separate API to version and no client-side router. Authorization
lives in one place (`AssessmentPolicy`), never duplicated per node type.

**Data model.** One `nodes` table with single-table inheritance on
`type ∈ {root, risk, attack, defence}`. A *Transfer* is a defence with
`is_transfer = 1` — a presentation variant, not a database type. The
structure is a **DAG, not a tree**: an attack may belong to several risks, a
defence to several attacks, so edges live in `risk_attack` and
`attack_defence` rather than a `parent_id` column. Full detail in
[`docs/specs/schema.md`](docs/specs/schema.md).

**Calculation engine.** `App\Domain\Calculation\RiskCalculator` is pure — no
Eloquent, no I/O, no clock, no randomness, enforced by an architecture test.
It accepts only an `AssessmentGraph` DTO and computes all three baselines
(inherent, current, target) in one pass. A TypeScript mirror
(`resources/js/calc/`) gives the client instant feedback while editing; the
server's broadcast result is always authoritative. Both implementations are
checked against the same golden-vector fixture
(`tests/fixtures/calculation-vectors.json`) to `1e-9`. The formulas
themselves are normative in
[`docs/specs/calculation-engine.md`](docs/specs/calculation-engine.md) —
read it before changing anything under `app/Domain/Calculation/` or
`resources/js/calc/`.

**Real-time.** Laravel Reverb over presence channels scoped per assessment,
authorized through `AssessmentPolicy`. Editing locks (since 2.5.1, one
editor per node while its modal is open) live entirely in Redis with a TTL.
There is no lock table and no sweep job; the TTL *is* the sweep.

## Commit and PR conventions

- Branch names follow `v2/<work-package-id>` during the rewrite (see
  `docs/PLAN.md`'s work-package table).
- Keep a change inside the file ownership its work package declares. If a
  change genuinely needs to touch a file outside that ownership, say so
  explicitly in the PR rather than editing it quietly.
- Commit messages should explain *why*, not restate the diff.
