# Deployment Specification

> Related: [security.md](security.md) for TLS, cookies, CSP and the websocket exposure; [ASSISTANT.md](../ASSISTANT.md) for the optional AI assistant.

---

## 1. Target environments

**Primary:** any Docker host. `docker compose up -d` with only a `.env` filled in works unchanged; nothing hardcodes a host path or a domain. The stack is designed to sit behind a reverse proxy that terminates TLS (§4), for example nginx, Caddy, Traefik or SWAG.

**Constraints the design meets:**

- Easy to deploy via Docker Compose.
- Resource efficient: the target is **under 512 MB total across the always-on containers at idle** (§8).
- Free and open-source components only.

---

## 2. Services

Six containers, four of which run the same image with a different command. A seventh, the assistant runtime, exists behind a profile (§9).

| Service | Image | Command | Ports | Purpose |
|---|---|---|---|---|
| `app` | `ARGUESECURE_IMAGE:ARGUESECURE_VERSION` | nginx + php-fpm via supervisord | `${APP_PORT:-8000}:80` | HTTP |
| `reverb` | same image | `php artisan reverb:start --host=0.0.0.0 --port=8080` | internal only | Websockets |
| `queue` | same image | `php artisan queue:work --tries=3 --max-time=3600` | none | Recalculation, notification, report and assistant jobs |
| `scheduler` | same image | `php artisan schedule:work` | none | Scheduled commands (§8) |
| `db` | `mariadb:11` | n/a | internal only | Data |
| `redis` | `redis:7-alpine` | `redis-server --appendonly yes --maxmemory 128mb --maxmemory-policy noeviction` | internal only | Cache, queue, locks, broadcast |

Redis serves the cache, the queue, the edit locks and broadcast authorization. Sessions are the exception: they are stored in MariaDB's `sessions` table, fixed in `config/session.php` rather than read from `SESSION_DRIVER`, because signing other devices out deletes rows there ([security.md §5](security.md#5-sessions-and-cookies)). A `SESSION_DRIVER` value in `.env` is ignored.

Only `app` runs migrations (§6). `app`, `reverb`, `queue` and `scheduler` mount the same `${DATA_PATH:-./data}/storage` volume, and all four `depends_on` `db` and `redis` being healthy.

Health checks: `app` inherits the image's `HEALTHCHECK` (`wget` against `/up` every 30 s). `reverb` overrides it with a TCP probe of `127.0.0.1:8080`. `queue` and `scheduler` serve no port, so their health check is disabled; if the process exits the container exits and `restart: unless-stopped` restarts it. `db` uses `healthcheck.sh --connect --innodb_initialized`; `redis` uses `redis-cli ping`.

### 2.1 Reverb is not published to the host

Reverb stays on the internal compose network and nginx proxies `/app` to it, so websockets inherit the app's TLS, hostname and origin and no second proxy entry is needed. `db` and `redis` are likewise unpublished by `docker-compose.yml`; only `app` publishes a port. (`docker-compose.override.yml`, which Compose loads automatically on top of the base file, is a development convenience that additionally publishes MariaDB and Redis on `127.0.0.1` only; production runs pass `-f docker-compose.yml` explicitly or omit that file.)

**The proxy target is the `reverb` service, not `127.0.0.1`.** The `app` container runs php-fpm and nginx and nothing else; Reverb is its own container, addressed by service name like `db` and `redis`. nginx reaches it through Docker's embedded resolver (`resolver 127.0.0.11`) and a variable upstream (`set $reverb_upstream "reverb:8080"`) rather than a literal one, because a literal upstream name is resolved once at startup and nginx refuses to start when it does not resolve, as happens on any boot where `reverb` comes up after `app`.

**The browser's app key comes from the server, not from the bundle.** It is shared onto every Inertia page by `HandleInertiaRequests` and read by `useRealtime.ts`. Vite substitutes `import.meta.env.VITE_*` when the image is built, but the Reverb credentials are generated when the container first starts (§6), so a build-time key would always be empty. The app key is a public identifier that appears in the WebSocket URL anyway; the secret is never shared.

Two sets of variables therefore mean different things and must not be conflated:

| | Who reads it | Correct value under compose |
|---|---|---|
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | the **app**, posting broadcasts to Reverb | `reverb` / `8080` / `http`, the internal service |
| `REVERB_CLIENT_HOST` / `_PORT` / `_SCHEME` | the **browser**, dialling the websocket | unset, meaning "this page's own origin", which lets one built image work on any hostname, port and scheme |

Set the `REVERB_CLIENT_*` trio only for a proxy-less local run (`php artisan serve` alongside `php artisan reverb:start`), where there is no nginx to proxy `/app`.

`REVERB_MAX_REQUEST_SIZE` and `REVERB_APP_MAX_MESSAGE_SIZE` (both default 1,000,000 bytes in `config/reverb.php`) bound a broadcast. A totals broadcast costs roughly 1 KB per node, so the default covers around a thousand nodes. Both must be raised together, because one governs the publish Reverb accepts over HTTP and the other the per-app message.

