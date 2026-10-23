# ArgueSecure 2.6.3 — two more standards, and a chart fix

> Follows [`RELEASE-2.6.2.md`](RELEASE-2.6.2.md). Tagged on the `master`
> commit that merged `PLAN-2.7.md`'s **W103** and **W105**. Like
> W102 before it, W103 was planned for 2.7.0 and ships in a patch: it adds two
> template files and no migration, so a 2.6.x instance upgrades by pulling.

---

## The headline

Two more standards templates, NIST CSF 2.0 and the EU Cyber Resilience Act,
join the seven that 2.6.2 cites controls for; and the chart view no longer cuts
off the *Add from template* and *Suggest* panels under a card that has nothing
beneath it.

## 1. NIST CSF 2.0 and EU CRA templates (W103, D67–D69)

`config/arguesecure.php`, `app/Domain/References/ControlId.php`, `database/seeders/templates/standard-{nist-csf-2,eu-cra}.json`

- **NIST CSF 2.0** (`standard-nist-csf-2`): the 106 Subcategories of all six
  Functions, as *todo* defences under three risks and fourteen threats, plus a
  cyber-insurance transfer. Each cites its control.
- **EU Cyber Resilience Act** (`standard-eu-cra`): Annex I's essential
  requirements and Art. 13–14's obligations (45) as defences under the risks of
  a compromised product, of an unhandled vulnerability and of enforcement.
- **Two new `control` frameworks** in the reference catalogue, so a defence
  can cite either by hand as well.
- Written from the source text (D69), and guarded: every identifier D68 names
  must appear exactly once in its template, and every defence title that names
  a control must cite it.

## 2. Chart panels are no longer cut off (W105)

`resources/js/Components/Chart/ChartView.vue`, `ChartCard.vue`

In the chart view, *Add from template* and *Suggest* on a card with nothing
beneath it, such as a new assessment's root, opened a panel clipped a few
pixels below the card. The scroll container now has a fixed, viewport-bounded
height, and a card scrolls its panel into view when it opens or grows. Covered
by Vitest and an end-to-end scenario.

## 3. Documentation

- README and `docs/specs/schema.md`: the two new frameworks and their codes.
- `docs/research/framework-templates.md`: why these two, and why not DORA or
  PCI DSS.
- **Images are built and published from the public repository** (decided
  2026-10-12), and the GHCR packages are public: `image.yml`'s Publish step
  runs only there, this repository still builds and boots the image on pull
  requests, and a host pulls with no login. The names and tags are unchanged.
  The README gains *Or use the prebuilt images*; `CLAUDE.md`, the release
  skill and `docs/specs/deployment.md` §8 say so.

## Upgrading from 2.6.2

With `ARGUESECURE_VERSION=2.6` in `.env`, a pull picks it up: `docker compose
pull && docker compose up -d`. There is no migration and the compose file is
unchanged.

**Templates already loaded stay as they were**, as in 2.6.2: the seeder skips
a template that exists. The two new templates are new files, but the seeder
runs only when the first administrator is created, so an existing instance
does not get them by itself. Load them with the command in
[`specs/deployment.md`](specs/deployment.md) §6:
`docker compose exec app php artisan db:seed --class='Database\Seeders\SampleContentSeeder' --force`.
It creates no users, skips every template already present and seeds the two
new ones.

## Verification behind this release

- On `master`'s head: PHP (Pint, PHPStan, Pest), JS (ESLint, vue-tsc, Vitest)
  and Security passed; the Docker build-and-boot job and the tag's Image run
  are the last gates and are reported on the release thread.

## Still open

- Everything under 2.6.2's *Still open* is unchanged.
- **W103 shipped in a patch release**, not in 2.7.0 as planned. PLAN-2.7 marks
  it ✓ (2.6.3).
