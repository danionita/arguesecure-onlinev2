# Security Specification

> Implements Phase 0 and Phase 10 item 2 of [`docs/PLAN.md`](../PLAN.md). Work packages **W4** (legacy remediation) and **W24** (hardening), with authorization rules binding on **W6** and every package that adds a route.
>
> Related: [schema.md §5](schema.md#5-authorization), [deployment.md](deployment.md).

---

## 1. Why this document exists

The legacy application has defects that are not theoretical. They are listed here because the rewrite must not reproduce them, and because two of them affect the currently-running instance today.

| # | Defect | Severity |
|---|---|---|
| 1 | `.env` with a real `APP_KEY` committed to git. On Laravel 5.2 a known `APP_KEY` is **remote code execution** (CVE-2018-15133) via a forged encrypted `X-XSRF-TOKEN`. `.env-deploy` contains a second live key. | Critical |
| 2 | The Node websocket relay subscribes every anonymous socket to one global Redis channel and re-emits **every** node event for **every** assessment. Privacy is filtered client-side in `arsec.js`. Any client reaching port 3002 reads private assessments. | Critical |
| 3 | Hardcoded `admin` / `XDARSEC` bootstrap in `migrate.sh`, run on every container start, documented publicly in the README. | Critical |
| 4 | `show` actions on risks, attacks and defences have no `authorize()` call and no global scope; nor does the AJAX node-render endpoint. Direct object reference reads private data. | High |
| 5 | `RiskPolicy` / `AttackPolicy` / `DefencePolicy` return `$node->tree->public \|\| owner` for `update` and `destroy` — any logged-in user can edit or delete the contents of any public assessment. | High |
| 6 | `config/session.php` has **no `http_only` key at all**, so the session cookie is readable by JavaScript. `secure` is `false`. | High |
| 7 | `NodeController` takes an attacker-supplied `type` string into `class_exists()` and `ReflectionClass::newInstance()`. | Medium |
| 8 | `NodeController` writes an attacker-chosen column via `$model->fill([$field => $value])`, bounded only by `$fillable` — which includes `Defence::tree_id` and `Tree::public`. | Medium |
| 9 | `barryvdh/laravel-debugbar` is in `require`, not `require-dev`; committed `.env` sets `APP_DEBUG=true`. | Medium |
| 10 | Admin authorization is `$request->user()->name != "admin"`, repeated nine times. Superuser password minimum length is 4. | Medium |
| 11 | `dompdf 0.6` (remote file inclusion / RCE class), `swiftmailer 5.4.1` (CVE-2016-10074), jQuery 1.11.3, Bootstrap 3.3.5, socket.io 1.3.7 — all unpatched. | Medium |

SQL injection is genuinely **not** among them — Eloquent is used consistently and there is exactly one raw query, which is parameterless. CSRF protection is also intact.

---

## 2. Phase 0 — legacy secret remediation (W4)

Do this first, independently of the rewrite.

1. **Tag `v1-final`** so the running system stays recoverable.
2. **Add to `.gitignore`:** `.env`, `.env-deploy`, `*.local`, `/storage/backups`.
3. **Untrack** `.env` and `.env-deploy` (`git rm --cached`).
4. ~~**Purge both from history** with `git filter-repo`.~~ **Considered and declined — see §2.1. Do not reopen this.**
5. **Remove the hardcoded credential bootstrap** from `migrate.sh` and the README.
6. **Rotate anything else that was ever in those files.**

Both committed `APP_KEY` values are permanently burned. The rewrite generates a fresh key on first deploy and never ships one.

### 2.1 The history purge: declined, with reasoning

The original plan called for rewriting history with `git filter-repo`. After
enumerating what the four commits touching `.env` and `.env-deploy` actually
contain, the owner declined it on 2026-08-04. **This is a settled decision.**

What is in history, in full:

| Value | Reality |
|---|---|
| `APP_KEY` in `.env` | Real, 32 chars |
| `APP_KEY` in `.env-deploy` | Real, 32 chars, different |
| `DB_PASSWORD` | `secret` — Laravel's stock placeholder |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | `null` — never configured |

Two application keys and nothing else. No mail credential, no real database
password, no API tokens. Why that does not justify a rewrite:

- The keys are exploitable only against a **running Laravel 5.2 instance still
  using them** (CVE-2018-15133). v2 generates its own key on first boot, so
  they are inert from cutover onward.
- The repository is **private**. Anyone able to read the history today could
  have cloned those values years ago, and rewriting history does not reach
  those clones. Purging removes the evidence, not the exposure.
- Every commit SHA changes, a force-push is required, and every existing clone
  breaks — including the deployment's.

Rotation is the control that actually revokes a secret, and here it happens for
free: the rewrite generates a new key regardless. The purge was the weaker
control that merely felt stronger.

**Still outstanding:** `.env` and `.env-deploy` remain *tracked on `master`*, so
the v1 branch continues to ship them. Untrack them there at cutover — a normal
commit, not a rewrite.

**What would reverse this decision:** evidence that either `APP_KEY` was reused
for another application, host, or account. Nothing else.

---

## 3. Authorization

### 3.1 One policy, one source of truth

`AssessmentPolicy` decides everything. `NodePolicy` and `CommentPolicy` load the owning assessment and delegate. **No policy may grant access based on a node's own attributes** — that is defect #5, and it is what let any user delete another user's work.

Abilities and the roles that satisfy them:

| Ability | admin | owner | manager | editor | commenter | viewer | public | share token |
|---|---|---|---|---|---|---|---|---|
| `view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `comment` | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | — |
| `update` (nodes, links, values) | ✅ | ✅ | ✅ | ✅ | — | — | — | — |
| `manage` (share, rename, scales, snapshot) | ✅ | ✅ | ✅ | — | — | — | — | — |
| `delete` (soft) | ✅ | ✅ | ✅ | — | — | — | — | — |
| `transferOwnership`, hard delete | ✅ | ✅ | — | — | — | — | — | — |

Resolution order: `is_admin` → owner → `assessment_user.role` → `is_public` (view only) → a valid share token held in the **session** (view only).

`is_public` grants **read only**. This is a deliberate behaviour change from v1, where public meant world-writable. Note it in the release notes; anyone relying on the old semantics should use an editor share instead.

### 3.1a Templates (W45; revised in 2.5.1)

A template is reference material for the whole instance: every signed-in account can list it, start an assessment from it, and — since the "add from template" picker — copy single nodes out of it. So what the library contains is an instance-wide decision, and 2.5.1 narrowed who makes it.

| Ability | Who | What it allows |
|---|---|---|
| `saveAsTemplate` | admin only | set or clear `is_template` (the Share dialog's template panel) |
| `useAsTemplate` | any signed-in account, on an assessment with `is_template = 1` | list it (`templates.index`, `templates.options`), clone it (`templates.use`), read one type of its nodes (`templates.nodes`) |

- **Publishing is an admin's decision.** Through 2.5 the owner could publish too. It is narrower than `manage` on purpose: every other `manage` action decides who inside an assessment can do what, and this one offers the assessment's contents to every account, which cannot be withdrawn from whoever has already copied it.
- **Publishing moves `is_template` and nothing else.** Through 2.5 it also set `is_public`, because using a template needed `view`; `useAsTemplate` replaced that, so a template no longer has to be readable by everyone to be usable by everyone, and the instance's `public_assessments_allowed` setting no longer stands between an admin and the library. A template is opened in the editor only by those `view` already admits.
- **`useAsTemplate` reads, it never writes.** Being a template adds no write ability to anyone. Every route behind it answers **404** for an assessment that is not a template, so none of them can be used to read the titles of an arbitrary assessment by uuid.
- **`templates.nodes` returns titles, descriptions, the transfer flag and parent titles — never values.** A template's impacts and likelihoods describe its example system; copying them into someone else's assessment would present them as that assessment's own judgement (`app/Domain/Templates/TemplateNodes.php`). The copy itself goes through the ordinary `nodes.store` write, under the *target* assessment's `update`.
- **Cloning is a copy, never a link**, and the copy is not a template: `is_template` and `is_public` are both false on it, so starting from a template never publishes the private work that follows.

Required tests, and this is the part that matters: assert each write verb **separately** — PATCH a node, DELETE a node, add a node, share, generate a share link, delete the assessment, toggle the template flag, tag, comment, change settings. A single "a stranger cannot edit a template" test goes green while one route stays open, and the open one will be whichever was added last. See `tests/Feature/Templates/TemplateSecurityTest.php`; the picker's two routes are in `tests/Feature/Templates/TemplateNodesTest.php`.

### 3.1b Assignees (W46)

An assignment is a label, not a grant. It confers **no** ability: the assignee's collaborator role decides everything, exactly as it did before they were named.

- **Assigning is `update` on the assessment.** Unassigning too.
- **There is no node-scoped ability.** `NodePolicy` still delegates upward without reading a node's own attributes, and `node_assignees` has no role column to read even if something tried. This is the rule that fixed legacy defect #5 and W46 does not weaken it.
- **Adding a new person is `manage`, whatever route it arrives through.** The assign endpoint has one path that shares the assessment at `viewer` with someone who is not on it, taken only when the caller asks for it explicitly in the same request. It requires `manage`, not the `update` that assigning needs — otherwise any editor could hand read access to a whole assessment to anyone with an account, through a route whose name says nothing about sharing.
- **Never as a side effect.** The UI never sends that flag; sharing happens in the Share dialog where the roles are explained. Assert this on the client too — a picker that quietly grants access is the disclosure the rule exists to prevent.
- **Names, never addresses.** The assignee list is readable by everyone who can view the assessment, which for a published template is every account on the instance.
- **Reports name nobody by default**, and the one-click Download does not turn it on. A report is a document that leaves the room; who was responsible for which line is internal information the exporter chooses to include.

### 3.1c Mentions and notifications (W47)

**`@name` matches against the assessment's collaborators only — never against `users`.** This is the security content of the notification package. Matching globally turns every comment box into an account-existence oracle: type `@dana`, observe whether anything happens, and you have learned that a Dana has an account on this instance, from inside an assessment she has nothing to do with. There is no feature behind that disclosure, so the query never touches the users table unfiltered. Assert it directly — that a non-collaborator named in a comment has **no notification row at all**, not merely that they were not shown one.

An ambiguous first name resolves to nobody. Guessing between two Danas sends the message to the wrong person; notifying both sends half of them something that was not for them. Both failures are invisible to the author, so neither is taken.

**Deactivated accounts are not candidates.** They cannot read the assessment, so a notification would sit unread while the author believes they reached someone.

**The feed has no policy, and needs none.** There is no assessment in the route: every query is scoped to `$request->user()->notifications()`, so the relation answers "whose feed is this?" before the database does. Another user's notification id finds no row and **404s** — a 403 would confirm the id exists and belongs to somebody.

### 3.2 The share token

- 32 random bytes, base64url, stored in `assessments.share_token`, compared in constant time.
- Presenting the token at `/s/{token}` establishes a **session flag scoped to that assessment id**, then redirects to a clean URL so the token does not sit in browser history, referrers or server logs.
- Grants `view` only. Every mutating endpoint must reject a token-only session — verify this with an explicit test that hand-crafts a mutation.
- Revocable and regenerable by the owner or a manager. Revocation invalidates existing sessions, so store the token's `created_at` in the session and compare.
- Token-only sessions are excluded from presence and from all broadcasts carrying user identity.

### 3.3 Required tests (W6 and every route-adding package)

- A **route-coverage test** enumerating `Route::getRoutes()` and asserting every non-public route has authentication middleware and that every route touching an assessment invokes the policy. This is the test that would have caught defect #4.
- A **matrix test** over {admin, owner, manager, editor, commenter, viewer, token-only, stranger, guest} × {view, comment, update, manage, delete}.
- An **IDOR test** per node-scoped endpoint: authenticate as a stranger and request a node belonging to someone else's private assessment by direct id. Expect 403 or 404, never 200.
- A **mass-assignment test** asserting that `assessment_id`, `type`, `created_by`, `calc_version` and `owner_user_id` cannot be set from request input.

---

## 4. Authentication

- **No public registration.** Login only, throttled to 5 attempts per minute per IP and per email, with a generic failure message that does not reveal whether the account exists.
- **First-run setup.** When `users` is empty the app serves a one-time setup screen creating the first admin. Guard it with a check that the table is still empty at the moment of submission, inside a transaction. **Never seed a credential**, in any environment, including tests and fixtures.
- **Passwords** — argon2id via Laravel's hasher, `users.password varchar(255)` so the algorithm can change later. Minimum 12 characters, checked against Laravel's compromised-password rule (`Password::min(12)->uncompromised()`), which uses the k-anonymity HIBP range API. **If outbound network access is unavailable, degrade to length and complexity only — never block login.**
- **Changing a password requires the current one.** Changing an email requires the current password. Both invalidate other sessions.
- **Admin password reset** sets a temporary password shown once on screen and forces a change on next login. No email is sent; there is no SMTP dependency.
- **Session invalidation** on password change, role change, deactivation and deletion.
- **`is_active` is enforced on every request, not only at login (W8).** Session
  invalidation alone is reactive: it depends on the deactivating code path
  reaching every one of that user's sessions. Add middleware on the
  authenticated route group that logs out and rejects any user whose
  `is_active` is false, so a missed session cannot keep a deactivated account
  working. **This belongs in authentication, not in `AssessmentPolicy`** —
  account status is not an assessment relationship, and putting it in the
  policy would leave every non-assessment route uncovered. W6 deliberately left
  the policies alone for this reason.
- No "remember me" for admin accounts.

---

## 5. Sessions and cookies

Explicitly set — defect #6 was an *omission*, so state every value rather than relying on a default:

```php
'driver'         => 'redis',
'lifetime'       => 480,        // minutes, configurable
'expire_on_close'=> false,
'encrypt'        => true,
'http_only'      => true,       // ← absent entirely in the legacy config
'secure'         => true,       // via env, true in any non-local environment
'same_site'      => 'lax',
'partitioned'    => false,
```

`SESSION_SECURE_COOKIE=true` in every deployed environment. Behind the SWAG reverse proxy this depends on `TrustProxies` being configured correctly — see [deployment.md §4](deployment.md#4-reverse-proxy).

Regenerate the session id on login and on privilege change.

---

## 6. Content Security Policy

Strict, with per-request nonces. No `unsafe-inline`, no `unsafe-eval`.

```
default-src 'self';
script-src  'self' 'nonce-{random}';
style-src   'self' 'nonce-{random}';
img-src     'self' data:;
font-src    'self';
connect-src 'self' wss://{app_host};
frame-ancestors 'none';
base-uri    'self';
form-action 'self';
object-src  'none';
```

**Everything is self-hosted.** No CDN, no external font, no external icon set, no remote image. The legacy app loaded socket.io from `cdn.socket.io` with no SRI; that class of dependency is gone.

Additional headers: `Strict-Transport-Security` (2 years, includeSubDomains), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY`, and a `Permissions-Policy` denying camera, microphone, geolocation, USB and payment.

---

## 7. Input handling

- **Validate in Form Requests**, never in controllers. Every request class has an `authorize()` that calls the policy — not `return auth()->check()`, which is what the legacy request classes did.
- **Never `$request->all()`.** Use `$request->validated()` or an explicit `only([...])`. Defect #8 came from the general pattern.
- `$fillable` on every model. `assessment_id`, `type`, `uuid`, `created_by`, `owner_user_id` and `calc_version` are **never** fillable; they are set by services.
- **No dynamic class instantiation from request input, ever.** Defect #7 is fixed by making node type an enum resolved through a hardcoded map, never through `class_exists()` or reflection.
- **No dynamic column names from request input.** The legacy generic `field`/`value` endpoint is replaced by typed endpoints per node type with named, validated fields.
- **Markdown** in comments is rendered through a hardened parser with raw HTML disabled, then sanitized. Render on the server; never `v-html` unsanitized content.
- **Uploads** — the CSV import is the only one. Enforce a size limit, parse in a streaming reader, never save the file to disk, never accept a filesystem path from input, and reject ragged rows explicitly rather than silently.
- **External reference identifiers** are regex-validated ([schema.md §3.12](schema.md#312-node_references--cve-and-friends)) and the URL is **derived from the identifier**, never taken from input, except for the `url` kind which must match `https?://` and renders with `rel="noopener nofollow"`.

---

## 8. Websocket authorization

The single most important architectural fix. Defect #2 existed because the relay had no concept of a user.

- **Reverb** with `presence-assessment.{id}` channels, authorized server-side in `routes/channels.php` through `AssessmentPolicy::view`.
- **No global channel.** Nothing broadcasts to an "everyone" channel, ever.
- Broadcast payloads carry only what the recipient is already authorized to read. Do not broadcast a full model and rely on the client to filter — that was the legacy design.
- Token-only (share-link) sessions do not join presence channels and receive no broadcasts carrying user identity.
- The Reverb port is **not** published to the host; it is proxied under the app's own hostname at `/app` so it inherits TLS and the same origin. See [deployment.md](deployment.md).

---

## 9. Rate limiting

| Endpoint group | Limit |
|---|---|
| Login | 5/min per IP **and** per email |
| First-run setup | 5/min per IP |
| Password change / email change | 5/min per user |
| Node and link mutations | 120/min per user |
| Report generation | 10/min per user |
| Search | 60/min per user |
| Share-token resolution `/s/{token}` | 10/min per IP — this is the brute-force surface for tokens |
| Assistant generation | 6/min per user, **and one at a time** |
| Everything else authenticated | 300/min per user |

Rate-limit state lives in Redis so it holds across containers.

**The assistant's second half is not a rate.** A generation occupies physical CPU cores for tens of seconds — 28s for a suggestion, up to 249s for a summary — on the machine that is simultaneously serving every other user. A per-minute limit cannot see concurrency: six requests inside one second are six requests, comfortably under any ceiling, and six generations competing for the same cores make the instance unusable for everybody while the limiter's accounting stays happy. `App\Domain\Assistant\Generation\SingleFlight` holds a per-user lock so a second generation is refused rather than queued — queued would hold a php-fpm worker for minutes, which is the same exhaustion arriving through the front door.

The assistant's generating routes also sit behind `auth`, while the help pages they read from do not. That asymmetry is deliberate: the pages are public product documentation costing a file read, and an anonymous endpoint that spends seconds of CPU per call is a free denial-of-service primitive against the whole instance.

---

## 10. Dependencies and supply chain

- **No paid libraries or services**, per the project constraint.
- `composer.lock` and `package-lock.json` are both committed. The legacy repo had no `package-lock.json` at all, so builds were unreproducible.
- CI runs `composer audit` and `npm audit --omit=dev`; a high or critical advisory fails the build.
- Enable Dependabot for composer, npm and GitHub Actions.
- Pin GitHub Actions to commit SHAs, not tags.
- Docker base images pinned to a specific digest, refreshed deliberately.
- **Do not reintroduce**: `dompdf`, `laravelcollective/html`, `lord/laroute`, `laravel-elixir`, `swiftmailer`, `socket.io`, jQuery, Bootstrap.

---

## 11. Logging and error handling

- `APP_DEBUG=false` in every deployed environment. Debugbar, if used at all, is `require-dev` only.
- Log authentication events, authorization failures, admin actions, share-token creation and revocation, and role changes — with actor, target, IP and timestamp.
- **Never log** passwords, tokens, session ids, or full request bodies of auth endpoints.
- Generic error pages. No stack trace, no framework version, no SQL ever reaches the browser.
- Redact `share_token` and `password` from all log context via a Monolog processor.

---

## 12. Data protection

- The tool holds risk assessments about client systems — commercially sensitive, occasionally covered by NDA. Treat assessment content as confidential by default; that is why `is_public` defaults to `0` and is read-only.
- Deleting a user offers **transfer of owned assessments** before destruction, and states exactly what will be deleted.
- Soft deletes plus a scheduled purge (default 30 days), documented so users know retention is finite.
- Backups (`storage/backups`) are excluded from git and must be excluded from any public bind mount.

---

## 13. W24 acceptance criteria

- Every item in §3.3 has a passing test.
- CSP is active with nonces and there are **zero** console violations on every page, verified in the E2E run.
- A scan of the built assets finds no external host reference.
- `composer audit` and `npm audit --omit=dev` are clean at high and critical.
- No secret, key, password or token literal exists anywhere in the working tree — assert with a `gitleaks` (or equivalent) step in CI, not by eye.
- Session cookies carry `HttpOnly`, `Secure` and `SameSite=Lax`, verified by an integration test asserting the `Set-Cookie` header.
- A token-only session receives 403 on every mutating endpoint.
- `APP_DEBUG=false` and the error page leaks nothing, verified by triggering a 500 in a test.