### 2.2 Compose skeleton

`docker-compose.yml` is the source of truth; the shape of the always-on services is:

```yaml
x-arguesecure-image: &arguesecure-image ${ARGUESECURE_IMAGE:-ghcr.io/danionita/arguesecure}:${ARGUESECURE_VERSION:-latest}

services:
  app:
    image: *arguesecure-image
    build: { context: ., dockerfile: Dockerfile }
    restart: unless-stopped
    ports: ["${APP_PORT:-8000}:80"]
    env_file: .env
    environment: { RUN_MIGRATIONS: "true" }
    volumes:
      - ${DATA_PATH:-./data}/storage:/var/www/html/storage
    depends_on:
      db:    { condition: service_healthy }
      redis: { condition: service_healthy }
    networks: [arguesecure]

  # reverb, queue, scheduler: same image, different command, no ports

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

The `db` and `redis` images are referenced by tag (`mariadb:11`, `redis:7-alpine`), not by digest.

Each PHP service also lists an optional build secret, `composer_auth` (from the `COMPOSER_AUTH` environment variable). When present at build time, Composer uses it to authenticate its downloads, which avoids anonymous rate limits on shared build addresses. It is a build secret, not a build argument, so it does not enter the image history. An unset `COMPOSER_AUTH` is not an error: the build runs anonymously.

### 2.3 The `DATA_PATH` variable

Host-side data lives under `${DATA_PATH:-./data}` (`storage/`, `mysql/`, `redis/`), documented in `.env.example`:

```
# Elsewhere: leave unset to use ./data
DATA_PATH=
```

`.env` is not bind-mounted into any container; services read it through `env_file:` only. Nothing in the application writes to `.env`.

### 2.4 Published images

Every release is published to GitHub's container registry by `.github/workflows/image.yml`, so a deployment is `docker-compose.yml` and a `.env`: no checkout, no build, no Node or Composer traffic on the host.

| Image | Built from | Used by |
|---|---|---|
| `ghcr.io/danionita/arguesecure` | `Dockerfile` | `app`, `reverb`, `queue`, `scheduler` |
| `ghcr.io/danionita/arguesecure-assistant` | `docker/assistant/Dockerfile`: the digest-pinned llama.cpp image plus `assistant.sh` and `models.conf` | `assistant`, `assistant-install` |

| Git ref | Tags published |
|---|---|
| `v2.7.0`, the newest release overall | `2.7.0`, `2.7`, `latest` |
| `v2.7.1`, the newest in its minor line but not overall | `2.7.1`, `2.7` |
| `v2.6.3`, not the newest in its minor line | `2.6.3` only |
| `v2.7.0-rc1` (any other `v*` tag) | `2.7.0-rc1` only |
| a manual run of the workflow | `sha-<commit>`, `edge` |

`ARGUESECURE_VERSION` in `.env` chooses the tag (default `latest`); `ARGUESECURE_IMAGE` overrides the repository, for a fork. Both published images always carry the same tag, so a pulled app and assistant come from the same commit and read the same `models.conf`. A source build keeps that true only if it rebuilds the assistant too (§8). Only `linux/amd64` is published.

The sidebar shows the running version. It is the release tag, passed by `image.yml` as the build argument `ARGUESECURE_BUILD_VERSION`, recorded by the `Dockerfile` as an environment variable and read by `config/arguesecure.php` (`version`). It is not `ARGUESECURE_VERSION`, which is only the tag a deployment pins. A build with no argument, such as a source checkout's `docker compose build`, reads the repository's `VERSION` file (the last release), and shows `dev` only if that file is missing. The "Release notes" link goes to `docs/RELEASE-x.y.z.md` at tag `vx.y.z` in the public repository; other values link to the releases list. Every release therefore ships its notes file.

Each service keeps `build:` beside `image:`. Compose pulls an image it does not have and builds only when the pull fails; `--build` always builds. A source checkout works the same way as an image-only deployment, and its local build carries the published name until the next `docker compose pull` replaces it. Because every compose project on a host builds to that one name, a second project on the same host (a throwaway deployability check, or the end-to-end stack, which `docker-compose.e2e.yml` gives its own `:e2e` tag) must set `ARGUESECURE_VERSION` to a name of its own; otherwise its build retags the image the first stack runs, and that stack's next `up` recreates itself from it and migrates its database with it.

Before anything is pushed, `image.yml` boots the new images from a directory holding nothing but `docker-compose.yml` and a `.env`, with `--pull never`, and requires `/up` and the setup screen to answer and `assistant-install list` to read the catalogue from inside the assistant image. `tests/Unit/Deployment/ComposeImageDeployTest.php` keeps the compose file from drifting: every buildable service names an image, and no service bind-mounts a path from the checkout.

The packages are public, so pulling needs no login. The workflow's Publish step runs only for `github.repository == 'danionita/arguesecure-onlinev2'` and never for pull requests; elsewhere it builds and boots the images and pushes nothing. The `Release` workflow (`release.yml`, manual dispatch on `main`) checks that `VERSION`, `docs/RELEASE-<VERSION>.md` and an untagged `v<VERSION>` agree, creates the tag and GitHub release from the notes file, and dispatches `image.yml` on the new tag. The same workflow has two maintenance modes, one per run: repointing an existing tag at another commit on `main`, and rewriting an existing release's notes from `docs/RELEASE-<version>.md`.

---

## 3. Dockerfile

Multi-stage, `php:8.3-fpm-alpine` as the runtime base.

**Stage 1, assets.** `node:22-alpine`; copy `package.json` and `package-lock.json`; `npm ci`; copy `resources/`, `vite.config.ts`, `tsconfig.json` and `public/`; `npm run build`.

**Stage 2, vendor.** `composer:2`; copy `composer.json` and `composer.lock`; `composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-req=ext-gd`; then copy the application and run `composer install --no-dev --no-scripts --optimize-autoloader --prefer-dist --ignore-platform-req=ext-gd`.

The `--ignore-platform-req` is scoped to **one extension by name**, never blanket, so a genuinely missing requirement in a future dependency still stops the build. It is needed because `composer:2` has no `gd` while `phpoffice/phpspreadsheet` and `phpoffice/phpword` require it, and this stage never runs the application: with `--no-scripts` it fetches dists and writes an autoloader. The same premise underlies `composer.json`'s `config.platform.php`: describe the target, not the builder. Stage 3 asserts the extension is loaded, which is what makes the flag safe.

**Stage 3, runtime.** `php:8.3-fpm-alpine` with:

- Extensions: `pdo_mysql`, `redis` (pecl), `opcache`, `intl`, `bcmath`, `zip`, `pcntl` (Reverb and the queue worker need it for signal handling), `mbstring`, `gd` (configured `--with-freetype --with-jpeg`; `libpng`, `libjpeg-turbo` and `freetype` ship in the runtime layer and their `-dev` halves are removed with the build dependencies).
- A build step that asserts `gd` actually loaded, kept as its own `RUN` so a future change dropping it fails at a line whose name says what went wrong, instead of as a fatal in a queue worker on a running host.
- `nginx` with `nginx-mod-http-brotli`, and `supervisor`. A missing or ABI-drifted brotli package fails the image build rather than nginx at boot.
- Vendor from stage 2, built assets from stage 1.
- `storage/` and `bootstrap/cache/` owned by `www-data`. supervisord (nginx and php-fpm masters) starts as root; php-fpm workers run as `www-data`.
- `ENV LOG_CHANNEL=stderr LOG_LEVEL=info` (§8) and `ARG`/`ENV ARGUESECURE_BUILD_VERSION`.
- `EXPOSE 80` and a `HEALTHCHECK` (30 s interval, 5 s timeout, 30 s start period, 3 retries) running `wget -qO- http://127.0.0.1/up`, Laravel's built-in health route.
- `ENTRYPOINT entrypoint.sh` (§6), `CMD supervisord -c /etc/supervisord.conf`.

