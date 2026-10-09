# ArgueSecure 2.0.1 — release notes

A security and release-engineering follow-up to 2.0.0. No new features, no
schema change, no configuration change required by an existing deployment.

Read `docs/RELEASE-2.0.0.md` first if you are new to 2.x — this file only
covers what moved since.

---

## Why this release exists

2.0.0 shipped with two sets of known limitations, both recorded honestly in
its own tag message and release notes rather than hidden. This release closes
both.

**The four release-scenario defects (W27).** These were found by writing the
twelve end-to-end scenarios and were merged after 2.0.0 was tagged, which is
the main reason 2.0.1 exists at all. The largest by far:

- **Real-time collaboration never connected in any deployment.** Two
  independent causes, either of which alone was fatal. `useRealtime.ts` read
  `import.meta.env.VITE_REVERB_APP_KEY`, which Vite substitutes at *image
  build* time, while `docker/entrypoint.sh` generates that credential at
  *container start* — so the shipped bundle contained no key and never could.
  Separately, `docker/nginx.conf` proxied `/app` to `127.0.0.1:8080`, which is
  inside the app container, while Reverb runs as its own service. The key now
  arrives as a shared Inertia prop and nginx proxies to `reverb:8080`.
- **Totals updated on reload, not live.** The optimistic recompute threw
  "Cannot band-map a value against an empty scale" into a bare `catch {}` on
  every keystroke, because `Show.vue` never declared the `scaleLevels` and
  `scales` props the controller had always been sending.
- **Drag and drop was not wired up.** `useDragDrop.ts` was imported by nothing.
- **The share dialog had no mount point,** and the share routes bound by
  primary key while every payload exposes only `uuid`.

**The five security defects pinned through W24 (W28).** W24 found these
outside its own file ownership and pinned each as a test asserting the
defective behaviour, per this repository's convention. They are now fixed, and
every pinned block was rewritten in place as a `FIXED:` regression guard
rather than deleted, so the reasoning survives with the fix.

| Was | Now |
|---|---|
| Login throttling keyed on the email+IP pair only | A second limiter keyed on the IP alone, hit and cleared alongside the first |
| The guest landing page shipped exact Laravel and PHP version strings | Both props removed from the `/` route |
| No write endpoint the tree editor uses carried any rate limit | `throttle:120,1` on node and link mutations, `throttle:300,1` as the authenticated default, `throttle:5,1` on password change, password confirmation and email change |
| A 404 for an unmatched URL, and `/up`, carried no security headers | `SecurityHeaders` registered globally, plus a `withExceptions()->respond()` hook for responses rendered outside the pipeline |
| The Ziggy route-table script carried no CSP nonce | Fixed in 2.0.0; the guard remains, and is what keeps the CSP enforceable |

The first of those is the one that mattered. A combined email+IP key does not
bound password spraying at all: every new email address is a fresh bucket, so
the real per-IP budget was five attempts multiplied by however many accounts
an attacker cared to guess at. The `security.md` §9 requirement was always
"5/min per IP **and** per email" — two independent limiters — and only one of
them existed.

---

## Also in this release

**The end-to-end scenarios now run in CI.** A fifth workflow job boots a
throwaway compose stack on its own port and data directory, generates every
credential at runtime, and runs all twelve Playwright scenarios in order. Those
scenarios had found eight defects between them, including the broken real-time
stack above, and until now they ran only when someone remembered to run them
locally. Given this repository once went nine work packages with a fully red
CI that nobody noticed, that gap was worth closing.

---

## Upgrading from 2.0.0

Pull and redeploy. Nothing else.

```bash
docker compose up --build -d
```

There is no migration, and no `.env` key was added or renamed.

Two things will *start working* that previously did not, which may look like a
change in behaviour: presence avatars and live updates now actually appear for
a second user in the same assessment, and totals update as values are typed
rather than on reload.

---

## Verification behind this release

Every check was run, not assumed.

| Check | Result |
|---|---|
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpstan analyse` (level 6) | no errors |
| `php artisan test` | 588 passed |
| `npm run lint` | passed |
| `npm run type-check` | passed |
| `npm run test:unit` | 169 passed |

Each of the four security fixes was additionally **mutation-tested**: the fix
was neutered, the guard confirmed red, and the fix restored. That is worth
recording, because one guard did not survive it. The rate-limiting pin
asserted that no route carried *a* `throttle` middleware, so once the fix
landed, deleting the tighter `throttle:120,1` from the node-mutation group
left the test green — the enclosing group's `throttle:300,1` also matches a
prefix check. A presence assertion is not a guard. The test now asserts the
specific rate `security.md` §9 names, and fails when it is dropped.

---

## Still open

**One pinned defect remains:** `CatalogInserter` issues a query per descendant
(`tests/Feature/Performance/`). It is a performance defect, not a security or
correctness one, and it is pinned as a test asserting today's behaviour.

**`master` is still 1.x** in the original repository, deliberately, so both
versions can run in parallel. 2.x lives on `v2/develop` there, and on `master`
in the `arguesecure-online-v2` repository.

**The git-history purge remains declined** (2026-08-04), and that decision is
settled — see `docs/specs/security.md` §2.1. Both historical `APP_KEY` values
are dead. Do not reopen it.
