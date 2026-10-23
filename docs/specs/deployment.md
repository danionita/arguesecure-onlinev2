# Deployment Specification

> Implements Phase 1 of [`docs/PLAN.md`](../PLAN.md). Work package **W3**, with the entrypoint and CI touching **W2**.
>
> Related: [security.md](security.md) for TLS, cookies, CSP and the websocket exposure decision.

---

## 1. Target environments

**Primary:** an Unraid server, deployed through Compose Manager, behind a SWAG reverse proxy that terminates TLS. This is where the instance actually runs, and the constraints it implies are real.

**Secondary:** any Docker host. `docker compose up -d` from a clean checkout, with only `.env` filled in, must work unchanged. Nothing may hardcode an Unraid path or the owner's domain.

**Non-functional constraints from the project brief:**

- Easy to deploy via Docker Compose.
- Modern stack, stable OS libraries, robust architecture.
- Resource efficient — target **under 512 MB total across all containers at idle**.
- No paid libraries or services.

---

## 2. Services

Six containers, three of which are the same image with a different command.

| Service | Image | Command | Ports | Purpose |
|---|---|---|---|---|
| `app` | built | nginx + php-fpm via supervisord | `${APP_PORT:-8000}:80` | HTTP |
| `reverb` | built (same image) | `php artisan reverb:start` | **internal only** | Websockets |
| `queue` | built (same image) | `php artisan queue:work --tries=3 --max-time=3600` | — | Recalculation jobs |
| `scheduler` | built (same image) | `php artisan schedule:work` | — | Purges, backups |
| `db` | `mariadb:11` pinned by digest | — | internal | Data |
| `redis` | `redis:7-alpine` pinned by digest | — | internal | Cache, queue, locks, broadcast |

**Redis replaces memcached entirely.** The legacy stack ran both; Redis is needed anyway for locks and the queue, so memcached is one fewer moving part. The 1.x deployment set `CACHE_DRIVER=memcached`; the cache is `redis` here, and the example file that carried the old values has been deleted rather than left beside the current one for someone to copy by mistake. Sessions are the exception: they are stored in MariaDB's `sessions` table, fixed in `config/session.php` rather than read from `SESSION_DRIVER`, because signing other devices out deletes rows there ([security.md §5](security.md#5-sessions-and-cookies), D71). 2.0–2.6 kept them in Redis, where no revocation reached them.

**The Node websocket container is deleted.** Reverb is a PHP process on the same image, so the deployment has one language.

### 2.1 Reverb is not published to the host

