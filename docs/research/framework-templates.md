# Which frameworks to add as standards templates

*Researched 2026-10-09. Dates and statuses below are as of that day.*

## Question

ArgueSecure ships seven standards templates (ISO/IEC 27001:2022, ISO/IEC
42001:2023, the EU AI Act, SOC 2, GDPR, NIS2, CIS Controls v8.1), each a gap
analysis: every control or obligation is a *todo* defence under the threats it
addresses, citing its control (D64). Which framework should come next?

The answer feeds PRODUCT.md's third job, *show that a framework's
risk-assessment requirement was met*. A candidate earns a place only if the
framework **itself requires a risk assessment** that an ArgueSecure graph can
evidence. A control catalogue with no risk-assessment requirement, or one whose
real need is audit workflow or evidence collection, is the GRC suite the
non-goals rule out.

## How each candidate was judged

1. **Risk-assessment requirement** — does the framework demand one, and where?
2. **Fit with the graph** — do its obligations read as defences against
   threats, so that the template is an argument and not a checklist?
3. **Who needs it** — overlap with the people PRODUCT.md names (consultants,
   small security teams, compliance leads), and how soon.
4. **Can we summarise and cite it** — free text to read, a stable control
   identifier for a D64 pattern, no licence that forbids a summary.
5. **Cost** — a template JSON, a `config/arguesecure.php` framework entry with
   its pattern, a help line, the fixture validator's tests (W102 shape).

## Ranking

| # | Framework | Risk-assessment requirement | Who, and when | Fit | Verdict |
|---|---|---|---|---|---|
| 1 | **EU Cyber Resilience Act** (Reg. (EU) 2024/2847) | Manufacturers assess the cybersecurity risks of each product with digital elements, document it, and keep it updated through the support period; the essential requirements are applied in its light (Art. 13; Annex I) | Every maker of connected hardware or software sold in the EU. Reporting from 11 Sep 2026, main obligations from 11 Dec 2027 | Strong: Annex I's essential requirements are defences against product threats, the shape of the IoT archetype | **Do next** |
| 2 | **DORA** (Reg. (EU) 2022/2554, with RTS (EU) 2024/1774) | An ICT risk-management framework: identify sources of ICT risk, assess risk on every major change, set risk tolerance, treat what lies outside it (DORA Art. 6, 8; RTS Art. 3) | EU financial entities and their ICT providers; applies since 17 Jan 2025 | Good: Articles 5–16 read as defences; it is NIS2's lex specialis for finance, so the two templates sit side by side | **Do** |
| 3 | **NIST Cybersecurity Framework 2.0** | Risk assessment is its own category, ID.RA-01 to ID.RA-10: threats, vulnerabilities, likelihood and impact identified, inherent risk understood, responses prioritised | Worldwide, the most-asked-for framework missing here; voluntary | Good: Protect/Detect/Respond/Recover outcomes become defences; ID.RA is the assessment ArgueSecure *is* | **Do** |
| 4 | **PCI DSS v4.0.1** | Req. 12.3.1 targeted risk analysis for every requirement with a flexible frequency; 12.3.2 for each customized-approach control | Anyone who stores, processes or transmits card data | Medium: the TRA is narrow (how often), but a customized-approach TRA *is* an argument for a control | **Maybe**, after a licence check |
| 5 | **HIPAA Security Rule** (45 CFR Part 164 Subpart C) | Risk analysis is a required implementation specification, 164.308(a)(1)(ii)(A) | US covered entities and business associates | Good, but the January 2025 proposed overhaul is not final and its timetable has slipped, so a template written now may need rewriting | **Later** |
| 6 | **IEC 62443-3-2**, **ISO/SAE 21434** (with UNECE R155) | Both *are* risk-assessment methods: zones and conduits with target security levels; TARA with attack paths | Industrial automation; vehicle manufacturers and suppliers | Methodologically close (attack paths are attacks), but sector-specific, paywalled, and SL-T and attack-feasibility ratings have no place in the ALE model | **Not now** |

**Not recommended:** NIST SP 800-53 (over a thousand controls, a catalogue
for authorisation workflow: GRC), ISO/IEC 27701 (overlaps the GDPR template),
OWASP ASVS (a verification standard with no risk-assessment requirement),
CSA Cloud Controls Matrix (a control catalogue whose licence needs reading
first), CMMC / NIST SP 800-171 (one risk-assessment requirement in a US
defence-contractor niche).

## A different kind of template

ISO/IEC 27005, NIST SP 800-30 and ANSSI's EBIOS Risk Manager are **methods**,
not control sets. A template shaped as a method walk-through (EBIOS RM's
workshops as risks and attacks, say) would serve the first job, not the third,
and is a separate question for a later research note.

## Findings: what sources say, and what is inferred

**Said by sources:**

- CRA: in force 10 Dec 2024; manufacturers' reporting duties from 11 Sep
  2026; general application 11 Dec 2027; the cybersecurity risk assessment is
  one of the essential requirements and is documented and kept current
  (European Parliament OEIL summary; Commission CRA FAQ as reported by
  Scheja & Partners, 31 Mar 2026).
