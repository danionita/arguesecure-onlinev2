# Calculation Engine Specification

> Implements Phase 4 of `docs/PLAN.md`. Work packages **W7** (PHP) and **W11** (TypeScript mirror).
>
> Reads the schema in [schema.md](schema.md). Consumed by the reports in [ui.md](ui.md).
>
> **This is the highest-risk component in the project.** A subtly wrong engine produces plausible numbers that end up in a client report. Every formula below is normative; implement exactly what is written, and do not "improve" the semantics without changing this document first.

---

## 1. Notation

| Symbol | Meaning |
|---|---|
| `Imp(r)` | Loss **per occurrence** for risk `r`, in the assessment's currency. Not annual loss. |
| `ARO(a)` | Annual Rate of Occurrence for attack `a` — expected **occurrences per year**. A rate, not a probability. **May exceed 1.** |
| `s(d,a)` ∈ [0,1] | Fractional reduction on the link between defence `d` and attack `a`. `0.8` = 80% reduction. |
| `t(d,a)` | Reduction target ∈ {`likelihood`, `impact`, `∅`}, where `∅` = combined loss and is the default. |
| `P` | The set of **(risk, attack) pairs** — one per live `risk_attack` row with both endpoints alive. |
| `Links(a)` | Live `attack_defence` rows for attack `a`. |
| `S` | A defence set (a baseline). |
| `⊥` | **Unknown**, strictly distinct from `0`. |

"Live" means not soft-deleted.

---

## 2. Baselines

Three, all computed in a single pass:

| Baseline | Defence set `S` |
|---|---|
| `inherent` | `∅` — no defences at all |
| `current` | `{d : d.status = 'implemented'}` |
| `target` | `{d : d.status ∈ {'implemented','todo'}}` |

**`shelved` defences never participate in any baseline.** They may be evaluated on demand to answer "what did we give up?", but that result never enters a total.

---

## 3. The core formula

```
factor(a, S) = Π over l ∈ Links(a) where l.defence ∈ S of (1 − s(l))

ALE(r, a, S) = ARO(a) × Imp(r) × factor(a, S)

Total(S)     = Σ over valued pairs (r,a) ∈ P of ALE(r, a, S)
```

Roll-ups:

```
ALE(r, S) = Σ over a where (r,a) ∈ P of ALE(r, a, S)      the risk register row
ALE(a, S) = Σ over r where (r,a) ∈ P of ALE(r, a, S)      the attack row

Σ_r ALE(r,S) = Σ_a ALE(a,S) = Total(S)
```

---

## 4. Why the pair is the unit of loss

`ALE` is an expectation, and **expectation is linear**: `E[Σ Lᵢ] = Σ E[Lᵢ]` holds whether or not the loss events are independent or correlated. Summing per-pair ALEs to a total is therefore unconditionally valid, and no independence assumption is needed anywhere in the totals.

(Independence *is* assumed by the multiplicative defence combination in §7, and would additionally be needed for percentiles or value-at-risk — which this engine does not compute.)

Both slicings partition the same pair set, so the risk view and the attack view always reconcile to the same total. That property is what makes drill-down trustworthy, and it is exactly what the rejected alternatives destroy:

| Alternative | Why rejected |
|---|---|
| Attack owns a single impact = max or mean over its risks | Non-additive; risk view and attack view give different totals; editing risk R2's impact silently changes the number shown under R1 |
| Risk-level probability aggregation `p(r) = 1 − Π(1 − pₐ)` then `ALE(r) = p(r) × Imp(r)` | Requires probabilities not rates, so `ARO > 1` is inexpressible; caps at one loss per year, which is wrong for a DDoS at 2×/year; non-linear, so marginal contribution loses its closed form |
| Deduplicate a shared attack, counting it once against its "biggest" risk | Understates; makes the total depend on attachment order; makes marginal contribution non-monotonic |
| Weight the pair, splitting an attack's likelihood across its risks | Requires weights nobody can defend; conflates "this attack causes both losses" with "this attack causes one of these losses" |

**Offer as a display-only secondary metric**, next to a risk's ALE but never in a total:

```
P(at least one occurrence of r this year) = 1 − exp(−Σ_a ARO′(a,S))
```

### 4.1 The one genuine hazard — surface it, do not try to solve it

If a user attaches attack `A` to risks `R1` and `R2` that are *alternative descriptions of the same loss* rather than *two distinct losses both caused by A*, the total double-counts. **The model cannot detect this.**

Whenever an attack is linked to two or more risks, emit a warning and show it inline in the UI:

