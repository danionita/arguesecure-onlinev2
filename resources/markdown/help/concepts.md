# Concepts

An assessment in ArgueSecure is shaped as:

**Target of Assessment → Risks → Attacks → Defences / Transfers**

- The **Target of Assessment** is the root: the system, product or service
  being assessed. Its title and description are the assessment's own.
- A **Risk** is a loss you care about — a data breach, an outage, a
  compliance failure. A risk has an **impact**: what it costs *each time it
  happens*.
- An **Attack** is a way a risk can be realised — phishing, SQL injection, a
  DDoS. An attack has a **likelihood**: how often it happens *per year*.
- A **Defence** is a component or architectural decision that reduces a
  risk — MFA, a firewall, awareness training. A defence has a **status**:
  implemented, todo (planned) or shelved, and a **strength** against each
  attack it is linked to.
- A **Transfer** is a defence that shifts the loss to someone else instead of
  reducing it — a contract clause, a disclaimer, an insurance policy. It
  counts exactly like a defence; only its icon and label differ.

## Three views of one assessment

The switcher at the top right of an assessment moves between:

- **Chart** — the assessment as an org chart, one tier per row. Built for a
  workshop room: sketch the shape together.
- **List** — an indented list showing the values people entered. Use it to
  fill in impacts, likelihoods and strengths.
- **Report** (Beta) — the result, with every figure explained. See
  [How to read a report](/help/reading-a-report).

All three read the same live data. Switching never reloads the page or loses
your place.

## Why this is a graph, not a tree

The same attack (say, "phishing of admin credentials") is often a way into
more than one risk, and the same defence (say, MFA) often protects against
more than one attack. Forcing a tree would mean duplicating nodes and
watching the copies drift apart.

So an attack may sit under several risks, and a defence under several
attacks. The structure is a **directed acyclic graph**: a node can appear in
more than one place, and editing it anywhere edits the one node. A
**shuffle badge** with a number shows how many places a node appears in;
hovering a node highlights every place it appears.

This matters for the numbers: a shared attack's likelihood and a shared
defence's effect apply everywhere they are linked. See [the quantification
model](/help/quantification).
