# ArgueSecure 2.8.0

Email, self-registration, a refreshed look, and a round of security fixes: the application can now send mail (off until you set it up), people can reset their own password, an administrator can let people create their own accounts, and live updates are tightened to the people who should see them.

## Sign-in and security

- **Security fixes.**
  - Administrator accounts are never remembered between visits, and the cookies already issued stop working.
  - Changing an account's role signs its other devices out.
  - Share links are looked up by a hash of the token, so the database never compares the text a visitor types. Existing links keep working.
  - Only an assessment's owner, collaborators and administrators join its live channel. Readers of a public assessment or a read-only link reload the page to see changes.
  - The websocket accepts connections only from the application's own host (see **Upgrading from 2.7.0**).
  - The sign-in lockout is counted per email address and network address together, and a test now pins that (this was already the behaviour).
- **Forgot password.** With email set up, the sign-in page offers a one-time link, good for an hour, to choose a new password. The answer is the same whether or not the address has an account. Using the link ends every session and does not sign anyone in. Deactivated accounts, and non-administrators when sign-in is limited to single sign-on, get no link.
- **Self-registration, off by default.** An administrator can let people create their own accounts from the **Authentication** page. It needs working email, because nobody gets an account without following a mailed link, and the person chooses their password only after that. You can limit it to certain email domains and set a daily cap on new accounts. Registered accounts are ordinary, non-administrator accounts that own and belong to nothing.
- **Google sign-in** is documented alongside the other OpenID Connect providers, with the setup written from Google's documentation. It has not been tested against a live Google account.

## Email

The application sends no email until you configure it, and works fully without it.

- **Admin → Email** takes your SMTP server and sender address, with TLS, STARTTLS or none. Email turns on only after a test message has arrived. The password is stored encrypted. `MAIL_*` variables in `.env` win over what is stored.
- **Invitations.** Creating an account without typing a password emails the person a one-time link to choose their own. They get a reminder after 3 days and another after 7. You can still have a password generated instead.
- **Notification email.** Mentions, assignments and drafted summaries can also be emailed. Administrators choose which kinds are allowed, all off by default, and each person can turn them off in their profile. Mail gathers for ten minutes and goes out as one message, with a link and no comment text. Every message ends with a link to stop that kind.
- **Editable wording.** Each kind of message can be edited, with a preview and a reset to the shipped text.

## Administration

- **Administrators' help** is a separate help area with its own search, visible only to administrators. The administration pages left the public help.
- **A deployment notice.** Set `APP_NOTICE` to show one line of plain text in a band at the top of every page, including the sign-in page. It is not printed.
- **Moving to another hostname** is documented step by step: the proxy, sign-in providers, the websocket origin and what the database does and does not store.
- **A deploy-only compose file,** `docker-compose.deploy.yml`, for a server that has only the file and a `.env`. A failed image pull now reports the real cause instead of a missing Dockerfile.

## Look and feel

- **A new look.** The application takes on the new brand: palette, fonts, mark and icons.

## Upgrading from 2.7.0

1. **Back up your database.** This release adds database changes that run when it starts.
2. **Check `APP_URL`.** The websocket now accepts connections only from the host in `APP_URL`. If people open the app under another hostname or by IP address, live updates and presence stop for them (the rest of the page works) until you list those hosts in `REVERB_ALLOWED_ORIGINS`.
3. **Pull the new images and compose file** (`docker compose pull`, then `docker compose up -d`). If you keep your own `.env`, nothing is required. Optional new lines are in `.env.example`: `MAIL_*`, `APP_NOTICE`, `REVERB_ALLOWED_ORIGINS`.
4. **Administrators are signed out once.** Their remember-me cookies are cleared, and everyone else's sessions continue.
5. **Email, self-registration and Google sign-in stay off** until you set them up.

## Known limits

- Single sign-on. Only Keycloak has been tested. Google and other providers are expected to work but are unverified.
- Email has been verified with a STARTTLS server; try your own server with **Send test email** before relying on it.
- Readers of a public assessment or a read-only link no longer get live updates; they reload to see changes.
