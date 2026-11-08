# Users

**Admin**, in the sidebar, is shown to administrators only. Administrators
can open, edit and manage every assessment on the instance.

Accounts are created here, unless you have switched on self-registration
on the Authentication tab, which needs working email. Then people can also
create their own accounts.

- **Create** an account, or **Bulk import** several from a CSV file. The import previews every row it will create or reject, with the reason, before anything is written. The file needs a header
  row with `name` and `email` columns (`admin` is optional). Files saved from
  Excel as "CSV UTF-8", "CSV" or "Unicode Text" are read, with commas, semicolons or tabs between columns.
- New passwords are shown **once**, on a sheet you can print for a workshop. Leave the password blank when you create a user and one is generated and shown once; whoever receives a generated password, or one from a bulk import, must change it at their first sign-in.
  They are never stored in plain text or emailed.
- With **email set up** (the Email tab), creating a user without typing a password emails them a one-time link to choose their own, and no password is shown. Turn **Email an invitation** off to generate one instead. If they do not use it, they get a reminder after 3 days and another after 7, then no more. **Send invitation again** on the user's page replaces an old link with a new one. The link works once.
- Edit, deactivate, reset a password, make someone an administrator, or
  delete an account. Deleting a user who owns assessments offers to transfer
  them first.
- An account signed in through the identity provider shows its link;
  **Unlink** signs it out everywhere, and its next sign-in links again by verified email.
- An account's page shows whether it has two-factor authentication. If
  someone loses their phone, **Remove second factor** signs them out
  everywhere else; they sign in with their password and set it up again.