> Contributes to N risks. Totals assume all those losses occur when this attack succeeds. If these risks describe the same loss, merge them.

---

## 5. Reduction targets

For a pair `(r,a)` and defence set `S`, partition the applicable links:

```
L = { l ∈ Links(a) : l.defence ∈ S,  t(l) = likelihood }
I = { l ∈ Links(a) : l.defence ∈ S,  t(l) = impact }
C = { l ∈ Links(a) : l.defence ∈ S,  t(l) = ∅ }
```

Fixed evaluation order — and this is the order the UI displays as the derivation:

```
1.  ARO₀ = ARO(a)                            base rate
2.  ARO′ = ARO₀ × Π over l ∈ L of (1 − s(l))  residual likelihood
3.  Imp₀ = Imp(r)                            base per-occurrence loss
4.  Imp′ = Imp₀ × Π over l ∈ I of (1 − s(l))  residual (retained) impact
5.  ALE₀ = ARO′ × Imp′                       pre-combined expected annual loss
6.  ALE  = ALE₀ × Π over l ∈ C of (1 − s(l))  residual expected annual loss
```

### 5.1 State this plainly: reduction target does not change the total

Steps 2, 4 and 6 fold into a single product:

```
ALE(r,a,S) = ARO(a) × Imp(r) × Π over l ∈ Links(a), l.defence ∈ S of (1 − s(l))
```

Multiplication is commutative and associative, so the result is trivially order-independent **and target-independent**. This is not a defect — it is why the model is well-defined. But `reduction_target` still earns its column, for four reasons that are *not* the total:

1. **Display.** Step 2 yields the residual likelihood shown on the attack node; step 4 the retained impact shown on the risk row. Without targets you can only show the product.
2. **Transfers.** "Retained impact after insurance" is a number a risk manager asks for by name. It is exactly `Imp′`.
3. **Semantics.** "MFA cuts the chance", "insurance cuts the loss", "training cuts both a bit" is how people reason.
4. **Extension point.** Any future non-multiplicative refinement (deductibles, caps, likelihood floors) attaches to a specific target. Without the column that is unimplementable without a migration.

**Put this in the UI help text verbatim**, so nobody files a bug titled "changing reduction target doesn't change the total".

### 5.2 Defences on shared attacks apply per pair

`factor(a,S)` is a property of the **attack**, so it multiplies into every pair `(r,a)` for every risk the attack belongs to. If MFA halves the rate of credential phishing, it halves both the data-breach loss and the outage loss that phishing drives. Correct.

An impact-target transfer likewise applies per pair: its reduction is *conditional on the loss arriving via attack `a`*. Insurance attached to the phishing attack reduces the (R1, phishing) pair and leaves (R1, SQLi) untouched. That is a feature, not a bug.

### 5.3 A hook for the deferred risk-level-defence feature

Risk-level defences are **out of scope** (see the PLAN backlog). But structure the engine so the deferred feature is a migration rather than a rewrite: compute a `riskLevelFactor(r, S)` that currently **always returns `1.0`**, and multiply it in at step 6. One line, zero cost today.

---

## 6. Missing values

### 6.1 Classification

```
Imp(r) = ⊥  iff  impact_mode IS NULL
             OR (impact_mode = 'quantitative' AND impact_amount IS NULL)
             OR (impact_mode = 'qualitative'  AND impact_level_id IS NULL)

ARO(a) = ⊥  analogously on likelihood_*

s(l)   = ⊥  iff  strength_mode IS NULL
             OR (strength_mode = 'quantitative' AND strength_fraction IS NULL)
             OR (strength_mode = 'qualitative'  AND strength_level_id IS NULL)
```

### 6.2 Rules — note the deliberate asymmetry