The legacy compose published port 3002 directly, which is how the unauthenticated relay became reachable ([security.md §1](security.md#1-why-this-document-exists), defect 2). Reverb stays on the internal network and nginx proxies `/app` and `/apps` to it, so websockets inherit the app's TLS, hostname and origin.

This also removes the legacy `WEBSOCKET_IP` / `WEBSOCKET_PORT` variables and the second SWAG proxy entry they required.

**The proxy target is the `reverb` service, not `127.0.0.1`** (W27). The `app` container runs php-fpm and nginx and nothing else; Reverb is its own container on the compose network, addressed by service name exactly as `db` and `redis` are. nginx must reach it through a `resolver` and a variable upstream rather than a literal one, because a literal upstream name is resolved once at startup and nginx refuses to start when it does not resolve — which it would not on any boot where `reverb` comes up after `app`.

**The browser's app key comes from the server, not from the bundle** (W27). It is shared onto every Inertia page by `HandleInertiaRequests` and read by `useRealtime.ts`. Vite substitutes `import.meta.env.VITE_*` when the image is *built*, but §6 below generates the Reverb credentials when the container first *starts* — so a `VITE_REVERB_APP_KEY` is always empty in a deployed bundle and real-time collaboration was inert in every deployment until this changed. The app key is a public identifier that appears in the WebSocket URL anyway; the secret is never shared.

Three variables therefore mean two different things and must not be conflated:

| | Who reads it | Correct value under compose |
|---|---|---|
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | the **app**, posting broadcasts to Reverb | `reverb` / `8080` / `http` — the internal service |
| `REVERB_CLIENT_HOST` / `_PORT` / `_SCHEME` | the **browser**, dialling the websocket | unset, meaning "this page's own origin" — which is what makes one built image work on any hostname, port and scheme |

Set the `REVERB_CLIENT_*` trio only for a proxy-less local run (`php artisan serve` alongside `php artisan reverb:start`), where there is no nginx to proxy `/app`.

### 2.2 Compose skeleton

```yaml
services:
  app:
    build: { context: ., dockerfile: Dockerfile }
    restart: unless-stopped
    ports: ["${APP_PORT:-8000}:80"]
    env_file: .env
    volumes:
      - ${DATA_PATH:-./data}/storage:/var/www/html/storage
    depends_on:
      db:    { condition: service_healthy }
      redis: { condition: service_healthy }
    networks: [arguesecure]

  # reverb, queue, scheduler: same build, different command, no ports

  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: "${DB_ROOT_PASSWORD}"
      MARIADB_DATABASE:      "${DB_DATABASE}"
      MARIADB_USER:          "${DB_USERNAME}"
      MARIADB_PASSWORD:      "${DB_PASSWORD}"
    volumes: [ "${DATA_PATH:-./data}/mysql:/var/lib/mysql" ]
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 10
    networks: [arguesecure]

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: ["redis-server", "--appendonly", "yes", "--maxmemory", "128mb", "--maxmemory-policy", "noeviction"]
    volumes: [ "${DATA_PATH:-./data}/redis:/data" ]
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 3s
      retries: 5
    networks: [arguesecure]

networks:
  arguesecure: { driver: bridge }
```

### 2.3 The `DATA_PATH` variable

The legacy compose hardcoded `/mnt/apps/appdata/arguesecure/...`, which is correct for Unraid and broken everywhere else. Replace with `${DATA_PATH:-./data}`, documented in `.env.example` as:

```
# Unraid:  DATA_PATH=/mnt/apps/appdata/arguesecure
# Elsewhere: leave unset to use ./data
DATA_PATH=
```

**Do not bind-mount `.env` into the container** the way the legacy compose did. Use `env_file:` only. The bind mount existed so `artisan` could write to it; nothing in the rewrite writes to `.env`.

### 2.4 Published images (W101)

Every release is published to GitHub's container registry by `.github/workflows/image.yml`, so a deployment is `docker-compose.yml` and a `.env` — no checkout, no build, no Node or Composer traffic on the host (D62).

| Image | Built from | Used by |
|---|---|---|
| `ghcr.io/danionita/arguesecure` | `Dockerfile` | `app`, `reverb`, `queue`, `scheduler` |
| `ghcr.io/danionita/arguesecure-assistant` | `docker/assistant/Dockerfile`: the digest-pinned llama.cpp image plus `assistant.sh` and `models.conf` (D63) | `assistant`, `assistant-install` |

| Git ref | Tags published |
|---|---|
| `v2.7.0` | `2.7.0`, `2.7`, `latest` |
| `v2.7.0-rc1` (any other `v*` tag) | `2.7.0-rc1` only |
| a manual run of the workflow | `sha-<commit>`, `edge` |

`ARGUESECURE_VERSION` in `.env` chooses the tag (default `latest`); `ARGUESECURE_IMAGE` overrides the repository, for a fork. Both published images always carry the same tag, so a pulled app and assistant come from the same commit and read the same `models.conf`. A source build keeps that true only if it rebuilds the assistant too (§8). Only `linux/amd64` is published: the deployments are x86 servers, and an emulated arm64 build would compile PHP extensions and run Vite under QEMU.

The sidebar shows the running version (W124, D90). It is the release tag, passed by `image.yml` as the build argument `ARGUESECURE_BUILD_VERSION`, recorded by the `Dockerfile` as an environment variable and read by `config/arguesecure.php` (`version`); it is not `ARGUESECURE_VERSION`, which is only the tag a deployment pins. A build with no argument, such as a source checkout's `docker compose build`, reads the repository's `VERSION` file (the last release; W129), and shows `dev` only if that file is missing; a manual run shows `edge · <commit>`. The "Release notes" link goes to `docs/RELEASE-x.y.z.md` at tag `vx.y.z` in the public repository, so every release must ship that file; other values link to the releases list.

Each service keeps `build:` beside `image:`. Compose pulls an image it does not have and builds only when the pull fails; `--build` always builds. A source checkout therefore works exactly as before, and its local build carries the published name until the next `docker compose pull` replaces it. Because every compose project on a host now builds to that one name, a second project on the same host — a throwaway deployability check, or the e2e stack, which `docker-compose.e2e.yml` gives its own `:e2e` tag — must set `ARGUESECURE_VERSION` to a name of its own; otherwise its build retags the image the real stack runs, and that stack's next `up` recreates itself from it and migrates its database with it.

Before anything is pushed, the workflow boots the new images from a directory holding nothing but `docker-compose.yml` and a `.env`, with `--pull never`, and requires `/up` and the setup screen to answer and `assistant-install list` to read the catalogue from inside the assistant image. That is the acceptance test for the whole arrangement, run on every release. `tests/Unit/Deployment/ComposeImageDeployTest.php` keeps the compose file from drifting between releases: every buildable service names an image, and no service bind-mounts a path from the checkout.

The packages are **public** (decided 2026-10-12), so pulling needs no login. The workflow builds and boots the image in either repository but its Publish step runs only in the public one (`github.repository == 'danionita/arguesecure-onlinev2'`): its tree carries no agent instructions, and its release tags publish the images. Compose builds when a pull fails, so the same compose file serves a source build and an image-only deployment.

---

## 3. Dockerfile

Multi-stage, `php:8.3-fpm-alpine` as the runtime base.

**Stage 1 — assets.** `node:22-alpine`; copy `package*.json`; `npm ci`; copy sources; `npm run build`.

**Stage 2 — vendor.** `composer:2`; copy `composer.*`; `composer install --no-dev --no-scripts --optimize-autoloader --prefer-dist --ignore-platform-req=ext-gd`; then copy the app and run `composer dump-autoload --optimize`.

The `--ignore-platform-req` is scoped to **one extension by name**, never blanket, so a genuinely missing requirement in a future dependency still stops the build. It is there because `composer:2` has no `gd` while `phpoffice/phpspreadsheet` and `phpoffice/phpword` (W74) hard-require it — and this stage never runs the application: with `--no-scripts` it fetches dists and writes an autoloader, so its own extensions are not the ones that matter. It is the same premise `composer.json`'s `config.platform.php` already rests on: describe the target, not the builder. What makes that honest is stage 3 asserting the extension is really loaded; the flag would be a hole without it.

**Stage 3 — runtime.** `php:8.3-fpm-alpine` with:

- Extensions: `pdo_mysql`, `redis` (pecl), `opcache`, `intl`, `bcmath`, `zip`, `pcntl` (Reverb and queue need it for signal handling), `mbstring`, `gd` (hard requirement of `phpoffice/phpspreadsheet` and `phpoffice/phpword`, configured `--with-freetype --with-jpeg`; `libpng`/`libjpeg-turbo`/`freetype` ship in the runtime layer and their `-dev` halves are removed with `.build-deps`).
- **A build step that asserts `gd` actually loaded**, kept as its own `RUN` so it fails at a line whose name says what went wrong. Folded into the install it would surface instead as a fatal in a queue worker on a running host, hours later — and it is what licenses the vendor stage's `--ignore-platform-req`.
- `nginx` and `supervisor`.
- Vendor from stage 2, built assets from stage 1.
- A non-root user (`www-data`), owning `storage/` and `bootstrap/cache/`.
- `HEALTHCHECK` hitting `/up` (Laravel 12's built-in health route).

**opcache** — `opcache.enable=1`, `validate_timestamps=0` (the image is immutable), `memory_consumption=128`, `max_accelerated_files=20000`, JIT enabled in tracing mode.

Nginx serves `public/` directly, passes `.php` to php-fpm on a unix socket, and proxies `/app` to `reverb:8080` with the websocket upgrade headers.

**Pin base images by digest** and refresh deliberately. The legacy Dockerfile pinned `php:7.1-apache` and had to be patched to repoint apt at `archive.debian.org` once Buster went EOL — the lesson being that a floating tag on an EOL base is worse than a pinned one you update on purpose.

---

## 4. Reverse proxy

The instance runs behind SWAG, which terminates TLS and forwards plain HTTP. Without explicit proxy trust, Laravel generates `http://` URLs and the browser blocks them as mixed content. **This is recently-earned, hard-won knowledge — preserve it.**

Laravel 12 configures this through the `TrustProxies` middleware in `bootstrap/app.php`, not the legacy `AppServiceProvider` block:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(
        at: ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
        headers: Request::HEADER_X_FORWARDED_FOR
               | Request::HEADER_X_FORWARDED_PORT
               | Request::HEADER_X_FORWARDED_PROTO
               | Request::HEADER_X_FORWARDED_AWS_ELB,
    );
})
```

Two decisions carried over verbatim from the legacy fix, both deliberate:

1. **Only private ranges are trusted**, never `*`. Trusting every proxy lets any client spoof `X-Forwarded-*`.
2. **`X-Forwarded-Host` is deliberately excluded** from the trusted header set, to prevent Host-header injection (poisoned absolute URLs). Scheme, IP and port forwarding are still honoured. Note that omitting `HEADER_X_FORWARDED_HOST` from the bitmask is the Laravel 12 equivalent of the legacy `setTrustedHeaderName(HEADER_CLIENT_HOST, '')` call.

3. **php-fpm sees the proxy's address, not the client's.** The image's nginx uses `real_ip` to log the visitor's address, which rewrites `$remote_addr`; `docker/nginx.conf` therefore passes `$realip_remote_addr` as `REMOTE_ADDR`. Until 2.6.2 it passed the visitor's address, so a visitor from a public IP was not a trusted proxy, `X-Forwarded-Proto` was ignored, and the page's assets came out `http://` and were blocked as mixed content. Every test client is on a private address, so only a real deployment showed it. `tests/Unit/Deployment/NginxProxyTrustTest.php` guards it.

Set `APP_URL` to the real public HTTPS URL. It is what queued jobs and the Reverb host use, since they have no incoming request to infer from.

**SWAG configuration** — one proxy entry, not two. The legacy setup needed a second entry for port 3002; now `/app` is proxied inside the same server block with `proxy_set_header Upgrade $http_upgrade;` and `proxy_set_header Connection "upgrade";`.

---

## 5. Environment variables

`.env.example` documents every variable with **no real values**. Group and comment them.

| Variable | Notes |
|---|---|
| `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL` | `APP_DEBUG=false` in every deployed environment |
| `APP_KEY` | **empty in the example.** Generated on first run — see §6 |
| `DATA_PATH`, `APP_PORT` | host-side placement |
| `DB_*`, `DB_ROOT_PASSWORD` | `DB_HOST=db` |
| `REDIS_HOST=redis`, `REDIS_PASSWORD` | |
| `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `BROADCAST_CONNECTION=reverb` | |
| `SESSION_SECURE_COOKIE=true`, `SESSION_LIFETIME=480` | `SESSION_DRIVER` is not read: sessions are always in the database (D71). A `SESSION_DRIVER=redis` left in an older `.env` is harmless and can be deleted |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | generated on first run alongside `APP_KEY` |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | where the **app** posts broadcasts to: the internal service, `reverb` / `8080` / `http` — see §2.1 |
| `REVERB_CLIENT_HOST`, `REVERB_CLIENT_PORT`, `REVERB_CLIENT_SCHEME` | where the **browser** dials. Unset means "this page's own origin", which is correct behind the nginx proxy. Set only for a proxy-less local run |
| `ARGUESECURE_DEFAULT_CURRENCY`, `ARGUESECURE_AMORTIZATION_YEARS` | product defaults |
| `TRASH_RETENTION_DAYS` | default 30 |
| `SECURITY_LOG_RETENTION_DAYS` | default 90, at least 1; days the admin Security log keeps stored events (W128). Stderr is not affected. |
| `ASSISTANT_ENABLED` | **default `false`, and the common case.** Not merely a feature flag: when it is false the assistant routes are never registered, so the feature 404s rather than 403s (D25). Everything else in `config/assistant.php` is inert while it is off |
| `ASSISTANT_MODEL_PATH`, `ASSISTANT_MODEL_FILE`, `ASSISTANT_MODEL`, `ASSISTANT_THREADS` | where the mounted GGUF is, which file, the label the admin page shows, and how many cores the runtime may take — §9 |
| `ASSISTANT_BASE_URL`, `ASSISTANT_*_TIMEOUT`, `ASSISTANT_HEALTH_TTL`, `ASSISTANT_MAX_TOKENS_*`, `ASSISTANT_CONTEXT_TOKENS` | all optional with working defaults. **Read `config/assistant.php` before changing a timeout**: they are deliberately enormous because CPU inference runs for minutes, and every HTTP client in wide use defaults to one to two orders of magnitude less |

The assistant variables are the one group where setting them is not enough to switch anything on, and where an operator who sets them and sees nothing is looking at correct behaviour. Three separate switches gate it — this environment flag, the profile-gated container, and an admin toggle that does not render until the first two hold. [`docs/ASSISTANT.md`](../ASSISTANT.md) is the single document that says so, and the admin settings page points at it; §9 below is the operational half.

**Removed from the legacy set:** `HEARTRATE`, `LOCK_TIME` (locking is Redis TTL now), `WEBSOCKET_IP`, `WEBSOCKET_PORT` (Reverb is proxied), `MEMCACHED_HOST`, and all `MAIL_*` — there is no SMTP dependency, since accounts are admin-created and passwords are reset in the admin UI.

**Never call `env()` outside a config file.** The legacy app called `env('LOCK_TIME')` inside a model and `env('HEARTRATE')` inside middleware, which silently returns `null` once `config:cache` runs — and `composer install` ran `artisan optimize`. That is a latent production bug; the rewrite reads everything through `config()`.

---

### 5.1 Single sign-on (optional)

Enter the provider on **Admin → Authentication**, or set `OIDC_ISSUER`, `OIDC_CLIENT_ID` and `OIDC_CLIENT_SECRET` (see `.env.example`; a value set there wins over one stored on the tab, field by field, so a bad stored value is fixed by editing `.env`), and register `<APP_URL>/auth/oidc/callback` as the redirect URI at the provider (Microsoft Entra ID, Google Workspace, Okta, Keycloak, Authentik, Authelia, or any OpenID Connect provider with discovery). The app container must reach the provider over https; the provider needs no inbound access to it. `OIDC_ALLOWED_DOMAINS` lets a first sign-in create a non-admin account for those email domains (empty creates none); the provider must send `email_verified`, or set `OIDC_TRUST_EMAIL=true` for a provider you control that does not. Leave `OIDC_ISSUER` empty for no SSO. Only Keycloak has been tested against this build; every other provider, Microsoft Entra ID included, is expected to work because they speak standard OpenID Connect, but has not been verified. **Microsoft Entra ID (written from Microsoft's documentation, not verified):** register a web application, add the redirect URI above, create a client secret, and use `https://login.microsoftonline.com/<tenant-id>/v2.0` as the issuer. Entra's ID tokens carry the `email` claim only if you add it as an optional claim, and do not carry `email_verified`: for a single-tenant app whose directory you control, set `OIDC_TRUST_EMAIL=true`; do not do so for a multi-tenant app. Keep an administrator able to sign in with a password: it is the way in if the provider is down.

## 6. First run and the entrypoint

The entrypoint script, before starting supervisord:

1. Wait for `db` and `redis` (the compose healthchecks make this mostly redundant, but the queue and reverb containers start independently).
2. **If `APP_KEY` is empty, generate one and persist it** to a writable file under `${DATA_PATH}/config/app.key`, then export it. This is what makes "copy `.env.example`, run compose up" actually work without shipping a key. Log loudly that a key was generated and that losing it invalidates all encrypted cookies. Same treatment for the three `REVERB_*` secrets.
3. `php artisan migrate --force`.
4. `php artisan config:cache route:cache view:cache event:cache`.
5. `php artisan storage:link`.
6. Fix ownership on `storage/` and `bootstrap/cache/`.

**Only the `app` container runs migrations.** Guard with a `RUN_MIGRATIONS=true` variable set on `app` only, so three containers do not race on startup.

**No seeding of users, ever.** The app detects an empty `users` table and serves the first-run setup screen ([security.md §4](security.md#4-authentication)). This replaces the legacy `migrate.sh`, which piped a hardcoded `admin` / `XDARSEC` account into `artisan tinker` on every container start.

There is **no default `db:seed`** (2.5.1): `DatabaseSeeder`, which created an administrator with a fixed address, was deleted.

**Reference content is the exception, and arrives with the first admin** (W27). Through 2.6 templates needed an owning user, and at first boot there was none, so a fresh deployment reached the dashboard with no templates. Since W141 (2.7.0) templates have no owner, but `SetupController` still calls `SampleContentSeeder::seedReferenceContent()` the moment the first administrator is created, which keeps first-run behaviour unchanged. It seeds every bundled template — including the demo web shop, a template since 2.5.1 — and the catalogue entries. Both halves are idempotent, and no credential is created anywhere. An instance whose first admin predates a fixture can run `php artisan db:seed --class='Database\Seeders\SampleContentSeeder' --force`, which needs no administrator and creates no users.

---

## 7. CI

GitHub Actions on every push and pull request **in the public repository**. Every job is guarded by `github.repository == 'danionita/arguesecure-onlinev2'`, so in the private development repository they are skipped and cost nothing (D80). There the owner runs the same checks on their own machine: `tools/dev/checks/checks.sh` for the seven code checks, and the Docker, end-to-end and audit steps below by hand.

| Job | Steps |
|---|---|
| `php` | PHP 8.3, `composer install`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (level 6+), `php artisan test` (Pest) against MariaDB and Redis service containers |
| `js` | Node 22, `npm ci`, `npm run lint`, `npm run type-check` (`vue-tsc --noEmit`), `npm run test:unit` (Vitest) |
| `docker` | `docker compose build`, `docker compose up -d`, poll `/up` until healthy, assert HTTP 200 on the login page, `docker compose down -v` |
| `security` | `composer audit --locked`, `tools/dev/audit-gate.mjs` over `npm audit` of the whole lockfile (D86: every npm package is a devDependency, so `--omit=dev` always reports 0), a secret scan (gitleaks or equivalent) |
| `e2e` (W25) | Playwright against the composed stack |

The `docker` job is the one that catches deployability regressions, which are silent and expensive to find late. Pin every action to a commit SHA.

---

## 8. Operations

### Backups

- A documented `mysqldump` recipe in the README, runnable against the `db` container.
- A scheduled `arguesecure:backup` command writing every assessment as JSON to `storage/backups`, retained for N days. JSON export doubles as the portability format, so a backup is also a migration path to another instance.
- `storage/backups` is gitignored and must not sit inside a publicly-served path.

### Upgrades

`docker compose pull && docker compose up -d` from the published image (§2.4), after changing `ARGUESECURE_VERSION` if it pins one; `git pull && docker compose build && docker compose up -d` from a source checkout — with `--profile assistant` on both commands where the assistant runs, because `build` skips profile-gated services and the assistant's launcher and catalogue now live in its image rather than a mount. Migrations run automatically from the entrypoint. Document a `mysqldump` before any upgrade.

### Logs

All containers log to stdout/stderr for `docker compose logs`. No log files inside the image or on the data volume. The image sets `LOG_CHANNEL=stderr` and `LOG_LEVEL=info` (W112): an artisan service (queue, scheduler, Reverb) writes to its container's stderr directly, and a web request's lines reach the app container's through php-fpm's `catch_workers_output`. A value in `.env` overrides either; `.env.example` lists both, commented out, so the image stays the one source of the default. `LOG_LEVEL=debug` while diagnosing, `warning` for a quieter log; the change takes effect when `docker compose up -d` recreates the containers, which is when Compose re-reads `.env`.

Until 2.7 neither was set, so Laravel fell back to `stack` → `single`: an unrotated `storage/logs/laravel.log` on the data volume, at `debug`. An upgraded instance stops writing to it; the old file can be deleted. CI's Docker job asserts both halves on the booted image: an application log line on the container's stderr, and no `laravel.log`.

**The security event log (W111)** is a separate channel (since W128 also stored for 90 days and downloadable from Admin → Security log, below), `security`, that always writes to the container's stderr, one JSON object per line, at `info` whatever `LOG_LEVEL` says. Its fields and events are in [security.md §11](security.md#11-logging-and-error-handling). Select it from the rest of the output with a filter on the `"channel":"security"` key (`docker compose logs --no-log-prefix app | jq -R -c 'fromjson? | select(.channel=="security")'`), or ship the container's output to a log collector and match on `event`.

**nginx's access log masks the share token.** A share link carries its token in the path (`/s/{token}`), so the log format (`docker/nginx.conf`) writes the request line from a masked copy of the URI: `GET /s/[redacted] HTTP/1.1`. Every other path is logged as requested. nginx's error log, at `warn`, can still quote a request line when a request fails before it is processed; a reverse proxy in front keeps its own access log, which is outside this image, and records `/s/{token}` unless it is configured as this one is.

### Resource budget

| Service | Target idle |
|---|---|
| app (nginx + php-fpm) | ~150 MB |
| reverb | ~60 MB |
| queue | ~60 MB |
| scheduler | ~40 MB |
| db | ~120 MB |
| redis | ~20 MB (capped at 128 MB, never evicts: see below) |
| **Total** | **under 512 MB** |

Tune php-fpm to `pm = ondemand` with a small `max_children`; the default `dynamic` settings are sized for a busier host than this.

The `assistant` service is **not** in that budget and is not meant to be: it adds roughly 3 GB resident on its own. See §9.

### Redis memory (W109, D72)

**Redis never evicts.** It runs with `--maxmemory 128mb --maxmemory-policy noeviction`, because the one Redis holds the queue (recalculations, notifications, report and assistant jobs) and the edit locks, and an evicting policy such as `allkeys-lru` drops whichever key it picks when memory runs short: a queued job that never runs, or a lock that vanishes while its holder is still editing, with nothing logged. `noeviction` turns that silent loss into a visible failure.

**What a full Redis looks like.** Redis keeps answering reads and refuses every write with `OOM command not allowed when used memory > 'maxmemory'`, which Laravel logs to stderr (`docker compose logs`). Everything that writes to Redis fails with it: queuing a job, so an edit is saved but the request reports an error and its totals are not recomputed; taking an edit lock; rate-limited routes, sign-in included, whose counters live in the Redis cache store; sessions too, until they move to the database (D71), so a full Redis then also blocks sign-in; and filling the calculation cache, so an assessment that is not already cached fails to open. Nothing already queued is lost, but a request that saves an edit and then fails to queue its recalculation leaves the edit saved and collaborators' totals stale until the next edit. Raising the limit recovers at once, with no restart: `docker compose exec redis redis-cli config set maxmemory 256mb`. Make it permanent in the `redis` service's `command`, for example in `docker-compose.override.yml`, since `config set` lasts only until Redis restarts.

**What uses the memory, and how to size it.** The calculation cache is the part that grows with use. It holds one entry per assessment, deleted when the next one is written and expiring six hours after its last write, so (once entries written before an upgrade have expired, within six hours) it is bounded by the number of assessments opened or edited in the last six hours, times the size of an entry, which grows with the assessment's number of risk–attack pairs. Queued jobs are small and leave as the worker takes them; locks are a few bytes each and expire on their own. To size a deployment, measure it rather than estimate:

```bash
docker compose exec redis redis-cli info memory | grep -E '^(used_memory_human|used_memory_peak_human|maxmemory_human):'
docker compose exec redis redis-cli --scan --pattern '*as:calc:*:v*' | head -5    # then, for one of them:
docker compose exec redis redis-cli memory usage '<key>'
```

Keep `used_memory_peak` under half of `maxmemory`, so a backlog of queued work during a burst of edits has room. 128 MB leaves that margin for a team working on a few dozen assessments a day; raise it if the peak says otherwise.

---

## 9. The assistant (optional)

The local inference runtime for the 2.3 assistant ([`PLAN-2.3-ai.md`](../PLAN-2.3-ai.md), measurements in [`docs/spikes/2.3-inference.md`](../spikes/2.3-inference.md)). **Optional, off by default, and a fully supported absence** — most deployments will not run it, and an installation without it shows no assistant controls, no disabled buttons and no warnings.

### 9.1 Turning it on is two decisions, not one

| | Where | Who |
|---|---|---|
| Does this deployment have a runtime? | `ASSISTANT_ENABLED` in `.env` | whoever runs the stack |
| Is the feature on for this instance? | Admin → AI assistant | an admin |

Neither alone does anything. The first without the second gives an admin a toggle; the second is impossible without the first (the settings form refuses it, and every assistant route 404s). Adding the container does not hand the feature to your users — somebody decides.

### 9.2 Installing a model

The runtime serves **one GGUF, mounted from the host**. It never downloads at run time: a runtime that fetches weights on demand can fail at first use, in the field, with no network, and pinning a file makes "which model is this instance running" answerable by looking.

Since 2.6 (D52, D53) there are two presets, catalogued in `docker/assistant/models.conf`, and installing one is a single command run in the pinned runtime image itself — no manual download, no manual size check:

| Preset | Model | Download | Needs about |
|---|---|---|---|
| `small` (recommended) | Gemma 4 E4B, 4-bit, quantization-aware | 4.2 GB | 6 GiB RAM |
| `large` | Gemma 4 26B-A4B, 4-bit, quantization-aware, mixture of experts | 14.2 GB | 28 GiB RAM |

```bash
docker compose run --rm assistant-install small   # or: large
docker compose run --rm assistant-install list    # what exists, what is installed
```

`assistant-install` is its own compose profile, so `up` never starts it; it mounts the models directory read-write, which the `assistant` service's own mount deliberately is not. It:

1. checks the host has at least the preset's required memory, refusing otherwise unless run with `--force`;
2. checks free disk against the download size;
3. downloads from the publisher (Hugging Face) at a pinned repository revision, resumable, into a `.part` file;
4. verifies byte count **and** sha256 against the catalogue before the file gets its real name — size first, because it is instant and it is what catches a truncated download that exits 0 (`docs/spikes/2.3-inference.md` — a plain `curl -sL` once produced a 1.36 GB result and exit status 0 for a file that should have been complete, and the truncation surfaced only when the container started, as `tensor 'blk.14.attn_q.weight' data is not within the file bounds`);
5. records the selection, so a later `serve` with no explicit model runs what was last installed.

Re-running `install` on a preset that is already fully downloaded re-verifies it and re-selects it without downloading again. An installed file of the right length with the wrong bytes is deleted and downloaded afresh in the same run; a download that completes with the wrong hash is deleted and the command stops, installing nothing. Neither is "resumed": resuming a complete file fetches nothing and would fail the same check forever. Only a short `.part` — an interrupted download — resumes.

Files live under `ASSISTANT_MODEL_PATH` (default `./models`, gitignored, D58). On Unraid, use a pool path (`/mnt/<pool>/...`) rather than `/mnt/user/...` — the FUSE layer.

Do **not** take a model file from an Ollama volume. Ollama ships a custom variant of some models — `gemma3` among them — whose metadata upstream llama.cpp rejects outright.

**Which model actually runs** (D54), resolved by `docker/assistant/assistant.sh` at container start, first match wins:

1. `ASSISTANT_MODEL_FILE` — any GGUF in the models directory, by file name.
2. `ASSISTANT_MODEL=small|large` — a preset named explicitly.
3. `ASSISTANT_MODEL=<name>` where `<name>.gguf` is in the models directory — 2.5 used the variable as a label for the file.
4. The preset `install` most recently selected.
5. 2.5’s default file, `gemma-3-4b-it-Q4_K_M.gguf`, if it is there.

2.5 named its model three ways — `ASSISTANT_MODEL_FILE`, `ASSISTANT_MODEL` as a label for the file, or nothing at all, relying on the compose default `gemma-3-4b-it-Q4_K_M.gguf` — and all three are honoured, so an upgrade keeps running exactly the model it ran before; nothing switches silently.

The runtime refuses to start on a preset whose file is missing or the wrong size (checked by size only at start — hashing a 14 GB file on every restart would cost minutes, and `install` already hashed it), and the refusal names the install command that fixes it.

To move a 2.5 deployment from `ASSISTANT_MODEL_FILE` onto a preset: remove `ASSISTANT_MODEL_FILE` and `ASSISTANT_MODEL` from `.env`, run the install command for the preset you want, and restart the assistant (§9.3). The old `gemma-3-4b-it` file can then be deleted from the models directory.

### 9.3 Starting it

```bash
docker compose --profile assistant up -d
```

To pick up a different installed model on an already-running deployment — for example, after installing the other preset — restart the service rather than bringing it up again:

```bash
docker compose --profile assistant restart assistant
```

The profile is why an ordinary `docker compose up -d` does not start it. Nothing is published to the host, for the same reason Reverb is not (§2.1): an inference endpoint with no authentication is not something to expose.

Its health check probes llama.cpp's own `/health`, which returns 503 while the model is loading and 200 once it is ready, so `service_healthy` genuinely means ready. `start_period` is 180s because reading several gigabytes from disk is not fast.

The llama.cpp image is pinned by tag **and digest** (`ghcr.io/ggml-org/llama.cpp:server-b11176@sha256:6257697a…`, D56) — the same reason a model file is pinned: `:server` moves, and "which build is this instance running" should be answerable by looking. Since W101 the pin is the `FROM` line of `docker/assistant/Dockerfile`, which adds the launcher and the catalogue and nothing else (§2.4, D63); a local edit to either file takes effect with `--build`.

### 9.4 Resources, and what "slow" means here

| | |
|---|---|
| Resident | `small`: ~3 GiB (plus up to 2 GiB for the bounded host-RAM prompt cache, `--cache-ram`, D56). `large`: ~24 GiB, plus up to 4 GiB of prompt cache — budget 28 |
| Threads | `ASSISTANT_THREADS`, default **4** since 2.6 (was 8) — the container's CPU quota is set to the same number, and a host with fewer CPUs than this must lower it or the container will not start |

**Set `ASSISTANT_THREADS` to the number of cores you are willing to give it; the container's quota follows it automatically.** Setting `-t` higher than the cores the container may actually use makes it slower, not faster, and an unconstrained runtime will contend with the database it shares a host with. The table below, measured on 4, 6 and 8 cores of a 2018 developer workstation (an i9-9900K) in [`spikes/2.3-threads.md`](../spikes/2.3-threads.md), is still valid as a shape — past four to six cores generation barely improves — even though the 2.6 numbers below it were measured on newer hardware at 4 cores only:

| | 4 cores | 6 cores | 8 cores |
|---|---|---|---|
| Suggestions (job B) | 46s | 36s | 29s |
| Prompt processing | 36 tok/s | 49 tok/s | 59 tok/s |
| Generation | 8.2 tok/s | 8.7 tok/s | 9.4 tok/s |

**Past about six cores there is very little to gain.** Generation is memory-bandwidth bound. Prompt processing scales more usefully, so a long fixed prompt — the help corpus, a whole assessment — is what more cores actually help with. Those cores are generally worth more to MariaDB.

**CPU inference is slow in a way that changes designs, not just timings.** Measured on the current deployment host (Intel i9-14900K, 4 cores given to the container, [`spikes/2.6-models.md`](../spikes/2.6-models.md)) — the help corpus has grown to ~6,500 tokens since 2.3's figures were taken, so these are not directly comparable to the old ones:

| Job | `small` | `large` | 2.5's default, for comparison |
|---|---|---|---|
| Help answer, first after a restart (no warm-up) | 98s | 116s | 93s |
| Help answer, after | 9s | 10.5s | 9s |
| Suggestions (five) | 20s | 21–23s | 18s |
| Executive summary, 73-node assessment | 118s | 142s | 122s |

Generation runs at roughly 13 tok/s (`small`) and 11 tok/s (`large`); prompt processing at roughly 71 and 60 tok/s. Every timeout in `config/assistant.php` is set explicitly for this reason — HTTP defaults are wrong here by one to two orders of magnitude, and because generations stream, the timeout that protects anything is the gap between tokens, not the length of the answer.

Since 2.6 (D57), the scheduler warms the help corpus into the runtime's cache once per container start — only when the help feature is available — so the first real question after a restart gets the "after" row above rather than the cold one; nothing to configure, it runs on its own.

Re-measure on your own host before promising anyone a response time: `tools/spike/assistant/candidates.sh`, which needs only bash, curl and Docker (see `tools/spike/assistant/README.md`).

---

## 10. W3 acceptance criteria

1. From a **clean clone** with only `cp .env.example .env` and no other edits, `docker compose up --build -d` reaches a working login screen. No manual key generation, no manual migration, no manual permission fix.
2. `docker compose down && docker compose up -d` preserves all data.
3. Deleting `${DATA_PATH}` and starting again produces a clean install with the first-run setup screen.
4. No container publishes a port other than `app`.
5. `docker compose config` contains no secret, no absolute host path and no hostname belonging to the owner.
6. Behind a TLS-terminating proxy, every generated URL is `https://`, and the websocket connects over `wss://` on the same origin.
7. Total idle memory across the stack is under 512 MB, measured with `docker stats`.
8. The `docker` CI job passes.
