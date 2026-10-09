# `calculation-vectors.json` — the PHP/TypeScript oracle

This file is the shared golden-vector fixture required by
[`docs/specs/calculation-engine.md`](../../docs/specs/calculation-engine.md) §11.5 and §15.12. Both
`Calculation\RiskCalculator` (Pest, W7) and its TypeScript mirror (Vitest, W11) must be exercised
against every vector in here and agree with the `expected` block, and with each other, to the
tolerance declared at the top of the file (`1e-9`, absolute).

## Provenance — read this before trusting or editing the numbers

These vectors were derived **independently from the specification text**, by hand, without reading
`app/Domain/Calculation/**`, `resources/js/calc/**`, or any file implementing the engine. A
small throwaway Node script (not checked in) was used only as a calculator — to get
IEEE-double-precision-correct values for things like `ln()`, `exp()`, and repeated products —
never to look at or borrow from the real implementation. That is the entire point of an oracle:
if it had been influenced by the implementation, agreement between the fixture and the code would
prove nothing.

If a change to the spec changes expected behaviour, this file is a **breaking-change artifact**:
update it and both implementations in the same commit (per §11.5).

## Format

```jsonc
{
  "schema_version": 1,
  "tolerance": 1e-9,
  "known_spec_discrepancies": [ /* see below */ ],
  "vectors": [
    {
      "name": "...",              // stable, used as the test id in both Pest and Vitest
      "description": "...",       // what this vector pins down, and why it exists
      "input": { ... },           // the full graph: scales, risks, attacks, defences, edges
      "expected": { ... }         // baselines, completeness, warnings, rankings, attribution, cost-benefit
    }
  ]
}
```

Notes on reading `input`:

- `uuid` fields are short opaque string ids (`"R1"`, `"A2"`, `"D3"`), not RFC 4122 UUIDs — readability
  won over realism. Nothing in the engine should care about the format of an id.
- `risk_attack` and `attack_defence` are the DAG edges — `nodes` §-style single-table inheritance is
  flattened here into `risks` / `attacks` / `defences` arrays purely for fixture readability; the
  edges are what CLAUDE.md and schema.md actually call load-bearing (strength lives on the edge,
  ordering lives on the edge).
- A `null` `impact_mode` / `likelihood_mode` / `strength_mode` means "missing" (⊥), per
  calculation-engine.md §6.1 — distinct from a present mode with a `0` amount, which is a known value.
- Every defence not explicitly given a `status` should be assumed absent from the test's concern;
  vectors that only care about `current` still specify `status` for every defence because a missing
  status is not itself meaningful in this schema (unlike a missing scale value).

Notes on reading `expected`:

- `baselines.{inherent,current,target}` mirror `CalculationResult` from §13: `total`, `pairs`,
  `risks`, `attacks`. Vectors only populate the sub-fields that matter to what they're pinning down —
  a vector about cost-benefit doesn't re-assert every pair's `aro_residual`. Absence of a field in
  `expected` is not an assertion either way; only present fields are checked.
- `completeness` mirrors the §6.3 block. Fields omitted from a vector's `expected.completeness` are
  not being asserted by that vector (most vectors that don't care about, say, `orphan_defences`,
  simply don't mention it — assume `[]`/unset rather than "no opinion").
- `warnings` is a flat list of `{code, node_uuid, message}` as in §12. A vector lists only the
  warnings it is specifically checking for; it is not a claim that no other warnings fire.
- `defence_rankings`, `log_share_attribution`, and `cost_benefit` mirror §9 and §10.

## `known_spec_discrepancies`

Two places where hand-verifying §14 against §3.2 of `docs/specs/schema.md` turned up a genuine
inconsistency in the spec text itself (not an arithmetic slip on this fixture's part). Both are
recorded at the top of the JSON file as well, with enough detail to resolve them:

1. **R2's "moderate" impact.** §14 resolves it to `30,000`. The documented system-default impact
   scale (schema.md §3.2, band 10k–100k) has representative `√(10,000 × 100,000) = 31,622.7766…`,
   which schema.md's own table rounds to `31,623`. §14's totals only reproduce using `30,000`.
2. **D3's "strong" strength on A2.** §14 resolves it to `0.65`. The documented system-default
   strength scale assigns `0.65` to the **"moderate"** band (0.5–0.8); "strong" is the next band up
   (0.8–0.95, representative `0.875`). §14's totals only reproduce using `0.65`.

`worked_example_section14` encodes §14's own figures (so it satisfies the mandatory "reproduce
§14 exactly" requirement) but marks its impact and strength scales `is_system: false`, since they are
*not* actually the documented system defaults — flagging that explicitly rather than silently
asserting a scale equals "system default" when it demonstrably doesn't, per the value in the
resolved numbers. `qualitative_roundtrip_and_boundaries` and `aro_above_one` use the scales exactly
as documented in schema.md §3.2, unmodified, so they remain the source of truth for what the real
system defaults resolve to.

A third, smaller item — what `coverage` should be when `pairs_total = 0` — is a genuine spec gap
rather than a contradiction; the convention this fixture picked (`coverage = 1.0`, vacuous
completeness) is called out the same way.
