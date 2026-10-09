# ArgueSecure 2.6.2 — behind a reverse proxy, for real

> Follows [`RELEASE-2.6.1.md`](RELEASE-2.6.1.md). Tagged on `34e512d`,
> `master` after pull request #5, so it carries the reverse-proxy fix found by
> 2.6.1's first real deployment, two security updates, and
> [`PLAN-2.7.md`](PLAN-2.7.md)'s **W102** (control citations), which was
> merged to `master` after 2.6.1 was cut. W102 was planned for 2.7.0 and ships
> here instead; it adds one migration, which only adds.

---

## The headline

2.6.1 served a blank page behind SWAG to anyone reaching it from the internet:
its CSS and JavaScript links came out as `http://` and the browser blocked
them as mixed content. Every test, CI job and LAN visitor uses a private
address, and the bug only appears for a public one, so the first real
deployment was the first place it showed. 2.6.2 fixes it, and takes two
upstream security updates that turned the Security check red the same week.
It also brings W102: a defence can now cite the framework control it
implements, and the report shows each framework's coverage.

## 1. Assets load over https behind a proxy

`docker/nginx.conf`, `tests/Unit/Deployment/NginxProxyTrustTest.php`, `docs/specs/deployment.md` §4

- **The cause.** The image's nginx uses `real_ip` so its access log shows the
  visitor, which rewrites `$remote_addr`, and `fastcgi_params` passed that to
  php-fpm as `REMOTE_ADDR`. `TrustProxies` honours `X-Forwarded-Proto` only
  from a private `REMOTE_ADDR`, so a visitor from a public IP was not a trusted
  proxy, the proxy's `https` was ignored, and Laravel built `http://` URLs.
- **The fix.** nginx passes `$realip_remote_addr`, the address the request
  actually came from, as `REMOTE_ADDR`. Laravel trusts the proxy and resolves
  the visitor from `X-Forwarded-For` itself, so `request()->ip()` is unchanged.
- **Proven against real nginx and php-fpm**, not only by reading: a public
  `X-Forwarded-For` gave `scheme=http` before and `scheme=https` after, with
  the client IP `203.0.113.7` either way; a LAN visitor gave `https` both times.
- **Guarded** by a `FIXED:` test, mutation-tested three ways: the line removed,
  moved above the `fastcgi_params` include, and reverted to `$remote_addr`.

## 2. Security updates

`composer.lock` only; `composer.json` is unchanged.

| Package | From | To | Advisory |
|---|---|---|---|
| `laravel/framework` | 12.64.0 | 12.69.3 | GHSA-jh5r-qr3c-85q8, debug-page XSS (low) |
| `league/flysystem` | 3.35.2 | 3.36.0 | GHSA-cxf4-7mrp-vvpr, path-normaliser bypass (low) |

Both advisories were published on 2026-09-29. Neither is reachable in a
correctly configured deployment (`APP_DEBUG=false`; no user-supplied storage
paths), but the audit gate is right to refuse them.

## 3. Control citations and a coverage view (W102, D64–D66)

The details, and why, are in [`PLAN-2.7.md`](PLAN-2.7.md)'s W102.

- **A `control` reference kind** on defences: `FRAMEWORK:CONTROL` for
  ISO 27001:2022, ISO 42001:2023, the EU AI Act, GDPR, NIS2, SOC 2 and CIS v8.
  Validated against each framework's own pattern; its link is derived, never
  taken from input.
- **The seven standards templates** cite the control each defence's title
  names (506 citations).
- **The report** gains a *Control coverage* section, on screen and in the HTML,
  Word and Excel exports: per framework, each cited control, the defences
  citing it and whether they are in place.

## Upgrading from 2.6.1

With `ARGUESECURE_VERSION=2.6` in `.env`, a pull picks it up: take a database
dump, then `docker compose pull && docker compose up -d`. The W102 migration
runs on start. A source checkout can `git checkout v2.6.2` first so its
compose file matches; this release does not change it.

**Templates already loaded stay as they were.** The template seeder skips a
template that exists, so an instance set up on 2.6.1 or earlier keeps its
standards templates without the control citations. Defences you add can cite
controls straight away. A fresh install, or a template loaded for the first
time, gets the citations.

**If you worked around the bug in your proxy**, for example by not forwarding
`X-Forwarded-For` or by hard-coding `X-Forwarded-Proto`, put the standard
configuration back once 2.6.2 is running. Without the workaround, 2.6.1 breaks
again.

## Verification behind this release

- Pint, PHPStan and Pest (1,274 passed, 2 skipped) on this lock; `composer
  audit` clean.
- On pull request #5's head, every CI job passed: PHP, JS, Security, Docker
  build and boot, E2E, and the Image workflow's image-only boot test. W102
  passed the same jobs on pull request #2.
- The tag's Image run published `2.6.2` and moved `2.6` and `latest` to it.
- The images for this tag are built and booted again by `image.yml` before
  they are pushed.

## Still open

- Everything under 2.6.1's *Still open* is unchanged.
- **W102 shipped in a patch release**, not in 2.7.0 as planned: `v2.6.2` was
  tagged on `master`. PLAN-2.7 marks it ✓ (2.6.2).
- **Existing templates lack the citations** (see *Upgrading*). Re-seeding a
  template that already exists is not supported, deliberately; whether to
  offer it is a 2.7 question.
- **No test exercises a public client address end to end.** The guard reads
  the nginx configuration; it does not send a request through a proxy. A CI
  step that curls the booted image with a public `X-Forwarded-For` and checks
  the asset scheme would close that, and changes CI, so it is proposed here
  rather than done.
