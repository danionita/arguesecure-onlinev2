# ArgueSecure 2.7.0

Safer sign-in, scales you define yourself, a trash for deleted assessments,
and a round of improvements to editing and reports.

## Sign-in and security

- **Two-factor authentication.** Anyone can protect their account with an
  authenticator app and a set of recovery codes. Administrators can require it
  for everyone.
- **Single sign-on with OpenID Connect.** Connect your organisation's identity
  provider, then let people sign in with it. New accounts are created only for
  the email domains you allow, and administrators can turn password sign-in off
  for everyone else. An **Authentication** page in the admin area holds the
  settings and a button to check the connection. Single sign-on is new: it has
  been tested against a simulated provider, not yet against a real one, so try
  it with yours before relying on it.
- **Log out other devices** now ends exactly the sessions that exist, and
  changing your password signs you out everywhere else.
- **A security event log** records sign-ins, failures and administrative
  changes. Administrators can download it as CSV or JSON, with filters.
- **A security review** closed a set of smaller findings: exports treat cell
  text as text, undo and redo stay within their own assessment, and the
  password breach check can no longer block a password change.

## Assessments and editing

- **A trash for deleted assessments.** Restore them, or erase them for good.
  Deleted assessments are cleared out automatically after a retention period you
  can set.
- **Your own scales.** Administrators define named sets of impact, likelihood
  and strength bands and choose one when creating an assessment.
- **Templates belong to the organisation.** Any administrator can create, edit
  and delete templates. Publishing an assessment as a template now makes a copy,
  so editing one never changes the other.
- **Warnings before you lose work.** Pages with a Save button ask before you
  leave with unsaved changes.
- **Filters in named groups,** and a **Print** menu that asks whether to print
  the chart, the list or the report.
- **Collapsed cards show how many items they hide,** and the hover bar no
  longer sticks open.
- **Undo works as expected** after a redo, and the dashboard shows the real
  totals.
- **Share links** open the assessment straight away for people without an
  account.

## Reports

- **Clearer wording.** The cover reports "Current known risk", and defences are
  "ranked by cost benefit".
- **Choose what goes in.** Every report section has a switch, the long
  appendices start switched off, and model-quality warnings can be left out; the
  report says how many it left out. Defences are listed as implemented, planned
  and shelved.

## Administration

- **Importing a spreadsheet** now reads what Excel writes, including UTF-16 and
  semicolon-separated files.
- **Generated passwords are shown** when you create an account.
- **The sidebar shows the version you are running** and links to these notes.
- **Application logs go to standard error,** so `docker compose logs` shows
  them.

## Upgrading from 2.6.3

1. **Back up your database.** This release adds database changes that run when
   it starts.
2. **Pull the new images and the new compose file** (`docker compose pull`,
   then `docker compose up -d`). The compose file changed: Redis no longer
   discards data under memory pressure.
3. **Everyone signs in again once.** Sessions now live in the database, so
   existing sessions end at the upgrade. If you keep your own `.env`, copy the
   new lines from `.env.example`.
4. **Templates are converted once.** The bundled templates and any template
   someone published from their own assessment become shared templates owned by
   no one. Published templates are copied, and the original assessments go back
   to being ordinary ones with their owners and collaborators unchanged. A
   bundled template that was in the trash stays there until an administrator
   erases it.
5. **Optional:** single sign-on settings (`OIDC_*`, `OIDC_ALLOWED_DOMAINS`) and
   `SECURITY_LOG_RETENTION_DAYS`.

Report links that don't say whether to include the methodology or the warnings
now include neither; before, they included both. The app always sets both, so
only hand-built links and bookmarks change.

## Known limits

- Single sign-on has not yet been tried against a real identity provider.
- The browser's Back and Forward buttons and signing out do not warn about
  unsaved changes.
