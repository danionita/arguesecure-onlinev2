# ArgueSecure 2.0.0

> Version history: this version predates the releases published here, and
> its note is kept to show what changed. To install, follow the README for the
> current release.

A complete rewrite: collaborative security risk assessment with real numbers,
live editing and reports you can hand to a client.

ArgueSecure 2.0 shares a name with 1.x and nothing else. It is a new
application on current foundations (Laravel 12, PHP 8.3, Vue 3), and no 1.x
data is carried over.

## What you can do

- **Put numbers on risk.** Give each risk an impact (the loss each time it
  happens) and each attack a likelihood (how often per year, which may be more
  than once). ArgueSecure works out the expected annual loss three ways: with
  no defences, with what is implemented today, and with everything planned.
  Shelved defences never count.
- **Honest results.** Results are amounts in your currency with a reduction
  percentage, never low, medium or high. A value you have not filled in is
  flagged and left out of the total, never silently counted as zero.
- **Model a web, not a tree.** An attack can belong to several risks and a
  defence can counter several attacks. How effective a defence is can differ
  from one attack to the next, so strength is set on each link. Hovering a
  shared item highlights everywhere it appears.
- **Edit in place.** Add items inline and start typing; a tabbed window holds
  details, values, links, references, comments and history without leaving the
  assessment. Drag items to reorder or move them.
- **Work together live.** See who else is in an assessment, watch changes
  arrive, and see which item someone is editing so you never overwrite each
  other.
- **Reports.** A print-ready report ranks defences by what they save and by the
  opportunity they offer, and exports to CSV and JSON. PDFs come from your
  browser's print dialog.
- **Snapshots.** Freeze an assessment together with its computed results, so
  recalibrating later cannot change a figure you already reported. Compare any
  two snapshots.
- **Sharing.** Share with named people in a role, publish a read-only link, or
  make an assessment public, which grants read access only.
- **Templates and a catalogue** to start from, comments, per-item revision
  history with revert, external references (such as CVE identifiers) and a
  single undo history.
- **Accounts.** Administrators create accounts one at a time or in bulk from a
  CSV file. There is no public registration.

## Security

- Every assessment is checked against the same access rules, wherever it is
  reached from. Real-time channels are authorized on the server.
- There is no default account and no built-in password. The first administrator
  is created on a one-time setup screen.
- Nothing loads from outside your server, and a strict content security policy
  is enforced.

## First start

The application key and the real-time keys are generated on first start and
saved under your data directory. Back that file up: losing it signs everyone
out and invalidates encrypted cookies. Set `APP_URL`, `DB_PASSWORD`,
`DB_ROOT_PASSWORD` and `DATA_PATH` in `.env`. On first open there is a setup
screen where you create the first administrator.

## Coming from 1.x

There is no data migration. 1.x had no quantification, so an imported
assessment would arrive with every value missing and every total zero. Install
2.0 alongside 1.x on a different port and data directory, re-enter the
assessments still in use (adding impact and likelihood figures as you go), then
switch your reverse proxy over. Keep the 1.x data until nobody needs it.
Treat any 1.x password and application key as compromised and do not reuse
them.

## Known limits

- Moving an item under a parent that has no children yet has no drag gesture.
- Shift+Tab does nothing in the outline: an item's level is its type, which
  never changes.
