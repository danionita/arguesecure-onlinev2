# ArgueSecure 2.6.0

> Version history: this version predates the releases published here, and
> its note is kept to show what changed. To install, follow the README for the
> current release.

Two better models for the optional AI assistant, installed with one command,
plus standards templates and a rewritten help centre.

## The assistant

Before this release the assistant shipped with a small default model. Tested on
the kind of machine that runs it (four CPU cores), asked why a total does not
change when a reduction target is set, the default answered correctly once in
four tries and invented figures in one of four drafted summaries. Two new
presets answered correctly four times in four and invented no figures:

- **small** (Gemma 4 E4B) for modest hardware.
- **large** (Gemma 4 26B-A4B) for a machine with more memory.

Neither preset is easier or harder to steer than the old default, and nothing a
model writes reaches an assessment until a person accepts it.

- **One command installs a model:**
  `docker compose run --rm assistant-install small` (or `large`). It checks
  free memory and disk, resumes interrupted downloads, and verifies the file
  before using it. A model of your own still works.
- **The admin AI tab shows what is really installed,** asked of the running
  service, rather than a label typed into a settings file, and lists both
  presets with the memory and download each needs.
- **Faster after a restart.** The help answers are prepared in the background
  when the service starts, so the first person to ask does not wait a minute or
  two.
- **Better defaults.** Four processing threads instead of eight, and the
  service's CPU limit follows that setting.

## Also in this release

- **Standards templates** for ISO/IEC 27001, ISO/IEC 42001, the EU AI Act,
  SOC 2, GDPR, NIS2 and the CIS Controls, bringing the bundled templates to
  twelve. Each control is a defence you can mark as implemented, planned or
  shelved, which gives a gap analysis against the standard.
- **Add from template** on any item, and **From template** in the new-item
  form, copy individual risks, attacks or defences into an existing
  assessment.
- **A help centre rewritten for the current product:** ten pages that also
  feed the assistant's answers.
- **Your own edits no longer come back to bite you.** Your own change used to
  echo back from the live connection and could overwrite what you had just
  typed, or blank the Details tab on a new item.

## Changes for operators since 2.5.x

- **A 2.5 assistant keeps running without changes.** Whatever model your
  settings named, or the old default, still resolves to the same file.
- **`ASSISTANT_THREADS` now defaults to 4.** A machine with fewer than four
  CPUs to give the assistant must lower it or the service will not start.
- **To move onto a preset:** remove `ASSISTANT_MODEL_FILE` and `ASSISTANT_MODEL`
  from `.env`, run the install command above, then
  `docker compose --profile assistant restart assistant`. The old model file can
  then be deleted.
- **`/assistant/status` no longer reports a model name.** Read it from the
  admin AI tab instead.
- Templates added in this release load for new installs; see the README to load
  them into an existing instance.
