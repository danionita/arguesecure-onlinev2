# ArgueSecure 2.4.0

Chart cards that show a figure instead of chrome, a tidier editor, and reports
you can download as HTML, CSV, JSON, Excel or Word.

2.4 was never released on its own: its changes shipped as part of 2.5.0.

## The chart and the list

- **Cards carry the number.** The bottom of a card shows the value that matters:
  `€400k` for a risk, `0.4/yr` for an attack, `60%` for a defence, and a
  two-part bar for the target of assessment showing the current and target
  loss against the unprotected loss. An unset value shows `?`. A defence shows
  the strength on each attack it counters, so the same defence can show two
  percentages and both are right.
- **Columns are sized to what they hold,** and 100% zoom now shows what 110%
  used to.
- **The list reads better.** A narrower column, no meaningless progress bar on
  the first row, and every value shown the same way (for example *Reduces
  likelihood: 40%*).
- **Fewer distractions in the item window.** Tabs wrap instead of hiding, the
  item's type is an icon beside its title, and a defence's Cost tab is about
  cost (status and the transfer switch moved to Details). The target of
  assessment gains a Scales tab.
- **Appears under.** An attack that sits under several risks now shows them
  from the counter on its card, where you can also detach it from one of them.
- **Reuse reaches the chart.** Adding an attack from the chart suggests
  existing ones, as the list already did.
- **The editor and the report share their header,** so they line up.

## Navigation

- **New assessment** creates one straight away.
- **Shared with me** is a page of its own.
- **The dashboard** has tabs for notifications (assigned to me, mentions) and
  assessments (mine, shared with me), with counts.
- **The sidebar toggle stays put** when the sidebar changes width.

## Reports and export

One report, five renderings:

- **HTML** is self-contained, with no outside resources.
- **CSV** now covers the whole report, not just defences.
- **JSON** keeps a stable shape, the same one snapshots use.
- **Excel** has one sheet per section, with numbers kept as numbers.
- **Word** has the prose sections in order, with tables.

PDF remains your browser's print dialog. The long **Download report** button is
replaced by the **Export** menu, and **Print** prints what you see. The unused
"figures" report option is removed.

## Fixes

- **The Details tab no longer comes up empty** when another tab has a problem;
  tabs load when you first open them.
- **Dropdowns are no longer hidden** behind other parts of the page.
- **Templates keep their references.** When you started an assessment from a
  template, the CVE, CWE and similar references attached to its items were
  dropped. They are now copied. Starting from a template is also faster.
- **The default scale means something.** The default scale set chosen in the
  admin settings now decides which built-in scales new assessments get, and a
  second set tuned for small organisations is included.
- **The AI assistant is easier to find.** The admin settings page explains what
  it is, that it runs on your own server, and what has to be switched on.

## Upgrading

See the upgrade steps in [`RELEASE-2.5.0.md`](RELEASE-2.5.0.md), which includes
this release.
