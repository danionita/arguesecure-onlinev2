# Authentication

Whether **two-factor authentication is required** and whether **SSO is the
only way in** for non-administrators. For single sign-on, register the tab's redirect URI at
your OpenID Connect provider and enter its issuer, client ID and secret; the
secret is stored encrypted and never shown. _Set by the server's environment_
means an `OIDC_*` variable wins. See [Signing in](/help/signing-in).

**Registration** lets people create their own accounts. It is off by default and
needs email working on the Email tab or SSO set up. With SSO set up, a person
who signs in with the provider for the first time gets an account, no mail
needed, because the provider has proved the address; the SSO domain list limits
who (empty allows every domain), and the email domain list below applies too.
Signing up by email is not offered while sign-in is limited to SSO. A person
who signs up by email enters only their address; a mailed link lets them
confirm it and choose a password. Optionally list the email domains allowed
(empty allows any) and set how many new accounts a day may be created.
A person who signs in through the provider with the same verified address
while a request is waiting takes the address, and the waiting request is
discarded.
