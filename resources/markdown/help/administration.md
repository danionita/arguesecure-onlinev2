# Administration

**Admin**, in the sidebar, is shown to administrators only. Administrators
can open, edit and manage every assessment on the instance.

## Users

There is no public sign-up: every account is created here.

- **Create** an account, or **Bulk import** several from a CSV file. The import previews every row it will create or reject, with the reason, before anything is written. The file needs a header
  row with `name` and `email` columns (`admin` is optional). Files saved from
  Excel as "CSV UTF-8", "CSV" or "Unicode Text" are read, with commas, semicolons or tabs between columns.
- New passwords are shown **once**, on a sheet you can print for a workshop. Leave the password blank when you create a user and one is generated and shown once; whoever receives a generated password, or one from a bulk import, must change it at their first sign-in.
  They are never stored in plain text or emailed.
- Edit, deactivate, reset a password, make someone an administrator, or
  delete an account. Deleting a user who owns assessments offers to transfer
  them first.
- An account signed in through the identity provider shows its link;
  **Unlink** signs it out everywhere, and its next sign-in links again by
  verified email.
- An account's page shows whether it has two-factor authentication. If
  someone loses their phone, **Remove second factor** signs them out
  everywhere else; they sign in with their password and set it up again.

## Instance settings

Whether assessments may be made public, and how long a session lasts.

## Authentication

Whether **two-factor authentication is required** and whether **SSO is the
only way in** for non-administrators. For single sign-on, register the tab's redirect URI at
your OpenID Connect provider and enter its issuer, client ID and secret; the
secret is stored encrypted and never shown. _Set by the server's environment_
means an `OIDC_*` variable wins. See [Signing in](/help/signing-in).

## Defaults

The default **currency** and the **scales** new assessments start from. **Standard**
and **Small organisation** are built in; **Create scale** copies one. Each has its own **Save**.

## AI assistant

Switches the optional local assistant on or off, and each of its three
features — help answers, suggestions and summary drafts — separately. If
the server has no model installed, the tab says "AI service not installed";
installing one is done on the server.

The tab shows which model is running: **small** (about 6 GiB of memory) or
**large** (about 28 GiB, better suggestions and summaries). Switching is done
on the server.

## Security log

Who signed in or was refused, which accounts and settings changed, who can
open which assessment, and who exported one: the events this instance
recorded in the last 90 days (the person running the server may set
another period). The tab shows the latest 50 matching events and downloads
all matching ones as **CSV** or **JSON**, filtered by date range (UTC days)
and event. A download holds at most 50,000 events and is itself recorded.

Rows here can be altered by anyone with access to the database. For a record
that cannot, send the server's standard error to a log collector. Application
errors are not shown here; read them on the server with
`docker compose logs app`.

## Templates

Administrators manage templates. See [Templates](/help/templates).

The sidebar shows the running version, with a link to its release notes.

## Unsaved changes

Settings pages, your profile and the scale editors ask **Stay** or **Leave**
before a link takes you away from unsaved edits. The Back button and signing
out are not guarded.
