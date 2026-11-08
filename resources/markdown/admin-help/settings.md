# Settings and sign-in

## Instance settings

Whether assessments may be made public, and how long a session lasts.

If the server's environment sets `APP_NOTICE`, every page shows that line in a band at the top. It is set in the environment only, not on this page.

## Authentication

Whether **two-factor authentication is required** and whether **SSO is the
only way in** for non-administrators. For single sign-on, register the tab's redirect URI at
your OpenID Connect provider and enter its issuer, client ID and secret; the
secret is stored encrypted and never shown. _Set by the server's environment_
means an `OIDC_*` variable wins. See [Signing in](/help/signing-in).

**Registration** lets anyone create an account with an email and a password. It
needs email working on the Email tab, is off by default, and is unavailable
while sign-in is limited to SSO. People enter only their address; a mailed
link lets them confirm it and choose a password. Optionally list the email domains allowed
(empty allows any) and set how many new accounts a day may be created.
A person who signs in through the provider with the same verified address
while a request is waiting takes the address, and the waiting request is
discarded.

## Defaults

The default **currency** and the **scales** new assessments start from. **Standard**
and **Small organisation** are built in; **Create scale** copies one. Each has its own **Save**.

## Templates

Administrators manage templates. See [Templates](/help/templates).

## Sidebar and unsaved changes

The sidebar shows the running version, with a link to its release notes.

Settings pages, your profile and the scale editors ask **Stay** or **Leave**
before a link takes you away from unsaved edits. The Back button and signing
out are not guarded.
