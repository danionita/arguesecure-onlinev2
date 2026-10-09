# ArgueSecure — what it is for, and what it is not

> The product brief every plan is judged against. `CLAUDE.md` says *how* work
> is done here; this says *what is worth doing*. A proposal that cannot say
> which of the three jobs below it serves is not ready to be a work package.

---

## The niche

**Argumentation-based, collaborative risk assessment, with a strong focus on
risk identification and on evidencing the risk-assessment requirements of
standards and regulations.**

Three jobs, in order of priority:

1. **Help a group identify risks together.** A workshop, not a form. People
   argue a *Target of Assessment → Risks → Attacks → Defences/Transfers* graph
   into shape: an attack is a claim about how a risk is realised, a defence is
   a counter-claim, and the structure makes gaps visible ("this risk has no
   attack yet", "this attack has no defence"). Real-time co-editing,
   assignees, labels and history exist to serve this.
2. **Make the result defensible.** Quantified where the group can quantify
   (ALE with inherent/current/target baselines, `calculation-engine.md`),
   qualitative where it cannot, never pretending a missing number is zero,
   with a full history of who changed what. A report someone can hand to a
   manager or an auditor.
3. **Show that a framework's risk-assessment requirement was met.** Templates
   for ISO/IEC 27001 (Annex A), ISO/IEC 42001, the EU AI Act, SOC 2, GDPR,
   NIS2 and the CIS Controls place each control or obligation under the
   threats it addresses, as a gap analysis to argue through. They summarise;
   they are not the standards' text and not legal advice.

## Who it is for

Security and risk practitioners running assessments *with* other people —
consultants facilitating a client workshop, a small security team with
engineers and a product owner in the room, a compliance lead who has to show
an auditor that risks were identified systematically. Organisations that
need to keep assessments on their own infrastructure.

## Principles that are not up for trade

- **Self-hosted, free and open-source.** No paid dependencies, no telemetry,
  no cloud requirement. Runs on a home server (the reference deployment is
  Unraid) as readily as in a data centre.
- **People decide; models suggest.** The optional local assistant proposes;
  nothing it writes reaches the graph, a report or another user until a
  person accepts it (`PLAN-2.3-ai.md` D24). This holds for any future AI
  feature, and for anything written *about* the product, too.
- **Honest numbers.** A missing value is excluded, never coerced to zero;
  computed results are never band-mapped to low/medium/high.
- **Measured, not asserted.** Performance, model quality and guardrails are
  benchmarked and recorded (`docs/spikes/`) before they drive a decision.

## What it is not — non-goals

- **Not a GRC suite.** No policy management, audit workflow, evidence
  collection, vendor-risk questionnaires, or control-testing schedules.
  Tools that do those well exist; integrating with them (import/export) is in
  scope, becoming them is not.
- **Not an automated scanner.** It does not discover assets or
  vulnerabilities; it structures what people know and argue.
- **Not a compliance certificate.** It helps evidence an assessment; it never
  claims to make anyone compliant.

## The landscape it sits in

A starting map, to be deepened by research (`docs/research/`), not a finding:

| Category | Examples | How ArgueSecure differs |
|---|---|---|
| Risk-assessment methods | ISO/IEC 27005, ISO 31000, NIST SP 800-30, EBIOS Risk Manager, CORAS, OCTAVE, FAIR | Methods to support, not compete with. EBIOS RM and CORAS are workshop-based; FAIR is close to the ALE model here |
| Threat-modelling techniques and tools | STRIDE, attack trees, attack-defence trees, OWASP Threat Dragon, IriusRisk | Those model a system's design; ArgueSecure argues a risk register into shape, and quantifies it |
| Open-source GRC | Eramba, SimpleRisk, CISO Assistant | Registers and compliance workflow; the identification step is a form, not a shared argument |
| Assurance / argumentation | Goal Structuring Notation (GSN), assurance cases | The same idea — a structured argument, open to challenge — that ArgueSecure applies to identifying security risks rather than to certifying safety |

## How to judge a proposal

1. Which of the three jobs does it serve, and how would a workshop feel
   different afterwards?
2. Does it keep the non-goals non-goals?
3. Does it respect the principles — especially "people decide"?
4. What is the smallest version that proves it, and how would we measure
   that it worked?
5. Is the claim it lets us make true? (Marketing and in-app text included.)
