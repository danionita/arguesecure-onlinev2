# ArgueSecure 2.8.1

One registration switch for email and single sign-on, clearer report settings and Strength tab, and a round of fixes to the administration pages.

## Sign-in and security

- **One registration switch.** *Allow new registrations* now covers both ways in. With it on, a first single sign-on login creates an ordinary, non-administrator account when the provider marks the email as verified and the address is on the single sign-on domain list (empty means every domain) and, if you set one, on the email-registration list. Nobody is sent mail for this: the provider has already proved the address. With it off, single sign-on links to an existing account with the same verified email but creates none. The switch can be turned on with email set up **or** single sign-on set up.
- **Google sign-in** has now been tested against a live Google account. Only Keycloak and Google have been tested; other providers, Microsoft Entra ID included, are expected to work but are unverified.

- **Dependency security update.** Laravel is updated to 12.72.0, with its minor dependencies, to fix a published advisory in its concurrency tasks.

## Editing

- **Strength tab.** A defence opened from an attack shows that attack's strength first, in a highlighted block; the defence's other attacks sit in a collapsed list below. When there is no known parent (a direct link, or a single attack) nothing is split.
- **Suggest buttons** look different from Add buttons: a sparkles icon in the accent colour.
- **The assessment list no longer shows a Settings icon** on each row. Settings open from the action bar inside an assessment.

## Reports

- **Report settings** are four panels: Sections, Appendices (references, methodology, warnings), Details (show comments, show assignees) and the existing Defences. Each switch has its own row with a short description, and flipping one no longer closes the panel.

## Administration

- **Notifications on by default for new installs.** The three notification kinds start enabled on a new installation. An instance that already saved its choices keeps them: switch the kinds on under **Admin → Email → Enabled notifications**. Titles stay off, because they pass through the mail server.
- **Admin → Email** wording says what it means for the whole instance (*Enabled notifications*, *Notification templates*), and its Encryption select and template field are readable on the dark theme.
- **Administrators' help** has a page for every administration tab.
- **The deployment notice** no longer adds a second scroll bar: it sits beside the sidebar instead of above it.

## Upgrading from 2.8.0

1. **Back up your database.** One small database change runs at start.
2. **Pull the new images** (`docker compose pull`, then `docker compose up -d`). No `.env` change is required.
3. **Single sign-on and registration.** If you set `OIDC_ALLOWED_DOMAINS` and left *Allow new registrations* off, 2.8.0 created accounts for those domains and 2.8.1 no longer does. Turn registration on to keep that behaviour. An empty domain list now means every domain once registration is on, so list your domains if you want to limit it.
4. **Notification email.** An existing instance keeps its saved kinds. Switch them on under Admin → Email if you want them.

## Known limits

- Single sign-on. Only Keycloak and Google have been tested. Other providers are expected to work but are unverified.
- Email has been verified with a STARTTLS server; try your own server with **Send test email** before relying on it.
- Readers of a public assessment or a read-only link do not get live updates; they reload to see changes.