1. **A pair is *valued* iff `Imp(r) ≠ ⊥` AND `ARO(a) ≠ ⊥`.** Unvalued pairs are **excluded from all totals** and counted separately. **Never coerced to zero** — coercing a missing impact to 0 would *understate* risk, and a security tool must never understate risk by default.
2. **A missing strength is coerced to `s = 0`** (no measured benefit). This *overstates* residual risk — the safe direction — and keeps an otherwise fully-valued path in the total instead of discarding it because one control lacks a rating. Flag the link in `links_missing_strength`.
3. **Never emit a bare number for a partial assessment.** Render `≥ €53,500 · 3 of 4 paths valued (75%)` with a completeness meter on the root node. Reports warn — but do not block — below a configurable coverage floor (default 100%).
4. A risk with **zero attacks** contributes 0, which is correct (no identified path to the loss), but is listed in `risks_without_attacks`. That is almost always incomplete modelling, not the absence of risk.
5. `s = 1.0` exactly is permitted (residual 0) but flagged in `implausible_strength`. The marginal-contribution computation must not divide by `1 − s` (§9.2).
6. **`ARO = 0` and `Imp = 0` are *known* values** meaning "cannot happen" and "no loss". They contribute 0 and are **not** flagged as missing. Distinguishing 0 from ⊥ is the entire reason those columns are nullable.
7. **An assessment with no pairs at all** (`pairs_total = 0`) has `coverage = 1.0` and `is_partial = false` — vacuously complete, not 0/0. An empty assessment is not a *partial* one, and reporting 0% coverage for a blank canvas would fire the `missing_values` warning at every user who has just clicked "new assessment".

### 6.3 The completeness block

Every `CalculationResult` carries:

```
total              float          over valued pairs only
is_partial         bool           pairs_unvalued > 0
pairs_total        int
pairs_valued       int
coverage           float          pairs_valued / pairs_total, 0..1
                                  pairs_total = 0 → coverage = 1.0, is_partial = false

risks_missing_impact        uuid[]
attacks_missing_likelihood  uuid[]
links_missing_strength      {attack_uuid, defence_uuid}[]
risks_without_attacks       uuid[]
orphan_attacks              uuid[]   attack with no risk link
orphan_defences             uuid[]   defence with no attack link — library items, informational not an error
shared_attacks              {uuid, risk_count}[]
implausible_strength        {attack_uuid, defence_uuid}[]
```

---

## 7. Combining multiple defences

```
factor(a, S) = Π over l ∈ Links(a), l.defence ∈ S of (1 − s(l))
```

Independent multiplicative composition. Properties: bounded in `[0,1]`; order-independent; adding a defence never increases risk; `s = 0` is a no-op.

Two 50% defences leave 25%, not 0% — realistic diminishing returns, and never zero risk.

**The independence assumption is real and it flatters defence-in-depth.** Two controls that both fail when the domain admin account is compromised are not independent. Note this on the methodology page. **Do not attempt to model correlation** — there is no honest way to elicit correlation coefficients from workshop participants, and that is the population this tool serves.

---

## 8. Qualitative mapping

### 8.1 Forward — level to number

