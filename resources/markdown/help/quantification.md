# The quantification model

This is the most important page in this help section. Read it before you
trust a number this tool shows you.

## The formula

For every (risk, attack) pair — every place an attack is linked to a risk it
can cause — the tool computes an expected annual loss:

```
ALE(risk, attack) = likelihood(attack) × impact(risk) × factor(attack)
```

where `factor(attack)` is what's left of the attack's likelihood or the
risk's impact after every defence attached to that attack has been applied.
The assessment's total is the sum of `ALE` over every valued pair. That total
is computed three times, for three different sets of defences:

- **Inherent** — no defences at all. What the risk would be with nothing in
  place.
- **Current** — only defences whose status is *implemented*.
- **Target** — implemented *and* planned ("todo") defences. What the risk
  will be once the roadmap is done.

Defences marked **shelved** never count toward any of the three totals. The
report can list what shelving them gave up, but that figure never enters a
total.

## Two assumptions you must not get backwards

**Impact is the loss each time the risk occurs — not the annual loss.** If a
data breach costs €200,000 to clean up, enter €200,000, not an annualised
figure. The tool multiplies impact by likelihood to get an annual figure; if
you enter an already-annualised number here, the total will be wrong by a
factor of the likelihood.

**Likelihood is a rate, in occurrences per year — not a probability.** A
value of `0.5` means "roughly one occurrence every two years." A value of
`2.0` is legitimate and means two occurrences a year on average — the tool
will not reject it, and clamping it to 1 would silently understate a genuine
high-frequency threat such as a volumetric DDoS. If you think in percentages,
the field shows a live conversion hint: a rate of `0.5`/yr is about a 39%
chance of *at least one* occurrence in a year, computed as `1 − e^(−rate)`.
That conversion is informational only — the rate, not the percentage, is what
feeds the calculation.

## What a missing value does — and the deliberate asymmetry

Not every field will be filled in while an assessment is in progress. The
tool treats a missing value differently depending on what's missing, and the
difference is deliberate:

- **A missing impact or a missing likelihood excludes that (risk, attack)
  pair from every total.** It is never treated as zero. Coercing a missing
  impact to €0 would make the tool understate risk — and a security tool
  that quietly understates risk by default is worse than one that shows an
  honest, partial number. Instead, the total is shown as a lower bound
  (`≥ €53,500`) alongside how many of the possible paths were actually
  valued, so you can see at a glance how many values are entered.
- **A missing defence *strength* is treated as zero reduction** — the
  defence is counted as present but contributing nothing. This overstates
  the residual risk, which is the safe direction to be wrong in: an
  under-rated control looks less effective than it might be, rather than a
  gap in your data quietly looking like extra protection. The affected link
  is flagged so it's easy to find and fill in.

In short: missing on the loss side of the equation hides the pair from the
total (never zero); missing on the reduction side counts as no reduction
(never skipped). Both defaults point the same way — toward not understating
risk — even though they do it by opposite means.

An `ARO` or impact of exactly `0` is a different thing entirely: it's a
*known* value meaning "this cannot happen" or "this causes no loss," and it
contributes `0` to the total without being flagged as missing.

## Reduction target does not change the total

Every defence link has a **reduction target**: does this control reduce the
attack's *likelihood*, the risk's *impact*, or the *combined loss* (the
default, shown as blank)? This choice changes what the tool displays as the
residual likelihood on the attack and the retained impact on the risk — it
is genuinely useful for reading "MFA cut the chance of this succeeding" or
"insurance cut what we'd actually lose."

**It does not change the assessment's total.** Likelihood, impact and every
defence's reduction all multiply together, and multiplication doesn't care
about the order or grouping you multiply in. Whether a defence is set to
reduce likelihood, reduce impact, or reduce the combined loss, the number
that comes out the other end of the formula is identical to the last cent.
If you change a reduction target and the total on screen doesn't move, that
is correct — this is not a bug to report.

## Stacking defences assumes independence

When more than one defence is attached to the same attack, their reductions
combine by multiplying the *retained* fraction: two controls that each stop
50% of attempts leave `50% × 50% = 25%` getting through, not `0%`. This is
realistic diminishing returns, and it never reaches exactly zero risk from
partial controls stacking alone.

**This calculation assumes the defences fail independently of one another.**
Two controls that both collapse the moment a single shared weakness fails —
for instance, two things that both depend on the same domain admin account
not being compromised — are not actually independent, and stacking them this
way will show a lower residual risk than reality. This assumption **flatters
defence-in-depth**: it makes stacking controls look better than it may
actually be. The tool does not attempt to model correlation between
controls — there's no honest way to elicit a correlation coefficient in a
workshop setting — so treat a residual figure built from several stacked
defences as optimistic, not exact, especially where the controls share a
common point of failure.

## Reading the totals

Results are always shown as currency, with a reduction percentage from
inherent through current to target and a proportional bar — never as a
low/medium/high band. Impact-per-occurrence and annual-loss are different
units that typically differ by an order of magnitude; mapping an annual-loss
figure back through a per-occurrence band would be a silent unit error, so
the tool never does it. If an assessment still uses a shared starting scale
unchanged, its reports carry a persistent notice: a precise-looking euro figure
from a starting scale is misleading.

An attack linked to more than one risk shows a warning: totals assume the
attack succeeding causes *every* linked loss to occur. If two risks actually
describe the same underlying loss rather than two distinct ones, merge them
— the tool cannot detect that case on your behalf.