- DORA RTS (EU) 2024/1774: proportionate ICT risk-management tools, methods,
  processes and policies; a risk-assessment procedure identifying threats and
  vulnerabilities to business functions and ICT assets; a simplified
  framework for small entities that still requires identifying and assessing
  ICT risk against a risk tolerance (OJ L 2024/1774 as reproduced by
  Springlex).
- NIST CSF 2.0: category ID.RA, subcategories 01–10 as summarised above
  (NIST CSWP 29). NIST publications are US federal works (17 U.S.C. § 105).
- PCI DSS v4.0.1: 12.3.1 replaced v3.2.1's annual enterprise risk assessment
  with targeted risk analyses; each is reviewed at least every 12 months
  (PCI SSC *Targeted Risk Analysis Guidance*, Nov 2023).
- HIPAA: the proposed Security Rule was published 6 Jan 2025; no final rule
  has been published; the current rule stays in effect (HHS, as reported by
  Paubox and Mondaq, 2026).
- IEC 62443-3-2:2020 defines the system under consideration, partitions it
  into zones and conduits, assesses each and sets a target security level
  (IEC webstore abstract). ISO/SAE 21434 clause 15 is the TARA; R155 makes a
  CSMS a condition of type approval (vendor summaries).

**Inferred here, not from a source:**

- The ranking itself, and that CRA and DORA matter most to this product's
  users: the existing templates are EU-weighted, and a consultant running a
  NIS2 workshop is the likeliest person to be asked about either.
- The article numbers for CRA (Art. 13, Annex I Parts I and II) and DORA
  (Art. 6, 8) are from prior reading of the regulations; EUR-Lex could not be
  fetched from this environment, so they must be checked against the
  Official Journal text before any template cites them.
- That the PCI DSS document licence permits a summarising template is
  unverified.

**Aside, not a template candidate:** the AI Omnibus (Regulation (EU)
2026/1744, as reported by National Law Review and Addleshaw Goddard) moved
the AI Act's Annex III high-risk obligations to 2 Dec 2027. The bundled EU AI
Act template names no application dates, so nothing in it is stale.

## Candidate work packages

Each is a proposal for the owner, not a plan entry.

1. **CRA template** — job 3. Smallest version: `standard-eu-cra.json` with
   Annex I Part I (security properties) and Part II (vulnerability handling)
   as defences under product-compromise and enforcement risks, a `CRA`
   framework with an `ANNEX-I.I(2)(A)`-style pattern. Success: the coverage
   view of a fresh copy lists every essential requirement, and the IoT
   archetype's defences can cite them.
2. **DORA template** — job 3. Smallest version: Chapter II (Art. 5–16) and
   the incident and third-party obligations as defences, a `DORA` framework
   with an `ART.9(4)(C)` pattern like NIS2's.
3. **NIST CSF 2.0 template** — job 3. Smallest version: the
   subcategories outside ID.RA as defences, ID.RA described in the template's
   description as what the assessment itself evidences, a `CSF2` framework
   with a `PR.AA-01` pattern.

PCI DSS and HIPAA wait on the licence check and the final rule respectively.

## Sources

- European Parliament, Legislative Observatory, *Cyber Resilience Act*
  summary: https://oeil.europarl.europa.eu/oeil/en/document-summary?id=1796083
- Regulation (EU) 2024/2847 (CRA): https://eur-lex.europa.eu/eli/reg/2024/2847/oj
- Scheja & Partners, *European Commission publishes FAQs on the Cyber
  Resilience Act* (31 Mar 2026): https://scheja-partners.de/en/2026/03/31/european-commission-publishes-faqs-on-the-cyber-resilience-act
- Regulation (EU) 2022/2554 (DORA): https://eur-lex.europa.eu/eli/reg/2022/2554/oj
- Commission Delegated Regulation (EU) 2024/1774, as reproduced by Springlex:
  https://www.springlex.eu/en/packages/dora/rts-rmf-regulation/
- NIST, *The NIST Cybersecurity Framework (CSF) 2.0*, CSWP 29:
  https://www.nist.gov/cyberframework
- PCI SSC, *PCI DSS v4.x: Targeted Risk Analysis Guidance* (Nov 2023):
  https://www.pcisecuritystandards.org/document_library/
- 45 CFR 164.308: https://www.ecfr.gov/current/title-45/subtitle-A/subchapter-C/part-164/subpart-C/section-164.308
- Paubox, *HHS pushes final HIPAA Security Rule update to 2027*:
  https://www.paubox.com/blog/hhs-pushes-final-hipaa-security-rule-update-to-2027
- IEC 62443-3-2:2020, IEC webstore: https://webstore.iec.ch/en/publication/30727
- National Law Review, *EU Digital Omnibus on AI enters into force*:
  https://natlawreview.com/article/eu-digital-omnibus-ai-enters-force
