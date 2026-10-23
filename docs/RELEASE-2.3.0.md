# ArgueSecure 2.3.0

> Version history: this version predates the releases published here, and
> its note is kept to show what changed. To install, follow the README for the
> current release.

Three views of every assessment, the tools a team needs to work in one
together, and an optional AI assistant that runs on your own hardware.

## Three views

An assessment now opens as an **org chart**: the target of assessment on top,
then risks, attacks and defences, one row each, scrolling sideways and never
down. Each view has one job.

- **Chart** for sketching the shape of an assessment, for example on a screen
  in a workshop room. Each card shows its title and whether its values are
  filled in; click the indicator to go straight to the missing value.
- **List** for entering values: impact, likelihood, status and strength.
- **Report** for the answer, against all three baselines at once.

One selector (no defences, implemented, or everything planned) drives every
card and the totals. Computed figures are labelled as computed and entered
figures as entered. All three views print.

## Editing the graph as a graph

- **Rate a defence from either end.** Strength can be set while looking at the
  defence, not only while looking at each attack.
- **Move or link.** Dropping an item under a different parent asks whether it
  belongs there instead, or there as well.
- **Reuse instead of retyping.** The title field of a new item suggests
  existing attacks and defences as you type, so the same attack is not entered
  three times under three risks.

## Working together

- **Labels** are created by typing them on an item and can be filtered on.
- **Assignees.** Assign items to people, see your assignments on the dashboard,
  and optionally show them in the report. Being assigned grants no access.
- **Notifications** tell you when you are mentioned or an item is assigned to
  you. An @mention only matches people in that assessment.
- **History** is one timeline of everything that changed in an assessment,
  grouped so one action reads as one entry and filterable by person, kind of
  change and item.
- **Assessment settings** in one place: title, description, currency,
  amortisation period and a risk tolerance. The tolerance is shown as a
  comparison, never as a verdict.
- **Save as template** from the Share dialog. It warns that everyone on the
  instance will be able to read the template, and that this cannot be taken
  back.
- **A sidebar and a dashboard** replace the old top bar search: what is
  assigned to you, what changed and what is unfinished.

## An optional AI assistant

Off by default, and nothing else depends on it. It runs on your own server (a
CPU-only `llama.cpp` container, started with a Compose profile) and sends
nothing elsewhere.

- Answers questions about the in-app help, from the help pages and nothing else.
- Suggests risks, attacks and defences, with one Add button per suggestion.
- Drafts an executive summary for a report as a background job, and notifies
  you when it is ready.
- Nothing it writes reaches an assessment, a report or another person until you
  accept it.

On a CPU a help answer takes seconds once warm, and a summary of a large
assessment takes minutes. [`ASSISTANT.md`](ASSISTANT.md) has the measurements.

## Other improvements

- Adding items from the catalogue and templates is faster.
- The scale calibration screen is clearer.
- Several smaller fixes to how the editor reacts to updates from other people.

## Changes for operators since 2.0.1

1. **Back up your database.** This release removes the separate "notes" field
   from items and the catalogue; its role is covered by the description. Copy
   anything you need from it first. Other database changes only add to what
   exists, and run when the application starts.
2. **Rebuild and restart:** `docker compose up --build -d`.
3. **The assistant is unchanged by default.** To turn it on, set
   `ASSISTANT_ENABLED=true`, provide a model file and enable it under the admin
   settings; see `docs/ASSISTANT.md`. It needs the queue worker running.

## Known limits

- The assistant is slow on a small CPU. Choose the machine before enabling it.
- Reverting to an earlier point in the history is not offered yet; you can read
  the history and revert individual fields.