**opcache** (`docker/opcache.ini`): `opcache.enable=1`, `enable_cli=0`, `validate_timestamps=0` (the image is immutable), `memory_consumption=128`, `interned_strings_buffer=16`, `max_accelerated_files=20000`, `jit=tracing`, `jit_buffer_size=64M`.

**php-fpm** (`docker/php-fpm.conf`): listens on the unix socket `/run/php-fpm.sock`; `pm = ondemand`, `pm.max_children = 8`, `pm.process_idle_timeout = 10s`, `pm.max_requests = 500`; `catch_workers_output = yes` with `decorate_workers_output = no` (so application stderr reaches the container's); `clear_env = no`.

**nginx** (`docker/nginx.conf`): serves `public/` directly with `try_files` falling back to `index.php`; passes `.php` to php-fpm on the unix socket (32k FastCGI buffers); denies dotfiles other than `.well-known`; `client_max_body_size 20m`; gzip and brotli for text, JSON, JavaScript, SVG and woff2 above 512 bytes; proxies `/app` to `reverb:8080` with the websocket upgrade headers (`proxy_read_timeout 60s`); access log to stdout and error log (`warn`) to stderr.

**Base images.** The runtime and build stages use floating tags (`php:8.3-fpm-alpine`, `node:22-alpine`, `composer:2`) and are refreshed by rebuilding. The assistant runtime's llama.cpp base is pinned by tag and digest (§9.3).

---

## 4. Reverse proxy

The stack expects a reverse proxy in front that terminates TLS and forwards plain HTTP. Without explicit proxy trust, Laravel generates `http://` URLs and the browser blocks them as mixed content.

Laravel 12 configures this through the `TrustProxies` middleware in `bootstrap/app.php`:

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

Three properties are deliberate:

1. **Only private ranges are trusted**, never `*`. Trusting every proxy lets any client spoof `X-Forwarded-*`. A proxy on a public address is not trusted.
2. **`X-Forwarded-Host` is excluded** from the trusted header set, to prevent Host-header injection (poisoned absolute URLs). Scheme, client IP and port forwarding are honoured.
3. **php-fpm sees the proxy's address, not the client's.** The image's nginx uses `real_ip` (`set_real_ip_from` the same three private ranges, `real_ip_header X-Forwarded-For`, `real_ip_recursive on`) to log the visitor's address, which rewrites `$remote_addr`. `docker/nginx.conf` therefore passes `$realip_remote_addr` as FastCGI `REMOTE_ADDR`, so Laravel's trusted-proxy check sees the proxy that actually connected. Passing the visitor's address instead would make a visitor on a public IP look like an untrusted proxy, `X-Forwarded-Proto` would be ignored and asset URLs would come out `http://`. `tests/Unit/Deployment/NginxProxyTrustTest.php` guards this.

Set `APP_URL` to the real public HTTPS URL. Queued jobs and the Reverb client configuration use it, since they have no incoming request to infer from.

**Proxy configuration.** One proxy entry is enough. Forward `/` to the `app` container's port 80 (`APP_PORT` on the host) and pass the usual `X-Forwarded-For` and `X-Forwarded-Proto` headers. The websocket path `/app` is proxied inside the app container's nginx to Reverb, so the outer proxy only needs to pass upgrade requests through on the same server block:

```nginx
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection "upgrade";
```

---

## 5. Environment variables

`.env.example` documents every variable with no real values, grouped and commented.

| Variable | Notes |
|---|---|
| `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL` | `APP_DEBUG=false` in every deployed environment |
| `APP_KEY` | **empty in the example.** Generated on first run (§6) |
| `ARGUESECURE_VERSION`, `ARGUESECURE_IMAGE` | image tag (default `latest`) and repository (§2.4) |
| `DATA_PATH`, `APP_PORT` | host-side placement (defaults `./data` and `8000`) |
| `DB_*`, `DB_ROOT_PASSWORD` | `DB_HOST=db`; the passwords are left empty in the example and must be set |
| `REDIS_HOST=redis`, `REDIS_PASSWORD` | |
| `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `BROADCAST_CONNECTION=reverb` | |
| `SESSION_SECURE_COOKIE=true`, `SESSION_LIFETIME=480` | `SESSION_DRIVER` is not read: sessions are always in the database |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | generated on first run alongside `APP_KEY`; do not set them in `.env` |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | where the **app** posts broadcasts to: `reverb` / `8080` / `http` (§2.1) |
| `REVERB_CLIENT_HOST`, `REVERB_CLIENT_PORT`, `REVERB_CLIENT_SCHEME` | where the **browser** dials. Unset means "this page's own origin", which is correct behind the nginx proxy. Set only for a proxy-less local run |
| `REVERB_MAX_REQUEST_SIZE`, `REVERB_APP_MAX_MESSAGE_SIZE` | optional broadcast size limits, bytes, default 1,000,000 each; set together |
| `LOG_CHANNEL`, `LOG_LEVEL` | optional; the image defaults them to `stderr` and `info` (§8) |
| `ARGUESECURE_DEFAULT_CURRENCY`, `ARGUESECURE_AMORTIZATION_YEARS` | product defaults |
| `TRASH_RETENTION_DAYS` | default 30, at least 1; days a deleted assessment stays in the trash |
| `SECURITY_LOG_RETENTION_DAYS` | default 90, at least 1; days the admin Security log keeps stored events. Stderr is not affected |
| `OIDC_*` | optional single sign-on (§5.1) |
| `ASSISTANT_ENABLED` | **default `false`, and the common case.** When false the assistant routes are never registered, so the feature answers 404 rather than 403, and everything else in `config/assistant.php` is inert |
| `ASSISTANT_MODEL_PATH`, `ASSISTANT_MODEL_FILE`, `ASSISTANT_MODEL`, `ASSISTANT_THREADS` | where the mounted GGUF is, which file, which preset or label, and how many cores the runtime may take (§9) |
| `ASSISTANT_BASE_URL`, `ASSISTANT_CONNECT_TIMEOUT`, `ASSISTANT_STREAM_IDLE_TIMEOUT`, `ASSISTANT_TOTAL_TIMEOUT`, `ASSISTANT_HEALTH_TTL`, `ASSISTANT_MAX_TOKENS_HELP`, `ASSISTANT_MAX_TOKENS_SUGGESTIONS`, `ASSISTANT_MAX_TOKENS_SUMMARY`, `ASSISTANT_CONTEXT_TOKENS` | all optional with working defaults. Read `config/assistant.php` before changing a timeout: they are deliberately large because CPU inference runs for minutes |

The assistant variables are the one group where setting them is not enough to switch anything on. Three separate switches gate it: this environment flag, the profile-gated container, and an admin toggle that does not render until the first two hold. [`docs/ASSISTANT.md`](../ASSISTANT.md) describes them and the admin settings page points at it; §9 is the operational half.

There is no SMTP dependency: accounts are admin-created and passwords are reset in the admin UI, so no `MAIL_*` variables are used.

Application code never calls `env()` outside a config file, because it returns `null` once `config:cache` has run (the entrypoint runs it, §6). Everything is read through `config()`.

### 5.1 Single sign-on (optional)

Enter the provider on **Admin → Authentication**, or set `OIDC_ISSUER`, `OIDC_CLIENT_ID` and `OIDC_CLIENT_SECRET` (see `.env.example`; a value set there wins over one stored on the tab, field by field, so a bad stored value is fixed by editing `.env`), and register `<APP_URL>/auth/oidc/callback` as the redirect URI at the provider (Microsoft Entra ID, Google Workspace, Okta, Keycloak, Authentik, Authelia, or any OpenID Connect provider with discovery). The app container must reach the provider over https; the provider needs no inbound access to it. `OIDC_ALLOWED_DOMAINS` lets a first sign-in create a non-admin account for those email domains (empty creates none); the provider must send `email_verified`, or set `OIDC_TRUST_EMAIL=true` for a provider you control that does not. `OIDC_BUTTON_LABEL` sets the login link's text ("Sign in with <label>"). Leave `OIDC_ISSUER` empty for no SSO: the login page shows no link and the two `/auth/oidc` routes answer 404.

Only Keycloak has been tested against this build; every other provider is expected to work because they speak standard OpenID Connect, but has not been verified.

**Microsoft Entra ID (written from Microsoft's documentation, not verified):** register a web application, add the redirect URI above, create a client secret, and use `https://login.microsoftonline.com/<tenant-id>/v2.0` as the issuer. Entra's ID tokens carry the `email` claim only if it is added as an optional claim, and do not carry `email_verified`: for a single-tenant app whose directory you control, set `OIDC_TRUST_EMAIL=true`; do not do so for a multi-tenant app.

Keep an administrator able to sign in with a password: it is the way in if the provider is down.

---

## 6. First run and the entrypoint

`docker/entrypoint.sh` runs in every PHP container before its command (`supervisord`, `reverb:start`, `queue:work`, `schedule:work`):

1. Recreate the `storage/` skeleton (`storage/app/private`, `storage/app/public`, `storage/framework/{cache/data,sessions,views}`, `storage/logs`, `bootstrap/cache`). The compose bind mount hides what the image put in `storage/`, and on a first boot the host directory is empty.
2. Wait for `db` and `redis` to accept TCP connections (30 tries, 2 s apart, then exit 1). The compose health checks make this mostly redundant, but it also covers containers started on their own.
3. **If `storage/app/config/app.key` does not exist, generate it.** It holds the application key (`artisan key:generate --show`) and a random `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET`. The four containers share the file through the storage volume and can start concurrently, so each writes to a unique `mktemp` file and swaps it into place with an atomic `mv -n`; one writer wins and the others read the finished file. The log line names the file and says that losing it invalidates every encrypted cookie and session.
4. Export `APP_KEY` and the three `REVERB_*` values from that file, after checking that each is non-empty and a single line. A malformed file stops the container with a message; deleting the file and restarting the stack regenerates it, at the cost of existing sessions and encrypted cookies. Because the file is the source, `APP_KEY` and `REVERB_APP_*` in `.env` are not used by the containers; leave them empty.
5. **Only if `RUN_MIGRATIONS=true`** (set on `app` only, so four containers do not race): `php artisan migrate --force`, `php artisan db:seed --class='Database\Seeders\SystemScaleSeeder' --force` (the built-in system scales, idempotent), `config:cache`, `route:cache`, `view:cache`, `event:cache`, and `storage:link`.
6. `chown -R www-data:www-data storage bootstrap/cache`, then `exec` the container's command.

Back up `storage/app/config/app.key` with the database. The `storage` volume also holds the Laravel framework cache directories; nothing else needs preserving there.

**No user is ever seeded.** The app detects an empty `users` table and serves the first-run setup screen ([security.md §4](security.md#4-authentication)). There is no default `db:seed`: `DatabaseSeeder` creates nothing.

**Reference content arrives with the first administrator.** `SetupController` calls `SampleContentSeeder::seedReferenceContent()` the moment the first administrator is created, so a fresh deployment reaches the dashboard with the bundled templates (every `kind: assessment` fixture in `database/seeders/templates/`) and the catalogue entries (every `kind: catalog` fixture). Both halves are idempotent and no credential is created anywhere. A failure there is logged and does not block the account. An instance whose fixtures have moved on since its first-run setup can run `php artisan db:seed --class='Database\Seeders\SampleContentSeeder' --force`, which needs no administrator and creates no users.

---

## 7. CI

GitHub Actions in the public repository runs on every push and pull request (`.github/workflows/ci.yml`). Every job is guarded by `github.repository == 'danionita/arguesecure-onlinev2'`, so a fork or mirror skips them; the same checks run locally through `tools/dev/checks/checks.sh`. Actions are pinned to commit SHAs.

| Job | Steps |
|---|---|
| `php` | PHP 8.3, `composer install`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test` (Pest) against MariaDB and Redis service containers |
| `js` | Node 22, `npm ci`, `npm run lint`, `npm run type-check` (`vue-tsc --noEmit`), `npm run test:unit` (Vitest). Needs nothing from `php` |
| `docker` | Needs `php` and `js`. `docker compose build` (retried with back-off), `docker compose up -d`, poll `/up` until healthy, assert `/login` redirects (302) and `/setup` answers 200 on a fresh instance, assert an application log line reaches the container's stderr at `info` (and a `debug` line does not) and that no `storage/logs/laravel.log` is written, `docker compose down -v` |
| `e2e` | Needs `php`, `js` and `docker`. Playwright (Chromium) against the composed stack with `docker-compose.e2e.yml` on port 8100; uploads the report on failure |
| `security` | `composer audit --locked`, `tools/dev/audit-gate.mjs` over `npm audit` of the whole lockfile (every npm package is a devDependency, so `--omit=dev` would always report 0), and a gitleaks secret scan |

`image.yml` additionally builds, boots and (on tags and manual runs) publishes the images (§2.4); on pull requests that touch the Dockerfile, `docker/`, the compose file or `.env.example` it builds and boots without pushing. The `docker` job is the one that catches deployability regressions, which are silent and expensive to find late.

---

## 8. Operations

### Scheduled work

The `scheduler` container runs `schedule:work`, which executes (`routes/console.php`):

| Command | Frequency | Purpose |
|---|---|---|
| `arguesecure:save-versions` | every 5 minutes | save an automatic version of every assessment whose unsaved changes are due one |
| `arguesecure:purge-deleted` | daily | erase assessments deleted longer ago than `TRASH_RETENTION_DAYS` |
| `arguesecure:purge-security-events` | daily | remove stored security events older than `SECURITY_LOG_RETENTION_DAYS` |
| `assistant:warm` | every minute, only when the assistant is configured | read the help corpus into a freshly started runtime, once per runtime start (§9.4) |

### Backups

- Take a database dump with `mariadb-dump` against the `db` container (the README gives the command). The dump is the backup.
- Back up `${DATA_PATH}/storage/app/config/app.key` with it (§6).
- An assessment's History page protects against mistakes inside the application, not against losing the server. A single assessment can also be exported as JSON from its Export menu, which doubles as a portability format between instances.
- There is no scheduled export of all assessments.

### Upgrades

`docker compose pull && docker compose up -d` from the published image (§2.4), after changing `ARGUESECURE_VERSION` if it pins one. `git pull && docker compose build && docker compose up -d` from a source checkout, with `--profile assistant` on both commands where the assistant runs, because `build` skips profile-gated services and the assistant's launcher and catalogue live in its image rather than a mount. Migrations run automatically from the `app` entrypoint. Take a database dump before any upgrade.

### Logs

All containers log to stdout/stderr for `docker compose logs`. Nothing is written to log files inside the image or on the data volume. The image sets `LOG_CHANNEL=stderr` and `LOG_LEVEL=info`: an artisan service (queue, scheduler, Reverb) writes to its container's stderr directly, and a web request's lines reach the app container's through php-fpm's `catch_workers_output`. A value in `.env` overrides either; `.env.example` lists both, commented out. Use `LOG_LEVEL=debug` while diagnosing and `warning` for a quieter log; the change takes effect when `docker compose up -d` recreates the containers, which is when Compose re-reads `.env`.

**The security event log** is a separate channel, `security`, that always writes to the container's stderr, one JSON object per line, at `info` whatever `LOG_LEVEL` says; events are also stored for `SECURITY_LOG_RETENTION_DAYS` days and listed on Admin → Security log. Its fields and events are in [security.md §11](security.md#11-logging-and-error-handling). Select it from the rest of the output with a filter on the `"channel":"security"` key (`docker compose logs --no-log-prefix app | jq -R -c 'fromjson? | select(.channel=="security")'`), or ship the container's output to a log collector and match on `event`.

**nginx's access log masks the share token.** A share link carries its token in the path (`/s/{token}`), so the log format (`docker/nginx.conf`) writes the request line from a masked copy of the URI: `GET /s/[redacted] HTTP/1.1`. Every other path is logged as requested. nginx's error log, at `warn`, can still quote a request line when a request fails before it is processed. A reverse proxy in front keeps its own access log, which is outside this image, and records `/s/{token}` unless it is configured to mask it.

### Resource budget

Approximate idle targets for the always-on services:

| Service | Target idle |
|---|---|
| app (nginx + php-fpm) | ~150 MB |
| reverb | ~60 MB |
| queue | ~60 MB |
| scheduler | ~40 MB |
| db | ~120 MB |
| redis | ~20 MB (capped at 128 MB, never evicts: see below) |
| **Total** | **under 512 MB** |

php-fpm runs with `pm = ondemand` and at most 8 children (§3). The `assistant` service is **not** in that budget: it adds roughly 3 GiB resident on its own (§9).

### Redis memory

**Redis never evicts.** It runs with `--maxmemory 128mb --maxmemory-policy noeviction`, because the one Redis holds the queue (recalculations, notifications, report and assistant jobs) and the edit locks, and an evicting policy such as `allkeys-lru` drops whichever key it picks when memory runs short: a queued job that never runs, or a lock that vanishes while its holder is still editing, with nothing logged. `noeviction` turns that silent loss into a visible failure. `tests/Unit/Deployment/RedisMemoryPolicyTest.php` guards the flags.

**What a full Redis looks like.** Redis keeps answering reads and refuses every write with `OOM command not allowed when used memory > 'maxmemory'`, which Laravel logs to stderr. Everything that writes to Redis fails with it: queuing a job (an edit is saved but the request reports an error and its totals are not recomputed), taking an edit lock, rate-limited routes (sign-in included, whose counters live in the Redis cache store), and filling the calculation cache (an assessment that is not already cached fails to open). Sessions are in the database and are unaffected. Nothing already queued is lost, but a request that saves an edit and then fails to queue its recalculation leaves collaborators' totals stale until the next edit. Raising the limit recovers at once, with no restart: `docker compose exec redis redis-cli config set maxmemory 256mb`. Make it permanent in the `redis` service's `command`, for example in `docker-compose.override.yml`, since `config set` lasts only until Redis restarts.

**What uses the memory, and how to size it.** The calculation cache is the part that grows with use. It holds one entry per assessment, replaced when the next one is written and expiring six hours after its last write, so it is bounded by the number of assessments opened or edited in the last six hours, times the size of an entry, which grows with the assessment's number of risk–attack pairs. Queued jobs are small and leave as the worker takes them; locks are a few bytes each and expire on their own. To size a deployment, measure it:

```bash
docker compose exec redis redis-cli info memory | grep -E '^(used_memory_human|used_memory_peak_human|maxmemory_human):'
docker compose exec redis redis-cli --scan --pattern '*as:calc:*:v*' | head -5    # then, for one of them:
docker compose exec redis redis-cli memory usage '<key>'
```

Keep `used_memory_peak` under half of `maxmemory`, so a backlog of queued work during a burst of edits has room. 128 MB leaves that margin for a team working on a few dozen assessments a day; raise it if the peak says otherwise.

---

## 9. The assistant (optional)

The local inference runtime for the AI assistant. **Optional, off by default, and a fully supported absence**: an installation without it shows no assistant controls, no disabled buttons and no warnings.

### 9.1 Turning it on is two decisions

| | Where | Who |
|---|---|---|
| Does this deployment have a runtime? | `ASSISTANT_ENABLED` in `.env` | whoever runs the stack |
| Is the feature on for this instance? | Admin → AI assistant | an admin |

Neither alone does anything. The first without the second gives an admin a toggle; the second is impossible without the first (the settings form refuses it, and every assistant route answers 404). Adding the container does not hand the feature to users: an admin decides.

### 9.2 Installing a model

The runtime serves **one GGUF, mounted from the host**. It never downloads at run time: a runtime that fetches weights on demand can fail at first use with no network, and pinning a file makes "which model is this instance running" answerable by looking.

Two presets are catalogued in `docker/assistant/models.conf`, and installing one is a single command run in the pinned runtime image itself:

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
4. verifies byte count **and** sha256 against the catalogue before the file gets its real name. Size is checked first because it is instant and catches a truncated download that exits 0;
5. records the selection, so a later `serve` with no explicit model runs what was last installed.

Re-running `install` on a preset that is already fully downloaded re-verifies it and re-selects it without downloading again. An installed file of the right length with the wrong bytes is deleted and downloaded afresh in the same run; a download that completes with the wrong hash is deleted and the command stops, installing nothing. Only a short `.part` (an interrupted download) resumes.

Files live under `ASSISTANT_MODEL_PATH` (default `./models`, gitignored). On hosts with a FUSE-based union filesystem, use a path on the underlying pool rather than the union mount.

Do not take a model file from an Ollama volume. Ollama ships a custom variant of some models whose metadata upstream llama.cpp rejects.

**Which model actually runs** is resolved by `docker/assistant/assistant.sh` at container start, first match wins:

1. `ASSISTANT_MODEL_FILE`: any GGUF in the models directory, by file name.
2. `ASSISTANT_MODEL=small|large`: a preset named explicitly.
3. `ASSISTANT_MODEL=<name>` where `<name>.gguf` is in the models directory: the variable used as a label for a file.
4. The preset `install` most recently selected.
5. The file `gemma-3-4b-it-Q4_K_M.gguf`, if it is present.

The runtime refuses to start on a preset whose file is missing or the wrong size (checked by size only at start, since hashing a 14 GB file on every restart would cost minutes and `install` already hashed it), and the refusal names the install command that fixes it. To move from an explicit file onto a preset, remove `ASSISTANT_MODEL_FILE` and `ASSISTANT_MODEL` from `.env`, run the install command for the preset, and restart the assistant (§9.3).

### 9.3 Starting it

```bash
docker compose --profile assistant up -d
```

To pick up a different installed model on an already-running deployment, restart the service rather than bringing it up again:

```bash
docker compose --profile assistant restart assistant
```

The profile is why an ordinary `docker compose up -d` does not start it. Nothing is published to the host, for the same reason Reverb is not (§2.1): an inference endpoint with no authentication is not something to expose.

Its health check probes llama.cpp's own `/health`, which returns 503 while the model is loading and 200 once it is ready, so `service_healthy` means ready. `start_period` is 180 s because reading several gigabytes from disk is slow.

The llama.cpp image is pinned by tag and digest (`ghcr.io/ggml-org/llama.cpp:server-b11176@sha256:6257697a…`): `:server` moves, and which build an instance runs should be answerable by looking. The pin is the `FROM` line of `docker/assistant/Dockerfile`, which adds the launcher and the catalogue and nothing else (§2.4); a local edit to either file takes effect with `--build`. `assistant.sh` starts `llama-server` with `-c ${ASSISTANT_CONTEXT_TOKENS}` (default 12288), `-t ${ASSISTANT_THREADS}`, `--reasoning off` and `--cache-ram` set per preset from `models.conf` (2048 MiB for `small`, 4096 for `large`). The context size is the same number on both sides because the runtime silently truncates a longer prompt, dropping its front, so the app must know it to refuse one.

### 9.4 Resources

| | |
|---|---|
| Resident | `small`: ~3 GiB, plus up to 2 GiB for the bounded host-RAM prompt cache (`--cache-ram`). `large`: ~24 GiB, plus up to 4 GiB of prompt cache; budget 28 |
| Threads | `ASSISTANT_THREADS`, default **4**. The container's CPU quota (`cpus`) is set to the same number, and a host with fewer CPUs than this must lower it or the container will not start |

**Set `ASSISTANT_THREADS` to the number of cores you are willing to give it; the container's quota follows automatically.** Setting `-t` higher than the cores the container may use makes it slower, not faster, and an unconstrained runtime contends with the database it shares a host with. Past about four to six cores generation barely improves, because it is memory-bandwidth bound; prompt processing scales more usefully, so a long fixed prompt (the help corpus, a whole assessment) is what more cores help with.

**CPU inference is slow in a way that changes designs, not just timings.** Measured at 4 cores on a modern desktop CPU, with a help corpus of about 6,500 tokens:

| Job | `small` | `large` |
|---|---|---|
| Help answer, first after a restart (no warm-up) | 98 s | 116 s |
| Help answer, after | 9 s | 10.5 s |
| Suggestions (five) | 20 s | 21–23 s |
| Executive summary, 73-node assessment | 118 s | 142 s |

Generation runs at roughly 13 tok/s (`small`) and 11 tok/s (`large`); prompt processing at roughly 71 and 60 tok/s. Every timeout in `config/assistant.php` is explicit for this reason: HTTP client defaults are wrong here by one to two orders of magnitude, and because generations stream, the timeout that protects anything is the gap between tokens (`ASSISTANT_STREAM_IDLE_TIMEOUT`, default 120 s), not the length of the answer.

The scheduler runs `assistant:warm` every minute while the assistant is configured. It reads the help corpus into the runtime's cache once per runtime start, and only when the help feature is available, so the first real question after a restart gets the "after" row above. Nothing needs configuring.

Re-measure on your own host before promising anyone a response time: `tools/spike/assistant/candidates.sh` needs only bash, curl and Docker.

---

## 10. Acceptance checks

1. From a clean clone with only `cp .env.example .env` and the database passwords set, `docker compose up --build -d` reaches a working login or first-run setup screen. No manual key generation, no manual migration, no manual permission fix.
2. `docker compose down && docker compose up -d` preserves all data.
3. Deleting `${DATA_PATH}` and starting again produces a clean install with the first-run setup screen.
4. No container publishes a port other than `app` (the development override file aside, §2.1).
5. `docker compose config` contains no secret and no absolute host path.
6. Behind a TLS-terminating proxy, every generated URL is `https://`, and the websocket connects over `wss://` on the same origin.
7. Total idle memory across the always-on services is under 512 MB, measured with `docker stats`.
8. The `docker` CI job passes.
