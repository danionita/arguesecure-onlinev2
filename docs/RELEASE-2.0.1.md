# ArgueSecure 2.0.1

> Version history: this version predates the releases published here, and
> its note is kept to show what changed. To install, follow the README for the
> current release.

Live collaboration that actually connects, and tighter sign-in and rate limits.
No new features and nothing to configure.

## Fixes

- **Live collaboration works.** In 2.0.0 the real-time connection never opened
  in a Docker deployment, so presence avatars, live updates and edit locks did
  nothing and two people could overwrite each other without warning.
- **Totals update as you type,** not only after a reload.
- **Drag and drop works.** Items can be reordered and moved.
- **The Share dialog opens** from the editor header.
- **Templates are available on a fresh install.** Creating the first
  administrator now loads the bundled templates.
- **An empty new item disappears** if you leave it untitled, instead of
  lingering as "Untitled".

## Security

- **Sign-in throttling is per address as well as per account,** so guessing
  many accounts from one address is limited too.
- **Editing and password actions are rate limited.**
- **Every response carries the security headers,** including error pages and
  the health check.
- **The sign-in page no longer shows framework or PHP versions.**

## Changes for operators since 2.0.0

There were no database or configuration changes.
