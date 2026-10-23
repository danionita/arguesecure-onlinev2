# How to read a report

**Report** is the third position of the view switcher, beside Chart and
List. It is marked **Beta**: the figures are the ones the other views use,
and the layout is still being refined. It is for reading, printing and
sending to people who don't need the editor.

## Options

The row above the report has two buttons, and every change applies at once:

- **Defences** — which defences the report covers: implemented ones always,
  planned ones with the target figures, and shelved ones listed only.
- **Sections** — switch any section on or off. Comments, the methodology, the
  model-quality warnings and assignees start off; the methodology states the
  assumptions the numbers rest on. A report that leaves sections out names
  them.

The search box finds text in the report; zoom changes its type size.

## What's in it

1. **Cover** — title, owner, date, the scales in use and the current known risk.
2. **Executive summary** — your own words, then inherent, current and target
   annual loss and what the defences buy.
3. **The plan: cost and benefit** — what the planned controls cost against
   the risk they remove.
4. **Risk register** — every risk, its impact, the attacks behind it and its
   loss before and after.
5. **Defences ranked by cost benefit** — implemented controls: the loss that
   would return if each were switched off, cost, CBR, ROSI and net benefit.
6. **Defences ranked by opportunity** — planned controls, by what each would
   buy. This is the roadmap. With shelved defences included, a third list
   shows what shelving them gave up.
7. **Transfers** — what is transferred and what loss is retained.
8. **Control coverage** — when defences cite framework controls: each
   cited control, whether it is implemented, planned or shelved, and the
   defences citing it. It lists what is cited, not a whole framework, and is
   not a statement of compliance.
9. **The full assessment**, with every value entered and computed.
10. **Appendices** — references, comments, the methodology and any
    data-quality warnings.

## Reading the numbers honestly

- **A partial total is shown as `≥ €amount`**, with how many (risk, attack)
  pairs were valued.
- **Every figure is labelled with its baseline**, because they can differ by
  an order of magnitude.
- **The importance and opportunity lists do not add up to the total
  reduction** — see [the quantification model](/help/quantification).
- **Priced, free and unpriced defences are listed separately.** An unpriced
  control is not a free one.
- **A control that costs more than it saves** (CBR under 1) is highlighted.
  That is often the finding worth acting on.

## The executive summary, and drafting it

Anyone who can edit writes the summary. Where the AI assistant is enabled,
**Draft with the assistant** writes one in the background and notifies you
when it's ready. The draft is shown beside your summary, never in place of
it: **Edit and use** opens it for you to change and save. A summary that
started as a draft is marked as machine-drafted in the report.

## Export and print

**Export** (in the bar above every view) produces **HTML**, **CSV**,
**JSON**, **Excel** or **Word**, using the options currently set.
*Everything expanded* ignores them and includes every section except
assignees. JSON is a complete, portable copy of the assessment's data.
Titles and descriptions are always written as text in every format. In the
CSV, text starting with `=`, `+`, `-` or `@` gets a leading `'` so a
spreadsheet shows it rather than calculating it.

**Print** uses your browser's print dialog with a stylesheet made for paper:
page breaks between sections, table headers repeated, light colours whatever
your theme. Choose **Save as PDF** as the destination to get a PDF. The chart
and the list print too: **Print** asks which of the three to print.
