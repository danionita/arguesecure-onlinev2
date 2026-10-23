# Security Specification

> How ArgueSecure authenticates, authorizes, isolates and logs. Written for operators, contributors and auditors.
>
> Related: [schema.md §5](schema.md#5-authorization), [deployment.md](deployment.md).

---

## 1. Threat model and design rules

ArgueSecure holds risk assessments about client systems: commercially sensitive and sometimes covered by NDA. Content is confidential by default. The assets worth protecting are the assessments themselves, the accounts that can open them, and the secrets (`APP_KEY`, the OpenID Connect client secret, two-factor secrets) that guard both.

The adversaries the design assumes:

- **A signed-in user acting outside their role**: reading or editing an assessment they were not given, escalating from `viewer` to `editor`, or enumerating other users.
- **An unauthenticated visitor**: guessing share tokens, brute-forcing logins, reaching the websocket server.
- **Content authors**: any text a user or a model writes (titles, comments, report text, imported CSV) is untrusted when it is rendered or exported.
- **A network observer or a hostile origin**: handled by TLS at the reverse proxy, strict cookies and a strict Content Security Policy.

The rules that follow from this, each detailed in the section named:

1. **One policy decides.** Every route that touches an assessment goes through `AssessmentPolicy`. Nothing grants access from a node's own attributes (§3.1).
2. **`is_public` and share links grant read only** (§3.1, §3.2).
3. **No public registration, no seeded credential.** Accounts are created by an admin, by first-run setup, or by single sign-on for the operator's own email domains (§4).
4. **No unauthenticated websocket channel and no global channel.** Every channel is authorized server-side against the policy (§8).
5. **No dynamic class or column from request input; no `$request->all()`** (§7).
6. **Nothing loads from a third party.** No CDN, font, script or image is fetched from another origin (§6).
7. **Secrets live in the environment or an encrypted column**, never in the repository, a log line, a response or an Inertia prop (§2, §11).
8. **Nothing a model generates reaches the graph, a report or another user without a person seeing it first.** Assistant replies render as plain text; there is no HTML renderer for generated output ([ASSISTANT.md](../ASSISTANT.md)).
9. **Every security-relevant action leaves a record** (§11.1).

Out of scope: protection against a malicious operator or database administrator, denial of service beyond the rate limits in §9, and the confidentiality of data in backups or database dumps taken outside the application.

---

## 2. Secrets and keys

- **`APP_KEY`** is generated on first boot by `docker/entrypoint.sh` and stored in the instance's data volume; no key ships in the repository or the image. It encrypts the session payload, the OpenID Connect client secret and the two-factor secrets, and keys the hash of two-factor recovery codes. Rotating it signs everyone out, makes the stored client secret unreadable (an admin enters it again) and invalidates enrolled two-factor secrets and recovery codes.
- **Reverb credentials** (`REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`) are generated the same way. The app key is a public identifier that appears in the websocket URL; the secret is never sent to a browser.
- **There is no default account.** The first administrator is created on the first-run setup screen (§4). No seeder, factory or test fixture writes a credential.
- **`env()` is read only in `config/` files**, so a cached configuration behaves like an uncached one.

### 2.1 Repository hygiene

- `.gitignore` excludes `.env` and every `.env.*` variant except the value-free `.env.example`, plus `.env-deploy`, `*.local` and `/storage/backups`.
- `.gitleaks.toml` extends gitleaks' default rules; CI runs gitleaks over the tree and history it checks out (§10).
- A source-audit test (`tests/Feature/Security/SourceAuditTest.php`) fails if the working tree holds a secret, key or credential literal, tracks an env file, calls `$request->all()`, calls `env()` outside `config/`, instantiates a class or selects a column from request input, or references an external host from a template, stylesheet or bundle entry.

---

## 3. Authorization

### 3.1 One policy, one source of truth

`AssessmentPolicy` decides everything. `NodePolicy` and `CommentPolicy` load the owning assessment and delegate. **No policy grants access based on a node's own attributes**: a per-node grant is how any user could edit or delete another user's work.

Abilities and the roles that satisfy them:

| Ability | admin | owner | manager | editor | commenter | viewer | public | share token |
|---|---|---|---|---|---|---|---|---|
| `view` | yes | yes | yes | yes | yes | yes | yes | yes |
| `comment` | yes | yes | yes | yes | yes | no | no | no |
| `update` (nodes, links, values) | yes | yes | yes | yes | no | no | no | no |
| `manage` (share, rename, scales, snapshot) | yes | yes | yes | no | no | no | no | no |
| `delete` (soft) | yes | yes | yes | no | no | no | no | no |
| `transferOwnership`, `forceDelete` (hard delete) | yes | yes | no | no | no | no | no | no |

Resolution order: `is_admin`, then owner, then `assessment_user.role`, then `is_public` (view only), then a valid share token held in the **session** (view only).

`is_public` grants **read only**. A public assessment can be read by any signed-in account (and by a visitor following its link); nobody gains write access from it. Write access comes only from an editor, manager or owner relationship. The instance setting `public_assessments_allowed` can disable the public flag altogether.

### 3.1a Templates

A template is reference material for the whole instance: every signed-in account can list it, start an assessment from it, and copy single nodes out of it. What the library contains is therefore an instance-wide decision, and only admins make it.

| Ability | Who | What it allows |
|---|---|---|
| `saveAsTemplate` / `createTemplate` | admin only | publish an assessment as a template (the Share dialog's template panel): copies it into a new, ownerless template and writes nothing to the original |
| `useAsTemplate` | any signed-in account, on an assessment with `is_template = 1` | list it (`templates.index`, `templates.options`), clone it (`templates.use`), read one type of its nodes (`templates.nodes`) |

- **A template has no owner and only admins reach it.** `AssessmentPolicy::before` makes every ability on a template admin-only, whatever `is_public` or a stray collaborator row says, except `useAsTemplate`; `transferOwnership` is false for everyone, and `forceDelete` is admin-only. Every sharing route (`share.*`) answers **403** for a template, an admin included (`RejectTemplate`), so no collaborator, public flag, share link or ownership change can attach to one. `owner_user_id` is `NULL` exactly when `is_template = 1`, enforced by a database CHECK (`assessments_template_ownerless`). Admins create and delete templates on the Templates page; deletion is permanent.
- **Publishing is an admin's decision.** It is narrower than `manage` because every other `manage` action decides who inside an assessment can do what, while publishing offers the assessment's contents to every account, which cannot be withdrawn from whoever has already copied it.
- **Publishing copies and writes nothing to the original.** A template never has to be readable by everyone to be usable by everyone, and `public_assessments_allowed` does not stand between an admin and the library. There is no unpublish: a published copy is removed by deleting it.
- **`useAsTemplate` reads, it never writes.** Being a template adds no write ability to anyone. Every route behind it answers **404** for an assessment that is not a template, so none can be used to read the titles of an arbitrary assessment by uuid.
- **`templates.nodes` returns titles, descriptions, the transfer flag and parent titles, never values.** A template's impacts and likelihoods describe its example system; copying them into someone else's assessment would present them as that assessment's own judgement (`app/Domain/Templates/TemplateNodes.php`). The copy itself goes through the ordinary `nodes.store` write, under the *target* assessment's `update`.
- **Cloning is a copy, never a link**, and the copy is not a template: `is_template` and `is_public` are both false on it, so starting from a template never publishes the private work that follows.

`tests/Feature/Templates/TemplateSecurityTest.php` asserts each write verb on a template **separately** (patch a node, delete a node, add a node, share, generate a share link, delete the assessment, tag, comment, change settings), because a single "a stranger cannot edit a template" test passes while one route stays open. The picker's two routes are covered in `tests/Feature/Templates/TemplateNodesTest.php`.

### 3.1b Assignees

An assignment is a label, not a grant. It confers **no** ability: the assignee's collaborator role decides everything.

- **Assigning and unassigning are `update` on the assessment.**
- **There is no node-scoped ability.** `NodePolicy` delegates upward without reading a node's own attributes, and `node_assignees` has no role column.
- **Adding a new person is `manage`, whatever route it arrives through.** The assign endpoint has one path that shares the assessment at `viewer` with someone who is not on it, taken only when the caller sets `share_as_viewer` explicitly in the same request. It requires `manage`, not the `update` that assigning needs, and refuses an inactive account. Otherwise any editor could hand read access to a whole assessment to anyone with an account through a route whose name says nothing about sharing.
- **Never as a side effect.** The UI never sends that flag; sharing happens in the Share dialog, where the roles are explained.
- **Names, never addresses.** The assignee list is readable by everyone who can view the assessment, which for a published template is every account on the instance.
- **Reports name nobody by default**, and the one-click download does not turn it on. A report is a document that leaves the room; who was responsible for which line is internal information the exporter chooses to include.

### 3.1c Mentions and notifications

**`@name` matches against the assessment's owner and collaborators only, never against `users`** (`App\Domain\Comments\MentionParser`). Matching globally would turn every comment box into an account-existence oracle: type `@dana`, observe whether anything happens, and learn that a Dana has an account on this instance, from inside an assessment she has nothing to do with. The query therefore never touches the users table unfiltered. A non-collaborator named in a comment has **no notification row at all**.

An ambiguous first name resolves to nobody. Guessing between two people sends the message to the wrong one; notifying both sends half of them something that was not for them. Both failures are invisible to the author, so neither is taken. Full names (with or without spaces) always match.

**Deactivated accounts are not candidates.** They cannot read the assessment, so a notification would sit unread while the author believes they reached someone.

**The feed has no policy and needs none.** There is no assessment in the route: every query is scoped to `$request->user()->notifications()`, so the relation answers "whose feed is this?" before the database does. Another user's notification id finds no row and **404s**; a 403 would confirm the id exists and belongs to somebody.

### 3.2 The share token

- 32 random bytes, base64url (43 characters), stored in `assessments.share_token` with `share_token_created_at`. Generation retries on the (astronomically unlikely) collision.
- Presenting the token at `/s/{token}` stores a **session flag scoped to that assessment id** (the token and its creation time) and redirects to a clean URL, so the token does not sit in browser history, referrers or server logs. A malformed or unknown token answers 404 and is logged as `share.link_rejected`.
- Grants `view` only. Every mutating endpoint rejects a token-only session.
- Revocable and regenerable by the owner, a manager or an admin (`manage`). The session flag carries the token's creation time and is compared on each request, so revoking or regenerating invalidates every existing session at once. The policy compares the flag's token with `hash_equals`.
- A token-only reader has no account, so the broadcasting authorization endpoint (`auth` + `active`) is unreachable for them: they receive no presence or broadcast events (§8).
- The nginx access log masks the token segment of `/s/...` requests (§11.1).

### 3.3 Test coverage

The authorization surface is held by tests that enumerate it rather than by examples:

- `tests/Feature/Security/RouteCoverageTest.php` enumerates `Route::getRoutes()` and asserts that every non-public route has authentication middleware, that every assessment-scoped route runs `AssessmentPolicy` for a stranger and denies a guest, that node uuids in request bodies resolve through the route's assessment on every node-scoped write route, that a deactivated account is kept out of every authenticated route, that the broadcasting auth endpoint requires `auth` and `active`, and that no self-service registration or password-reset route exists.
- `tests/Feature/Policies/AssessmentPolicyTest.php` covers the role table in §3.1; `tests/Feature/Sharing/PublicAndTokenAccessTest.php` and `ShareTokenServiceTest.php` cover `is_public` and share-token access, including that a token-only session cannot mutate.
- Identity columns (`assessment_id`, `type`, `uuid`, `created_by`, `owner_user_id`, `calc_version`) are not in any `$fillable`; they are assigned as properties by services (§7).

---

## 4. Authentication

- **No public registration.** There is no registration form or route. Login is throttled to 5 failed attempts per minute per address and per email-and-address pair, with a generic failure message that does not reveal whether the account exists. The one way an account appears without an admin is single sign-on (below), and only for the operator's own email domains.
- **Single sign-on with OpenID Connect.** Authorization-code flow with PKCE (S256), `response_mode=query`, one provider per instance, configured on the admin Authentication tab or in the environment (`OIDC_ISSUER`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`, `OIDC_BUTTON_LABEL`, `OIDC_ALLOWED_DOMAINS`, `OIDC_TRUST_EMAIL`). The tab writes the same six fields to `instance_settings`; a field set in the environment wins over a stored one, field by field. The client secret is stored with the `encrypted` cast (so `APP_KEY` guards it, as it does the two-factor secrets), is in the model's `$hidden`, and is write-only in the UI: no page, response or log line carries it, and a save is logged as the names of the fields changed, never values. Rotating `APP_KEY` makes it unreadable and the admin enters it again. Changing the provider is an admin action and is recorded in the security log. *Check provider* fetches the saved issuer's discovery document (admin only, 6/min per user, no credentials sent). With no issuer configured there is no sign-in link, and `/auth/oidc/redirect` and `/auth/oidc/callback` answer 404. Both are GETs (CSP `form-action 'self'` would block a form redirecting to the provider; the callback is a top-level navigation that the `same_site=lax` cookie accompanies). `state`, `nonce` and the verifier live in the session and are good for one callback and ten minutes. The ID token is checked for signature (algorithm from an allow-list of RS256/384/512, PS256/384/512 and ES256/384, never `none` or HMAC; keys from the discovery document's `jwks_uri`, cached for an hour, refetched once on failure), `iss`, `aud`, `azp` (when there are several audiences), `exp`, `iat`, `nonce` and a non-empty `sub`, with 60 seconds of clock leeway. Discovery must be https (or localhost) and its `issuer` must equal the configured one. Who signs in: the account already linked to `(iss, sub)`; else the active account with the token's email, linked once, if the email is verified (or `OIDC_TRUST_EMAIL`) and the account has no identity from this issuer; else, if the email's domain is exactly in `OIDC_ALLOWED_DOMAINS`, a new non-admin account with a random password nobody is shown. Anything else, and every failure, shows the same message as a wrong password. After SSO the session is marked, and the instance's *require two-factor* rule does not apply (the provider's own factor is the organisation's to enforce). *Sign in with SSO only* (off by default; effective only with a provider configured) refuses password sign-in for non-admins; admins keep it as the way in when the provider is down. In that mode a non-admin's email change needs a provider sign-in (`prompt=login`, `max_age=0`) within the last five minutes instead of the current password. An admin can unlink an identity, which ends the account's sessions. Not implemented: SAML, SCIM, group mapping, single logout, several providers.
- **First-run setup.** When `users` is empty the app serves a one-time setup screen creating the first admin. The submission re-checks, inside a transaction holding a lock on `users`, that the table is still empty. No credential is seeded in any environment, including tests and fixtures.
- **Passwords** are hashed with Laravel's hasher (`users.password varchar(255)`, so the algorithm can change). Minimum 12 characters, checked against Laravel's compromised-password rule (`Password::min(12)->uncompromised()`), which uses the k-anonymity HIBP range API. If outbound network access is unavailable the check degrades to length only and never blocks login. The lookup is limited to 3 seconds: a slow or silent service means the password is accepted on length alone, and setup or a password change does not wait.
- **Changing a password requires the current one.** Changing an email requires the current password (or, in SSO-only mode for a non-admin, a recent provider sign-in). Both end the account's other sessions.
- **Two-factor authentication.** An account can add a TOTP code (RFC 6238, 30-second steps, one step of drift either side) from an authenticator app, with ten single-use recovery codes. With it on, a correct password does **not** sign in: it parks the account id and the *remember me* choice in the session and shows the challenge, which expires after five minutes; the session id changes when the code is accepted, and the remember-me cookie is issued only then. Failed codes are limited to 5/min per pending account and per IP (§9). A code is accepted for a time step greater than the last one accepted, with the step claimed by a conditional `UPDATE`, so a code works once even under concurrent requests. The secret and the recovery codes are encrypted at rest with the app key (so `APP_KEY` guards them), recovery codes are kept only as keyed hashes (HMAC-SHA-256 under the app key), and all four two-factor columns are in `User::$hidden`, so no Inertia prop, JSON response or log context carries them. An enrolment stores nothing until a code from the new secret is confirmed. Starting it, new recovery codes and turning it off take the current password (turning off also a code), and turning it off or an admin removing it ends the account's other sessions. Two-factor is per account; the instance setting *Require two-factor authentication for password sign-in* (default **off**) sends an account without it to enrolment after its password. No SMTP, SMS or outside service is involved; passkeys are not implemented.
- **Admin password reset** sets a temporary password shown once on screen and forces a change on next login. So does creating a user without typing a password, and every account made by CSV import. The password reaches the page as Inertia flash data, which is delivered with one response and kept out of browser history, so pressing Back does not show it again. No email is sent; there is no SMTP dependency.
- **Session invalidation** (deleting the account's rows from the `sessions` table and replacing its remember-me token) happens on an own password change, an email change, an admin password reset, deactivation, deletion, turning two-factor off or removing it, and unlinking an SSO identity.
- **`is_active` is enforced on every request, not only at login.** Session invalidation alone is reactive: it depends on the deactivating code path reaching every one of that user's sessions. The `active` middleware on the authenticated route groups (and on the broadcasting auth route) logs out and rejects with 403 any user whose `is_active` is false, so a missed session cannot keep a deactivated account working. This belongs in authentication, not in `AssessmentPolicy`: account status is not an assessment relationship, and putting it in the policy would leave every non-assessment route uncovered.

---

## 5. Sessions and cookies

Every cookie attribute is stated explicitly rather than inherited from a default. Effective values in `config/session.php`:

```php
'driver'         => 'database', // fixed, not read from SESSION_DRIVER
'lifetime'       => 480,        // minutes; the admin setting session_lifetime_minutes (default 480)
'expire_on_close'=> false,
'encrypt'        => true,
'http_only'      => true,
'secure'         => true,       // SESSION_SECURE_COOKIE, true in any non-local environment
'same_site'      => 'lax',
'partitioned'    => false,
```

The lifetime is the instance setting *session lifetime* (default 480 minutes), applied to the configuration at the start of every request by `ApplyInstanceSessionLifetime`; `SESSION_LIFETIME` is only the fallback before that. `SESSION_SECURE_COOKIE=true` in every deployed environment. Behind a reverse proxy the `Secure` attribute and HSTS depend on `TrustProxies` seeing `X-Forwarded-Proto`; see [deployment.md §4](deployment.md#4-reverse-proxy).

The session id is regenerated on login, after a completed two-factor challenge and after an SSO sign-in.

**Sessions live in the database's `sessions` table.** Every invalidation in §4, and the profile page's list of signed-in devices with its revoke button, works by deleting that user's rows from it (`SessionRegistry`); under any other driver the table would stay empty and all of them would silently do nothing. `config/session.php` therefore does not read `SESSION_DRIVER`, and an install that still sets it is ignored. `tests/Feature/Auth/SessionRevocationTest.php` signs in on two devices and checks each revocation path signs the other one out.

---

## 6. Content Security Policy

Strict, with per-request nonces. No `unsafe-inline`, no `unsafe-eval`. Applied by the `SecurityHeaders` middleware (global, so redirects, `/up` and error pages carry it), with the directives in `config/security.php`.

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

The nonce is 16 random bytes, generated once per request before the response is rendered and handed to the Vite helper, so every `<script>` and stylesheet tag the framework emits carries it; the inline theme script and the route-table script (`@routes`) are given the same nonce. The websocket source is derived from the request's own origin (scheme, host and port) and from the host of `APP_URL`, never from a header the proxy does not vouch for; `ws://` replaces `wss://` on a plain-HTTP local run. `CSP_REPORT_ONLY=true` switches the header to `Content-Security-Policy-Report-Only` for an operator who needs to collect violations before tightening a custom directive; the default is enforcing.

**Everything is self-hosted.** No CDN, no external font, no external icon set, no remote image. A source-audit test scans templates, stylesheets and bundle entries for external hosts, and another renders a page and asserts it makes no off-origin reference.

Additional headers on every response:

| Header | Value |
|---|---|
| `Strict-Transport-Security` | `max-age=63072000; includeSubDomains` (two years), sent only on a request Laravel considers secure; `HSTS_ENABLED=false` turns it off. No `preload`. |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `X-Frame-Options` | `DENY` (duplicating `frame-ancestors 'none'` for scanners and old browsers) |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), usb=(), payment=()` |

---

## 7. Input handling

- **Validation lives in Form Requests** (or, for a few small routes, a `validate()` call in the route or controller), and every write runs a policy check: a Form Request's `authorize()` calls the policy rather than returning `auth()->check()`.
- **Never `$request->all()`.** Use `$request->validated()` or an explicit `only([...])`.
- **`$fillable` on every model.** `assessment_id`, `type`, `uuid`, `created_by`, `owner_user_id` and `calc_version` are never fillable; services assign them as properties. A reorder, move or link request resolves every node uuid through the route's assessment, so a uuid belonging to another assessment is a 404 or 422.
- **No dynamic class instantiation from request input.** Node type is an enum resolved through a fixed map, never through `class_exists()` or reflection.
- **No dynamic column names from request input.** Nodes are written through typed endpoints with named, validated fields, never a generic field/value endpoint.
- **Rendered text.** Comment bodies and assistant output render as text (`{{ }}`, never `v-html`), so Vue escapes them. The only Markdown renderer is for the bundled help pages: it is configured with raw HTML stripped and unsafe links dropped (`html_input = strip`, `allow_unsafe_links = false`), and its output is the only content set with `v-html` besides the bundled icon set.
- **Exports treat text as text.** The Word exporter enables output escaping; the Excel exporter writes text cells as explicit strings, so a leading `=` is never a formula; the CSV exporter prefixes a text cell that starts with `=`, `+`, `-`, `@`, tab or carriage return with `'`. Numeric cells are untouched.
- **Uploads.** The admin user import is the only upload. It accepts a CSV file (at most 1 MB) or pasted text of the same size, parses it from memory, never stores the file on disk, never accepts a filesystem path from input, and rejects a row whose field count differs from the header's explicitly, with its line number, rather than guessing.
- **External reference identifiers** are regex-validated ([schema.md §3.12](schema.md#312-node_references-cve-and-friends)) and the URL is **derived from the identifier**, never taken from input, except for the `url` kind, which must match `https?://` and renders with `rel="noopener nofollow"`.

---

## 8. Websocket authorization

- **Reverb** serves one presence channel per assessment, named `assessment.{uuid}` (clients subscribe as `presence-assessment.{uuid}`), authorized server-side in `routes/channels.php`: the account must be authenticated and active (the broadcasting auth route runs `web`, `auth`, `active`) and must pass `AssessmentPolicy::view` for that assessment. A user who is not authorized, or names an assessment that does not exist, is refused identically. A private channel `App.Models.User.{id}` is open only to that user.
- **No global channel.** Nothing broadcasts to an "everyone" channel.
- Broadcast payloads carry only what the recipient is already authorized to read; a full model is never broadcast for the client to filter.
- A visitor who has no account (a share-link reader) cannot reach the broadcasting auth endpoint and so receives no broadcasts and appears in no presence list.
- The Reverb port is **not** published to the host; it is proxied under the app's own hostname at `/app`, so it inherits TLS and the same origin. See [deployment.md](deployment.md).

---

## 9. Rate limiting

| Endpoint group | Limit |
|---|---|
| Login (failed attempts) | 5/min per IP **and** 5/min per email-and-IP pair |
| First-run setup | 5/min per IP |
| SSO redirect and callback | 30/min per IP (a sign-in is two requests) |
| SSO "Check provider" (admin) | 6/min per user |
| Two-factor challenge (failed codes) | 5/min per pending account **and** per IP; plus a coarse 30/min per IP on the endpoint |
| Password change / email change / password confirmation | 5/min per user |
| Node and link mutations | 120/min per user |
| Report generation (the five exports) | 10/min per user |
| Report views (the report page, re-requested by every option toggle) | 60/min per user; 10/min per IP without an account |
| Search (including the share dialog's user search) | 60/min per user |
| Security log download (admin) | 6/min per user |
| Share-token resolution `/s/{token}` | 10/min per IP: this is the brute-force surface for tokens |
| Assistant generation | 6/min per user, **and one at a time** |
| Everything else authenticated | 300/min per user |

Each limiter is a named limiter registered in `RateLimitServiceProvider`, so each has its own bucket. A limiter keyed by user falls back to the client address for a caller without a session. Rate-limit state lives in the application cache (Redis in the supplied configuration), so it holds across containers.

**The assistant's second half is not a rate.** A generation occupies physical CPU cores for tens of seconds (28 s for a suggestion, up to 249 s for a summary, as measured) on the machine that is simultaneously serving every other user. A per-minute limit cannot see concurrency: six requests inside one second are six requests, comfortably under any ceiling, and six generations competing for the same cores make the instance unusable for everybody. `App\Domain\Assistant\Generation\SingleFlight` holds a per-user lock so a second generation is refused rather than queued; queued would hold a php-fpm worker for minutes, which is the same exhaustion arriving through the front door.

The assistant's generating routes sit behind `auth`, while the help pages they read from do not. That asymmetry is deliberate: the pages are public product documentation costing a file read, and an anonymous endpoint that spends seconds of CPU per call is a free denial-of-service primitive against the whole instance.

---

## 10. Dependencies and supply chain

- **Free and open-source only.** No paid libraries or services.
- `composer.lock` and `package-lock.json` are both committed, so builds are reproducible.
- CI runs `composer audit --locked` and `tools/dev/audit-gate.mjs` over the whole npm lockfile (not `--omit=dev`, which would audit nothing here); a high or critical advisory fails the build unless `tools/dev/audit-allowlist.json` names it with a reason. CI also runs gitleaks.
- GitHub Actions are pinned to commit SHAs, not tags.
- The assistant's `llama.cpp` image is pinned to a digest; the application image's base images are pinned by tag and refreshed when the image is rebuilt.
- **Not used, and not to be introduced**: `dompdf`, `laravelcollective/html`, `lord/laroute`, `laravel-elixir`, `swiftmailer`, `socket.io`, jQuery, Bootstrap, memcached.

---

## 11. Logging and error handling

- `APP_DEBUG=false` in every deployed environment. No debug toolbar is a dependency.
- Generic error pages. No stack trace, framework version or SQL reaches the browser, and error responses carry the same security headers as any other (§6).
- The security log (§11.1) records authentication events, authorization failures, admin actions, share-link creation and revocation, and role changes, with actor, target, IP and timestamp.
- **Never logged**: passwords, tokens, session ids, or the request bodies of auth endpoints.

### 11.1 The security event log

Lines go to the `security` channel (`config/logging.php`): stderr, one JSON object per line, level `info` or above whatever `LOG_LEVEL` is, so `docker compose logs` and a log shipper carry it and a SIEM parses it without a grammar. `message` is the event name; `datetime` is the timestamp; `context` always has the same five keys:

| Key | Content |
|---|---|
| `event` | The name from the table below. Names are not renamed once released; new ones are added. |
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
| `admin.settings_updated` | Instance settings, the assistant switches, the default scales or currency; `details.settings` has the values (never a secret). |
| `admin.log_downloaded` | The security log was downloaded from admin; `details` has `format`, the `from`, `to` and `event` filters, `rows` and `truncated`. Written before the first byte is sent. |
| `authz.denied` | Any response with status 403; `details` has the route name, method and path. |
| `share.link_created`, `share.link_revoked`, `share.link_redeemed`, `share.link_rejected` | A share token generated, revoked, presented and accepted, presented and refused. |
| `template.published`, `template.created`, `template.deleted` | The template library changed. Target is the template; publishing also names its `source` assessment. |
| `share.public_changed`, `share.collaborator_added`, `share.collaborator_role_changed`, `share.collaborator_removed`, `share.ownership_transferred` | Who can open an assessment changed. |
| `assessment.exported` | One of the five exports answered 200; `details.format` and `details.access` (`account`, `share_link` or `public`). |
| `mfa.enabled`, `mfa.disabled`, `mfa.removed_by_admin`, `mfa.recovery_code_used`, `mfa.challenge_failed` | Two-factor enrolment, removal and challenge outcomes. |
| `sso.login`, `sso.login_failed`, `sso.identity_linked`, `sso.identity_unlinked` | OpenID Connect sign-ins and identity changes. |

**Where lines come from.** Most are recorded by `SecurityEventSubscriber` from events the framework and the models already fire (auth events, `User` and `Assessment` model events, the request lifecycle), so every path that changes an account is covered, not only the admin screens. The share and settings call sites write one line each through `SecurityLog`. Other code reports an event with `SecurityEventOccurred::dispatch(...)` and writes no line of its own.

**Redaction.** `RedactSensitiveData` is attached to every channel by a `tap`: a context or extra key naming a secret (`password`, `passwd`, `pass`, `*token`, `secret`, `session`, `session_id`, `cookie`, `authorization`, `recovery_code(s)`, `otp`, `code`, `code_verifier`, `key`, `private_key`, `credential(s)`, `bearer`, `jwt`, with or without `_confirmation`) has its value replaced with `[redacted]` at any depth, and a `/s/<token>` path is masked in the message and in every string value. The share token is therefore never in a line, and the share endpoints never pass it as context either. The access log of the image's nginx masks the same path (see [deployment.md](deployment.md), Logs).

**Stored copy and download.** Each record is also inserted into `security_events` by a handler on the `security` channel, after redaction, so the stored row never holds what stderr would not; a failed insert is reported on stderr and never fails the request. Rows are kept `SECURITY_LOG_RETENTION_DAYS` days (default 90, at least 1) and removed daily by `arguesecure:purge-security-events`. Admin → **Security log** shows the last 50 matching rows and downloads them as CSV or JSON (`GET /admin/security-log/download`), filtered by UTC date range and event or event family. Admin only, `security-log-export` limiter (6/min per account), at most 50,000 rows per download (`X-Rows-Truncated: 1` and `"truncated": true` when cut), streamed. A CSV cell starting with `=`, `+`, `-`, `@`, tab or CR is prefixed with `'`. The stored table is not tamper-evident; the stderr stream, shipped off the host, is the copy to rely on. The application log goes to stderr only and is not offered from the interface.

---

## 12. Data protection

- Assessment content is confidential by default: `is_public` defaults to `0` and is read-only when set (§3.1).
- Deleting a user offers **transfer of owned assessments** before destruction, and states exactly what will be deleted; without a transfer target, that user's deleted assessments are erased with them.
- **Deleting an assessment is soft, and retention is finite.** *My assessments → Deleted* lists what the caller could restore. **Restore** is the `delete` ability (owner, manager, admin) and **Erase permanently** is `forceDelete` (owner and admin, §3.1), both by route (`assessments.restore`, `assessments.erase`), both answering 404 for an assessment that is not deleted, so erasing always takes two deliberate steps. `arguesecure:purge-deleted` runs daily in the `scheduler` container and erases what was deleted more than `TRASH_RETENTION_DAYS` (`arguesecure.trash_retention_days`, default 30, at least 1) days ago. Erasing (`App\Domain\Retention\AssessmentEraser`) removes every row that hangs off the assessment (`tests/Feature/Retention/EraseTest.php` walks the schema's foreign keys to prove it) and its notifications, which carry its title outside every foreign key. Database dumps, the Redis calculation cache (six-hour TTL, figures only) and short-lived Redis keys (edit locks, recalculation debounce) are outside its reach, and the README says so.
- `/storage/backups` is excluded from git and must be excluded from any publicly served mount.

---

## 13. Verification

The controls above are checked by the test suite and CI rather than by inspection:

- The route-coverage and policy tests in §3.3 pass.
- The CSP is active with nonces, with zero console violations on every page in the end-to-end run, and `SecurityHeadersTest` asserts the header set on page, redirect and error responses and the enforce and report-only modes.
- A scan of templates, stylesheets and bundle entries finds no external host reference.
- `composer audit` and the npm audit gate are clean at high and critical; accepted findings are listed with reasons in `tools/dev/audit-allowlist.json`.
- No secret, key, password or token literal exists in the working tree, asserted by the source-audit test and by gitleaks in CI.
- Session cookies carry `HttpOnly`, `Secure` and `SameSite=Lax`, asserted on the raw `Set-Cookie` header by `SessionCookieTest`.
- A token-only session receives 403 on every mutating endpoint.
- `APP_DEBUG=false` and the error page leaks nothing.