`ScaleMapper::toNumber(level) = level.representative`. Default representative generation is specified in [schema.md §3.2.1](schema.md#321-default-representative-generation).

### 8.2 Reverse — number to level (band inversion)

```
toLevel(x, scale) = the unique level ℓ with ℓ.lower_bound ≤ x < ℓ.upper_bound
                    top band:                ℓ.lower_bound ≤ x
                    fraction scale top band: ℓ.lower_bound ≤ x ≤ 1
x = ⊥ → null   rendered as a grey "?" chip, never as the lowest band
```

Contiguity and coverage are guaranteed by the schema invariants, so the lookup is total on `[0, ∞)` and the result unique. Implement as a binary search over ordered `lower_bound` values.

**Boundary convention: `[lower, upper)` — inclusive lower, exclusive upper — everywhere.** A value exactly on a boundary lands in the *higher* band. Document this in the UI; users will type exactly 100,000.

### 8.3 Which scale for which quantity

| Displayed quantity | Unit | Scale |
|---|---|---|
| Risk impact `Imp(r)`, retained impact `Imp′` | currency **per occurrence** | `impact_scale` |
| Attack `ARO(a)`, residual `ARO′` | occurrences **per year** | `likelihood_scale` |
| Defence strength `s(d,a)` | fraction | `strength_scale` |
| **Pair / risk / assessment ALE** | currency **per year** | **none — rendered as currency** |

**Computed results are never band-mapped.** This is a settled decision. Impact is money-per-occurrence; ALE is money-per-year. They are different units and typically differ by an order of magnitude, so banding an ALE through the impact scale would be a silent unit error. Instead of a band label, show:

- the currency figure,
- the percentage reduction from inherent → current → target,
- a proportional bar.

`ScaleMapper::toLevel()` **must assert that the caller-supplied unit matches `scale.unit` and throw otherwise.** This turns the most likely implementation mistake into a type error rather than a wrong answer.

### 8.4 Pitfalls to handle explicitly

1. **Round-trip loss.** Guaranteed lossless only because `lower ≤ representative < upper` is validated. Required test: `toLevel(toNumber(ℓ)) === ℓ` for every level of every system and custom scale.
2. **Aggregation escalation.** Twenty "low" pairs sum to a large total. This is arithmetically correct and intuitively surprising. Always render the total next to the contributing-path count and the top three contributors so users see where it came from. **Do not "fix" it by taking a maximum** — that destroys the reason for having a quantitative engine.
3. **False precision.** When `display_mode = 'qualitative'`, never show cents; prefer an order-of-magnitude range (`€10k–€100k`) beside the figure. The underlying number is a product of representatives, not an elicited estimate.
4. **Uncalibrated defaults.** If an assessment still uses an unmodified system scale, show a persistent advisory on reports. Silently reporting euros off an uncalibrated default scale is the tool lying with a straight face.
5. **Mixed assessments.** A qualitative and a quantitative risk sum correctly because both resolve to currency — that is the design's payoff. Footnote mixed totals with "derived from N qualitative and M quantitative inputs".
6. **Rescaling breaks history.** Editing a scale changes every number computed from it. Bump `calc_version` and require the `manager` role. Snapshots inline their scales precisely so reports do not retroactively change.

### 8.5 Input labelling — two mandatory UI affordances

- **Impact** must be labelled unmissably as *loss each time this occurs*, not annual loss. Everything breaks if a user enters annual loss here.
- **Likelihood** must be labelled *how often per year*, with a live conversion hint `≈ 39% chance of at least one occurrence this year` computed as `p = 1 − e^(−ARO)`. Workshop participants think in probabilities; ALE needs rates.

---

## 9. Ranking defences

### 9.1 Marginal contribution

```
MC(d, S) = Total(S \ {d}) − Total(S)      for d ∈ S,  MC ≥ 0
```

"How much annual expected loss would return if we switched this control off."

Closed form — removing `d` touches only pairs whose attack is linked to `d`:

```
MC(d, S) = Σ over a ∈ attacks(d)  Σ over r where (r,a) ∈ P and valued
             ALE(r,a,S) × ( s(d,a) / (1 − s(d,a)) )
```

Well-defined and order-independent, because `factor(a,S)` is a commutative product: removing one factor gives the same answer regardless of which other defences are present or in what order they were applied. `MC(d,S)` depends only on `(S, d)`, never on a sequence.

### 9.2 Implementation rule — do not divide

For each attack, keep the list of `(1 − s)` factors with their defence ids and recompute the product excluding `d`:

```
factorWithout(a, S, d) = Π over l ∈ Links(a), l.defence ∈ S, l.defence ≠ d of (1 − s(l))

MC(d,S) = Σ over valued pairs  ARO(a) × Imp(r) × ( factorWithout(a,S,d) − factor(a,S) )
```

`k` (defences per attack) is tiny, so this is trivially fast. It avoids division by zero at `s = 1` and avoids floating-point drift.

**Keep the division form as the documented analytic identity, and assert in a unit test that both agree for `s < 1`.**

### 9.3 The addition delta — for planning

```
MCadd(d, S) = Total(S) − Total(S ∪ {d})   ≥ 0
```

Produce **two ranked lists**, and label the baseline on every report:

- **Defences ranked by cost benefit** — implemented defences, each with `MC(d, current)`, what we would lose by turning it off. Priced defences are ordered by cost-benefit ratio, then net benefit; free and unpriced by `MC`.
- **Defences ranked by opportunity** — `MCadd(d, current)` over todo defences: what we would gain by doing it. This is the roadmap.
- Shelved defences may be evaluated with `MCadd` on demand ("what did we give up?"), never included in a total.
- Optionally, importance against the target baseline for roadmap prioritisation. Note that `MC` against target is **smaller** than against current for any defence overlapping a planned one — that is real diminishing returns, and it is why the baseline must be labelled.

### 9.4 Marginal contributions are not additive — say so in the UI

With multiplicative composition, removal deltas **understate** and sum to less than the joint reduction:

```
Σ over d ∈ S of MC(d, S)  ≤  Total(∅) − Total(S)
```

Minimal example: one pair, inherent 100, two defences at 50% each. `Total(S) = 25`, each `MC = 25`, sum 50, joint reduction 75. **Do not present removal deltas as a decomposition.**

### 9.5 Log-share attribution — the additive metric

Because reductions are multiplicative, they are additive in log space. Per pair, split the total reduction in proportion to log-strength:

```
w(l)    = ln( 1 / (1 − s(l)) )        clamp s at 1 − 1e−9 for weighting only
Attr(d) = Σ over pairs (r,a) ∈ P of
            [ ALE(r,a,∅) − ALE(r,a,S) ] × w(d,a) / Σ over l ∈ active(a,S) of w(l)
```

This satisfies `Σ over d ∈ S of Attr(d) = Total(∅) − Total(S)` **exactly**, so it is the correct number for a "share of risk reduction" pie or stacked bar.

**`Attr(d)` is defined only for `d ∈ S`. For any defence outside `S` — a `todo`
defence against the `current` baseline, or a `shelved` one against any baseline
— `attribution` is `null`, never `0`.** This is the same distinction §10 draws
for an unpriced `CBR`: `0` means "was credited nothing", `null` means "the
question does not apply". A defence *in* `S` with `s = 0` legitimately has
`Attr = 0`, and conflating the two would make it impossible to tell a control
that earned no credit from one that was never eligible. Mirrors the
`mc` / `mcAdd` mutual exclusivity in the same result object.

**Present `MC` for decision-making ("should I keep this?") and `Attr` for reporting ("who gets credit?"). Do not blend them.**

(Shapley values would also work and coincide with log-share up to ordering, but cost O(2^k) instead of O(k) and need more explanation. Log-share is the choice.)

---

## 10. Cost-benefit

```
AC(d)  = opex_annual_amount + capex_amount / assessment.amortization_years
       = ⊥  if both cost columns are NULL

CBR(d) = MC(d, baseline) / AC(d)        € risk reduced per € spent per year
ROSI(d)= (MC(d) − AC(d)) / AC(d) = CBR(d) − 1
NB(d)  = MC(d) − AC(d)                  net annual benefit, absolute
```

Treat a NULL cost column as 0 **only when the other is set**; if both are NULL, `AC = ⊥`.

### Three buckets, never one merged list

| Bucket | Condition | Sort | Rendering |
|---|---|---|---|
| **Priced** | `AC > 0` | `CBR` desc, tiebreak `NB` desc | show CBR, ROSI %, NB |
| **Free** | `AC = 0` | `MC` desc | CBR shown as `—` with a "no cost" badge. **Never print ∞.** |
| **Unpriced** | `AC = ⊥` | `MC` desc | separate section, "cost not estimated". CBR is `null` — not 0, not ∞. |

**Never impute a cost.** Treating NULL as 0 ranks every unpriced control above every priced one; treating it as ∞ buries them. Segregation is the only honest presentation, and the unpriced section doubles as a to-do list for the analyst.

`CBR` ignores scale — a €50 control returning €500 outranks a €50k control returning €400k — so **always show `NB` alongside** and let the table sort by either. **Highlight `CBR < 1`**: a control costing more than it saves is the finding people act on.

---

## 11. Implementation

### 11.1 Services

| Class | Responsibility |
|---|---|
| `Calculation\RiskCalculator` | **Pure.** `compute(AssessmentGraph $g): CalculationResults` — all three baselines in one pass. No Eloquent, no I/O, no clock, no randomness. |
| `Calculation\ScaleMapper` | `toNumber(ScaleLevel): float`, `toLevel(float, Scale, string $unit): ?ScaleLevel` (asserts unit agreement) |
| `Calculation\DefenceRanker` | `MC`, `MCadd`, log-share attribution, cost-benefit buckets |
| `Calculation\CalculationCache` | Redis get/put keyed on `calc_version` |

`RiskCalculator` accepts **only** an `AssessmentGraph` DTO (see [schema.md §4](schema.md#4-eloquent-models)). Passing Eloquent models into the engine is a design violation — it is what makes the engine testable, snapshot-replayable, and mirrorable in TypeScript.

### 11.2 Execution order — single pass, no recursion

1. Load the graph: 4 queries plus scale levels.
2. Resolve every value to a `Valued{float|null, source: quantitative|qualitative|missing}` via `ScaleMapper`.
3. Per attack, per baseline: build the factor list and `factor(a,S)`, plus the L/I/C sub-products for display.
4. Per pair: compute `ARO′`, `Imp′`, `ALE`; mark valued or unvalued.
5. Roll up to risks, attacks, total.
6. Per defence: `MC`, `MCadd`, `Attr`, by recomputing per-attack products with that defence excluded.
7. Cost-benefit buckets and sorting.
8. Band-map residual likelihood and retained impact through their scales. **Do not band-map ALEs.**
9. Completeness block and warnings.

Memoize the per-attack link list once and derive the three baselines by filtering it — they differ only by the status filter.

### 11.3 Numerics

Use IEEE double (`float` / `number`) throughout. Every operation is a product of values in `[0,1]` times two non-negative scalars, plus sums; no catastrophic cancellation exists.

- **Never round intermediates.** Round to 2 decimals only in the API serializer.
- `DECIMAL` columns arrive from PDO as strings. Cast once, explicitly, at graph-load time.

### 11.4 Caching and invalidation

- Cache the `CalculationResults` for **all three baselines together**, in Redis, key `as:calc:{assessment_id}:v{calc_version}:e{engine_version}`, TTL 6 h.
- **Invalidate by explicit version bump, not TTL or fingerprint.** An observer increments `assessments.calc_version` on: any create/update/delete/restore of a node; any `risk_attack` or `attack_defence` mutation; any scale or scale-level change; and any change to `currency` or `amortization_years`. `put()` keeps one entry per assessment: it records the key it wrote in `as:calc:{assessment_id}:latest` and deletes the entry that pointer named before (W109, D72), so superseded keys do not wait out their TTL. Deterministic and race-free.
- On bump, dispatch a queued `RecalculateAssessment` job, **debounced 250 ms per assessment** via a Redis `SET NX` guard, which recomputes and broadcasts `AssessmentTotalsUpdated` on `presence-assessment.{id}`. Dragging a slider produces one broadcast, not forty.
- Snapshots store `results` verbatim and **never recompute**.

The whole computation for a 500-node assessment is sub-millisecond once loaded; the cost is the four queries, not the arithmetic.

### 11.5 The TypeScript mirror (W11)

Ship a TypeScript implementation to the client for instant optimistic feedback while editing. **PHP is authoritative** and its broadcast result overwrites the local one.

Guard the duplication with a shared golden-vector fixture at `tests/fixtures/calculation-vectors.json`, exercised by both Pest and Vitest, asserting agreement **to 1e-9**. Without that fixture the two implementations *will* diverge. The fixture is the contract; treat a change to it as a breaking change requiring both implementations to be updated in the same commit.

---

## 12. Warnings the engine emits

| Code | Trigger | Message |
|---|---|---|
| `shared_attack` | attack linked to ≥ 2 risks | "Contributes to N risks. Totals assume all those losses occur when this attack succeeds. If these risks describe the same loss, merge them." |
| `uncalibrated_scales` | assessment still uses an unmodified system scale | "Scales have not been calibrated for this assessment." |
| `implausible_strength` | `s = 1.0` exactly | "A 100% effective control eliminates this path entirely. Confirm this is intended." |
| `risk_without_attacks` | risk has no attack links | "No attack paths identified — this risk contributes nothing to the total." |
| `missing_values` | `coverage < 1.0` | "N of M paths are missing values and are excluded from the total." |
| `independence_assumed` | ≥ 2 defences on any one attack | "Stacked defences on this attack are combined assuming independence." |

**Message text is part of the contract.** Every string above is normative and
must match byte-for-byte in both the PHP engine and the TypeScript mirror, because
the golden vectors assert them. Changing one is a breaking change requiring both
implementations and the fixtures to move in the same commit.

**`node_uuid` is `null` for assessment-level warnings.** `missing_values` and
`uncalibrated_scales` summarise the whole graph — `missing_values` is phrased "N
of M paths" precisely because it is not about one node, and with more than one
unvalued pair, naming a single node would be an arbitrary choice among equals.
`shared_attack`, `implausible_strength` and `risk_without_attacks` do name their
node; `independence_assumed` names the attack.

**`uncalibrated_scales` requires a DTO field.** `AssessmentGraph` must carry a
`scales` collection including `is_system`, populated by `AssessmentGraphLoader`.
The engine is pure and must never query for this itself. Emit the warning when
any scale the assessment actually uses is still an unmodified system default.

---

## 13. Result object

```
CalculationResult (one per baseline)

baseline: 'inherent' | 'current' | 'target'
total: float

risks:    [{ uuid, ale, impact_value, impact_level_code,
             annual_probability, pairs_valued, pairs_total }]
attacks:  [{ uuid, ale, aro_base, aro_residual, aro_level_code,
             factor, risk_count }]
pairs:    [{ risk_uuid, attack_uuid, aro_residual, impact_residual, ale, valued }]
defences: [{ uuid, status, mc, mc_add, attribution, annual_cost,
             cbr, rosi, net_benefit, cost_bucket }]

completeness: { …§6.3 fields… }
warnings:     [{ code, node_uuid, message }]
```

`CalculationResults` is the container of all three.

---

## 14. Worked example — the first golden vector

**Assessment:** currency EUR, `amortization_years = 3` (irrelevant here, all costs annual).

> **This example uses a calibrated scale, not the system defaults.** Two
> qualitative values below deliberately do not match [schema.md §3.2](schema.md#32-system-default-scales),
> and the numbers are chosen for legible arithmetic rather than to exercise the
> defaults:
>
> | Value | Used here | System default would be |
> |---|---|---|
> | impact `moderate` (10k–100k) | **30,000** | 31,623 (`√(10,000 × 100,000)`) |
> | strength band with representative 0.65 | labelled **`strong`** | 0.65 is `moderate`; `strong` is 0.875 |
>
> Implementations must take the **numeric** values as authoritative here — 30,000
> and 0.65 — because every total below is derived from them. Do **not** resolve
> these two labels through the system default scales; that yields different
> totals and the example stops reconciling. A separate vector should exercise the
> genuine system defaults from schema.md §3.2 verbatim.

**Risks**

| | Impact | Resolves to |
|---|---|---|
| R1 "Customer data breach" | quantitative €200,000 | 200,000 |
| R2 "Service outage" | qualitative `moderate` (10k–100k) | **30,000** |

**Attacks**

| | Likelihood | Resolves to | Linked risks |
|---|---|---|---|
| A1 "Phishing of admin credentials" | quantitative 0.5/yr | 0.5 | **R1 and R2** (shared) |
| A2 "SQL injection on portal" | qualitative `unlikely` (0.02–0.2) | **0.06** | R1 |
| A3 "Volumetric DDoS" | quantitative 2.0/yr | 2.0 | R2 |

**Defences**

| | Status | Target | Links & strength | Cost |
|---|---|---|---|---|
| D1 "MFA on admin accounts" | implemented | likelihood | A1: 0.80 | €4,000/yr opex |
| D2 "Cyber insurance" *(transfer)* | implemented | impact | A1: 0.50; A3: 0.30 | €12,000/yr opex |
| D3 "Web application firewall" | todo | likelihood | A2: qualitative `strong` → **0.65**; A3: 0.50 | €10,000/yr opex |
| D4 "Legacy VPN hardening" | shelved | ∅ combined | A1: 0.40 | €2,000/yr opex |

Pairs: `(R1,A1) (R2,A1) (R1,A2) (R2,A3)` — 4 pairs, all valued, coverage 1.0.

### Inherent

```
(R1,A1) = 0.5  × 200,000 = 100,000
(R2,A1) = 0.5  ×  30,000 =  15,000
(R1,A2) = 0.06 × 200,000 =  12,000
(R2,A3) = 2.0  ×  30,000 =  60,000
Total inherent = 187,000
```

### Current — S = {D1, D2}

```
A1: L = {D1 s=0.80}, I = {D2 s=0.50}, C = {}
    ARO′ = 0.5 × 0.20 = 0.10        → likelihood band "unlikely", down from "possible"
    factor(A1) = 0.20 × 0.50 = 0.10
    (R1,A1): Imp′ = 200,000 × 0.50 = 100,000 → ALE = 0.10 × 100,000 = 10,000
    (R2,A1): Imp′ =  30,000 × 0.50 =  15,000 → ALE = 0.10 ×  15,000 =  1,500
A2: no implemented defences → factor 1.00  → ALE = 12,000
A3: I = {D2 s=0.30} → factor 0.70          → ALE = 60,000 × 0.70 = 42,000

Total current = 10,000 + 1,500 + 12,000 + 42,000 = 65,500
```

Reconciliation — risks: R1 = 10,000 + 12,000 = **22,000**; R2 = 1,500 + 42,000 = **43,500**; sum 65,500 ✓
Attacks: A1 = 11,500; A2 = 12,000; A3 = 42,000; sum 65,500 ✓

### Target — S = {D1, D2, D3}; D4 shelved, still excluded

```
A1: unchanged, factor 0.10        → (R1,A1) 10,000 ; (R2,A1) 1,500
A2: L = {D3 s=0.65} → factor 0.35 → 12,000 × 0.35 =  4,200
A3: I = {D2 0.30}, L = {D3 0.50} → factor 0.70 × 0.50 = 0.35 → 60,000 × 0.35 = 21,000

Total target = 10,000 + 1,500 + 4,200 + 21,000 = 36,700
```

### Ranked defences

**Importance — implemented, removal delta against current (65,500):**

```
D1 (MFA): factorWithout(A1, S, D1) = 0.50
  (R1,A1): 100,000 × 0.50 = 50,000  (was 10,000)  → +40,000
  (R2,A1):  15,000 × 0.50 =  7,500  (was  1,500)  → + 6,000
  MC(D1) = 46,000
  Identity check: ALE(A1) × s/(1−s) = 11,500 × 0.80/0.20 = 46,000 ✓

D2 (Cyber insurance): affects A1 (s=0.50) and A3 (s=0.30)
  A1: 11,500 × 0.50/0.50 = 11,500
  A3: 42,000 × 0.30/0.70 = 18,000
  MC(D2) = 29,500
  Direct check: Total(S\{D2}) = 20,000 + 3,000 + 12,000 + 60,000 = 95,000; 95,000 − 65,500 = 29,500 ✓
```

**Opportunity — todo, addition delta against current:**

```
MCadd(D3) = 65,500 − 36,700 = 28,800
```

**Shelved, on demand only:**

```
current ∪ {D4}: A1 factor = 0.10 × 0.60 = 0.06 → (R1,A1) 6,000; (R2,A1) 900
Total = 60,900 → MCadd(D4) = 4,600
```

**Non-additivity demonstrated:** `MC(D1) + MC(D2) = 75,500`, while the joint inherent→current reduction is `187,000 − 65,500 = 121,500`. Removal deltas understate by 46,000, exactly as §9.4 predicts.

**Log-share attribution (sums to 121,500):**

```
w(D1) = ln(1/0.20) = 1.6094379124341003
w(D2 on A1) = ln(1/0.50) = 0.6931471805599453
Σw on A1 = 2.3025850929940457  (= ln 10)

shares on A1: D1 = 0.6989700043360187 ; D2 = 0.30102999566398120

(R1,A1) reduction 90,000 → D1 62,907.300390241680 ; D2 27,092.699609758320
(R2,A1) reduction 13,500 → D1  9,436.094558536252 ; D2  4,063.905441463748
(R1,A2) reduction 0
(R2,A3) reduction 18,000: D2 only                 → D2 18,000

Attr(D1) = 72,343.395448777932   Attr(D2) = 49,156.604551222068   Sum = 121,500 ✓
```

**Cost-benefit (all priced, all annual):**

| Rank | Defence | Status | MC / MCadd | Annual cost | CBR | ROSI | Net benefit |
|---|---|---|---|---|---|---|---|
| 1 | D1 MFA | implemented | 46,000 | 4,000 | **11.50** | +1050% | 42,000 |
| 2 | D3 WAF | todo | 28,800 | 10,000 | **2.88** | +188% | 18,800 |
| 3 | D2 Cyber insurance | implemented | 29,500 | 12,000 | **2.46** | +146% | 17,500 |
| 4 | D4 VPN hardening | shelved | 4,600 | 2,000 | **2.30** | +130% | 2,600 |

**Partial-data variant to encode as a second vector:** blank A2's likelihood. The pair (R1,A2) drops out, current total becomes **€53,500**, flagged `≥ €53,500 · 3 of 4 paths valued (75%)`, with A2 listed in `attacks_missing_likelihood`.

---

## 15. Verification checklist

W7 and W11 are not complete until every item passes.

1. The §14 worked example reproduces **exactly**: inherent €187,000, current €65,500, target €36,700.
2. Risk-view and attack-view subtotals both reconcile to the total, for every fixture.
3. `MC` by factor-exclusion equals the analytic `Σ ALE × s/(1−s)` for all `s < 1`, and does **not** divide by zero at `s = 1`.
4. Log-share attribution sums exactly to `Total(inherent) − Total(S)`.
5. `toLevel(toNumber(ℓ)) === ℓ` for every level of every system and custom scale.
6. Blanking a risk's impact drops exactly its pairs from the total and raises the correct completeness flags — it does **not** silently contribute zero.
7. Blanking a link's strength leaves the pair in the total with `s = 0` and flags `links_missing_strength`.
8. Changing a defence's `reduction_target` changes `ARO′` and `Imp′` but leaves the total **bit-identical**.
9. Reordering defences on an attack leaves every number identical.
10. A shelved defence never affects any of the three totals.
11. `ARO = 0` contributes 0 and is not flagged missing; `ARO = NULL` is flagged and excluded.
12. PHP and TypeScript agree to 1e-9 on every golden vector.
13. `ScaleMapper::toLevel()` throws when given a unit that disagrees with the scale.
14. `RiskCalculator` has no Eloquent import, no I/O, no clock and no randomness — assert this with an architecture test.
