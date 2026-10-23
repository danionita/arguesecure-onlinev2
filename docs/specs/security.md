# Security Specification

> Implements Phase 0 and Phase 10 item 2 of `docs/PLAN.md`. Work packages **W4** (legacy remediation) and **W24** (hardening), with authorization rules binding on **W6** and every package that adds a route.
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
| `saveAsTemplate` | admin only | publish an assessment as a template (the Share dialog's template panel): copies it into a new, ownerless template and writes nothing to the original (W141) |
| `useAsTemplate` | any signed-in account, on an assessment with `is_template = 1` | list it (`templates.index`, `templates.options`), clone it (`templates.use`), read one type of its nodes (`templates.nodes`) |

- **A template has no owner and only admins reach it (W141).** `view`, `comment`, `update`, `manage` and `delete` on a template are admin-only whatever `is_public` or a stray collaborator row says; `transferOwnership` is false for everyone, and `forceDelete` (erasing one that reached the trash) is admin-only. Every sharing route (`share.*`) answers **403** for a template, an admin included (`RejectTemplate`), so no collaborator, public flag, share link or ownership change can attach to one. `owner_user_id` is `NULL` exactly when `is_template = 1`, enforced by a database CHECK. Admins create, edit and delete templates on the Templates page; deletion is permanent.
- **Publishing is an admin's decision.** Through 2.5 the owner could publish too. It is narrower than `manage` on purpose: every other `manage` action decides who inside an assessment can do what, and this one offers the assessment's contents to every account, which cannot be withdrawn from whoever has already copied it.
- **Publishing copies, and writes nothing to the original.** Through 2.6 it flipped `is_template` on the assessment itself (and through 2.5 also set `is_public`); since W141 the template is a separate row, so a template never has to be readable by everyone to be usable by everyone, and the instance's `public_assessments_allowed` setting does not stand between an admin and the library. There is no unpublish: a published copy is removed by deleting it.
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

- **No public registration.** There is no registration form or route. Login is throttled to 5 attempts per minute per IP and per email, with a generic failure message that does not reveal whether the account exists. The one way an account appears without an admin is single sign-on (below), and only for the operator's own email domains.
- **Single sign-on with OpenID Connect (W117, D74-D76, D79).** Authorization-code flow with PKCE (S256), `response_mode=query`, one provider per instance, configured on the admin Authentication tab or in the environment (`OIDC_ISSUER`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`, `OIDC_BUTTON_LABEL`, `OIDC_ALLOWED_DOMAINS`, `OIDC_TRUST_EMAIL`). **Stored settings (W133, D106):** the tab writes the same six fields to `instance_settings`; a field set in the environment wins over a stored one, field by field. The client secret is stored with the `encrypted` cast (so `APP_KEY` guards it, as it does the two-factor secrets), is in the model's `$hidden`, and is write-only in the UI: no page, response or log line carries it, and a save is logged as the names of the fields changed, never values. Rotating `APP_KEY` makes it unreadable and the admin enters it again. Anyone with admin rights can point sign-in at another provider, as an admin could already create admins; the security event is the record. *Check provider* fetches the saved issuer's discovery document (admin only, 6/min per user, no credentials sent). Unset issuer: no link, and `/auth/oidc/redirect` and `/auth/oidc/callback` answer 404. Both are GETs (CSP `form-action 'self'` would block a form redirecting to the provider; the callback is a top-level navigation that the `same_site=lax` cookie accompanies). `state`, `nonce` and the verifier live in the session and are good for one callback and ten minutes. The ID token is checked for signature (algorithm from an allow-list of RS/PS/ES, never `none` or HMAC; keys from the discovery document's `jwks_uri`, cached, refetched once on failure), `iss`, `aud`, `azp`, `exp`, `iat` and `nonce`. Discovery must be https (or localhost) and its `issuer` must equal the configured one. Who signs in: the account already linked to `(iss, sub)`; else the active account with the token's email, linked once, if the email is verified (or `OIDC_TRUST_EMAIL`) and the account has no identity from this issuer; else, if the email's domain is exactly in `OIDC_ALLOWED_DOMAINS`, a new non-admin account with a random password nobody is shown. Anything else, and every failure, shows the same message as a wrong password. After SSO the session is marked, and the instance's *require two-factor* rule does not apply (the provider's own factor is the organisation's to enforce). *Sign in with SSO only* (off by default; effective only with a provider configured) refuses password sign-in for non-admins; admins keep it as the way in when the provider is down. In that mode a non-admin's email change needs a provider sign-in (`prompt=login`, `max_age=0`) within the last five minutes instead of the current password. An admin can unlink an identity, which ends the account's sessions. Not built: SAML, SCIM, group mapping, single logout, several providers.
- **First-run setup.** When `users` is empty the app serves a one-time setup screen creating the first admin. Guard it with a check that the table is still empty at the moment of submission, inside a transaction. **Never seed a credential**, in any environment, including tests and fixtures.
- **Passwords** — argon2id via Laravel's hasher, `users.password varchar(255)` so the algorithm can change later. Minimum 12 characters, checked against Laravel's compromised-password rule (`Password::min(12)->uncompromised()`), which uses the k-anonymity HIBP range API. **If outbound network access is unavailable, degrade to length and complexity only — never block login.** The lookup is limited to 3 seconds (D84): a slow or silent service means the password is accepted on length alone, not that setup or a password change waits.
- **Changing a password requires the current one.** Changing an email requires the current password. Both invalidate other sessions.
- **Two-factor authentication (W116, D77, D78).** An account can add a TOTP code (RFC 6238, 30-second steps, one step of drift either side) from an authenticator app, with ten single-use recovery codes. With it on, a correct password does **not** sign in: it parks the account id and the *remember me* choice in the session and shows the challenge, which expires after five minutes; the session id changes when the code is accepted, and the remember-me cookie is issued only then. Failed codes are limited to 5/min per pending account and per IP (§9). A code is accepted for a time step greater than the last one accepted, with the step claimed by a conditional `UPDATE`, so a code works once even under concurrent requests. The secret and the recovery codes are encrypted at rest with the app key (so `APP_KEY` guards them), recovery codes are kept only as keyed hashes, and all four columns are in `User::$hidden`, and no Inertia prop, JSON response or log context carries them. An enrolment stores nothing until a code from the new secret is confirmed. Starting it, new recovery codes and turning it off take the current password (turning off also a code), and turning it off or an admin removing it ends the account's other sessions. Per account; the instance setting *Require two-factor authentication for password sign-in* (default **off**) sends an account without it to enrolment after its password. No SMTP, SMS or outside service is involved; passkeys are not built.
- **Admin password reset** sets a temporary password shown once on screen and forces a change on next login. So does creating a user without typing a password, and every account made by CSV import (W115). The password reaches the page as Inertia flash data, which is delivered with one response and kept out of browser history, so pressing Back does not show it again. No email is sent; there is no SMTP dependency.
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
'driver'         => 'database', // fixed, not read from SESSION_DRIVER (D71)
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

**Sessions live in the database's `sessions` table (D71).** Every invalidation in §4, and the profile page's list of signed-in devices with its revoke button, works by deleting that user's rows from it (`SessionRegistry`). Under any other driver the table stays empty and all of them silently do nothing, which is what 2.0–2.6 shipped: the example environment chose Redis. So `config/session.php` does not read `SESSION_DRIVER`; an install that still sets it is ignored, and upgrading to 2.7 signs everyone out once. The suite runs on the same driver, and `tests/Feature/Auth/SessionRevocationTest.php` signs in on two devices and checks each revocation path signs the other one out.

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
| SSO redirect and callback | 30/min per IP (a sign-in is two requests) |
| Two-factor challenge (failed codes) | 5/min per pending account **and** per IP |
| Password change / email change | 5/min per user |
| Node and link mutations | 120/min per user |
| Report generation (the five exports) | 10/min per user |
| Report views (the report page, re-requested by every option toggle) | 60/min per user; 10/min per IP without an account |
| Search | 60/min per user |
| Security log download (admin) | 6/min per user |
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
- CI runs `composer audit` and `tools/dev/audit-gate.mjs` over the whole npm lockfile (not `--omit=dev`, which audits nothing here); a high or critical advisory fails the build unless `tools/dev/audit-allowlist.json` names it with a reason.
- Enable Dependabot for composer, npm and GitHub Actions.
- Pin GitHub Actions to commit SHAs, not tags.
- Docker base images pinned to a specific digest, refreshed deliberately.
- **Do not reintroduce**: `dompdf`, `laravelcollective/html`, `lord/laroute`, `laravel-elixir`, `swiftmailer`, `socket.io`, jQuery, Bootstrap.

---

## 11. Logging and error handling

- `APP_DEBUG=false` in every deployed environment. Debugbar, if used at all, is `require-dev` only.
- Log authentication events, authorization failures, admin actions, share-token creation and revocation, and role changes — with actor, target, IP and timestamp.
- **Never log** passwords, tokens, session ids, or full request bodies of auth endpoints.

### 11.1 The security event log (W111)

Lines go to the `security` channel (`config/logging.php`): stderr, one JSON object per line, level `info` or above whatever `LOG_LEVEL` is, so `docker compose logs` and a log shipper carry it and a SIEM parses it without a grammar. `message` is the event name; `datetime` is the timestamp; `context` always has the same five keys:

| Key | Content |
|---|---|
| `event` | The name from the table below. Never renamed once released; new ones are added. |
| `actor` | `{id, email}` of the signed-in account that did it, or `null` (a guest, a failed sign-in, first-run setup). |
| `target` | What it was done to: `{type: "user", id, email}` or `{type: "assessment", id: <uuid>}`, or `null`. For a sign-in that named no account, `id` is `null` and `email` is what was typed, lower-cased. |
| `ip` | The client address as Laravel resolves it (`TrustProxies`). |
| `details` | Event-specific, small, never a secret. |

Refusals are warnings (`auth.login_failed`, `auth.lockout`, `authz.denied`, `share.link_rejected`, `mfa.challenge_failed`, `sso.login_failed`); the rest are info.

| Event | When |
|---|---|
| `auth.login`, `auth.login_failed`, `auth.lockout`, `auth.logout` | A sign-in, a refused one (`details.reason`: `credentials` or `inactive`), the sixth attempt inside the throttle, a sign-out. |
| `auth.password_changed` | An account changed its own password. |
| `account.created`, `account.updated`, `account.admin_granted`, `account.admin_revoked`, `account.deactivated`, `account.reactivated`, `account.password_reset`, `account.deleted` | An account changed by an admin, the CSV import or first-run setup. `account.updated` lists the fields (`name`, `email`) and, for an email, the previous one. |
| `admin.settings_updated` | Instance settings, the assistant switches, the default scales or currency; `details.settings` has the values. |
| `admin.log_downloaded` | The security log was downloaded from admin; `details` has `format`, the `from`, `to` and `event` filters, `rows` and `truncated`. Written before the first byte is sent. |
| `authz.denied` | Any response with status 403; `details` has the route name, method and path. |
| `share.link_created`, `share.link_revoked`, `share.link_redeemed`, `share.link_rejected` | A share token generated, revoked, presented and accepted, presented and refused. |
| `template.published`, `template.created`, `template.deleted` | The template library changed (W141): publishing offers a copy of an assessment to every account, and deleting one is final. Target is the template; publishing also names its `source` assessment. |
| `share.public_changed`, `share.collaborator_added`, `share.collaborator_role_changed`, `share.collaborator_removed`, `share.ownership_transferred` | Who can open an assessment changed. |
| `assessment.exported` | One of the five exports answered 200; `details.format` and `details.access` (`account`, `share_link` or `public`). |
| `mfa.*`, `sso.*` | Recorded for W116 and W117, which dispatch `SecurityEventOccurred`; the names are in `SecurityEvent`. |

**Where lines come from.** Most are recorded by `SecurityEventSubscriber` from events the framework and the models already fire (auth events, `User` and `Assessment` model events, the request lifecycle), so every path that changes an account is covered, not only the admin screens. The share and settings call sites write one line each through `SecurityLog`. A package that is not W111 reports an event with `SecurityEventOccurred::dispatch(...)` and writes no line of its own.

**Redaction.** `RedactSensitiveData` is attached to every channel by a `tap`: a context or extra key naming a secret (`password`, `*_token`, `secret`, `session`, `cookie`, `authorization`, `recovery_code(s)`, `otp`, `api_key`, with or without `_confirmation`) has its value replaced with `[redacted]` at any depth, and a `/s/<token>` path is masked in the message and in every string value. The share token is therefore never in a line, and the share endpoints never pass it as context either.

**Stored copy and download (W128).** Each record is also inserted into `security_events` by a handler on the `security` channel, after redaction, so the stored row never holds what stderr would not; a failed insert is reported on stderr and never fails the request. Rows are kept `SECURITY_LOG_RETENTION_DAYS` days (default 90, at least 1) and removed daily by `arguesecure:purge-security-events`. Admin → **Security log** shows the last 50 matching rows and downloads them as CSV or JSON (`GET /admin/security-log/download`), filtered by UTC date range and event or event family. Admin only, `security-log-export` limiter (6/min per account), at most 50,000 rows per download (`X-Rows-Truncated: 1` and `"truncated": true` when cut), streamed. A CSV cell starting with `=`, `+`, `-`, `@`, tab or CR is prefixed with `'`. The stored table is not tamper-evident; the stderr stream, shipped off the host, is the copy to rely on. The application log is not offered from the interface (it exists only on stderr since W112).

The access log of the image's nginx masks the same path (deployment.md, Logs).
- Generic error pages. No stack trace, no framework version, no SQL ever reaches the browser.
- Redact `share_token` and `password` from all log context via a Monolog processor.

---

## 12. Data protection

- The tool holds risk assessments about client systems — commercially sensitive, occasionally covered by NDA. Treat assessment content as confidential by default; that is why `is_public` defaults to `0` and is read-only.
- Deleting a user offers **transfer of owned assessments** before destruction, and states exactly what will be deleted.
- Soft deletes plus a scheduled purge (default 30 days), documented so users know retention is finite. **Built in W113**: deleting an assessment is soft; *My assessments → Deleted* lists what the caller could restore. **Restore** is the `delete` ability (owner, manager, admin) and **Erase permanently** is `forceDelete` (owner and admin, §3.1), both by route (`assessments.restore`, `assessments.erase`), both answering 404 for an assessment that is not deleted, so erasing always takes two deliberate steps. `arguesecure:purge-deleted` runs daily in the `scheduler` container and erases what was deleted more than `TRASH_RETENTION_DAYS` (`arguesecure.trash_retention_days`, at least 1) days ago. Erasing (`App\Domain\Retention\AssessmentEraser`) removes every row that hangs off the assessment — `tests/Feature/Retention/EraseTest.php` walks the schema's foreign keys to prove it — and its notifications, which carry its title outside every foreign key. Database dumps, the Redis calculation cache (six-hour TTL, figures only) and short-lived Redis keys (edit locks, recalculation debounce) are outside its reach, and the README says so.
- Backups (`storage/backups`) are excluded from git and must be excluded from any public bind mount.

---

## 13. W24 acceptance criteria

- Every item in §3.3 has a passing test.
- CSP is active with nonces and there are **zero** console violations on every page, verified in the E2E run.
- A scan of the built assets finds no external host reference.
- `composer audit` and the npm audit gate are clean at high and critical (accepted findings are listed with reasons in `tools/dev/audit-allowlist.json`).
- No secret, key, password or token literal exists anywhere in the working tree — assert with a `gitleaks` (or equivalent) step in CI, not by eye.
- Session cookies carry `HttpOnly`, `Secure` and `SameSite=Lax`, verified by an integration test asserting the `Set-Cookie` header.
- A token-only session receives 403 on every mutating endpoint.
- `APP_DEBUG=false` and the error page leaks nothing, verified by triggering a 500 in a test.
