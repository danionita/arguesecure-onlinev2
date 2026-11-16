# Email

The application sends no email until you set it up here, and works fully without it.
Enter your mail server (SMTP) and the sender address, save, then press **Send test
email**: the test goes to your own address only, and email is **on** once it arrives.
Changing any setting switches it off until the next test. The password is stored
encrypted and never shown again. _Set by the server's environment_ means a `MAIL_*`
variable wins. Use **TLS** or **STARTTLS** (which refuses to send if the server does not upgrade); **None** sends the password and messages
readable, so use it only for a relay on the same machine.

## Notification email

Under the server settings, **Enabled notifications** chooses which notifications (a mention, an assignment, a drafted summary) may also be emailed. A new setup starts with all three on, and nothing is sent until email itself is on; an existing instance keeps the choices saved on it. People can turn each off in their profile. Emails gather for ten minutes and go out as one, with a sentence and a link and no comment text. Tick **Include assessment titles** only if titles may pass through your mail server.

## Notification templates

**Notification templates** lets you edit what each kind of message
says. Use the placeholders listed for it, such as `{{name}}`; anything else in double
braces is refused. Wording is plain text. A line saying why the person got the message
is always added and cannot be removed. A subject cannot use links or the summary, because subjects show on lock screens and in mail logs. **Preview** shows the message with sample values and
sends nothing; the reset button restores the shipped text.

## Forgot password

With email on, the login page shows **Forgot password?**: people get a one-time link, good for an hour, to choose a new password. It signs them out everywhere and does not sign them in. Deactivated accounts, and non-administrators under *SSO only*, get no link.
