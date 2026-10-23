# ArgueSecure 2.6.3

Two more standards templates and a chart fix.

## Standards

- **NIST CSF 2.0.** All 106 Subcategories of the six Functions, as planned
  defences under risks and threats, plus a cyber-insurance transfer. Each
  cites its control.
- **EU Cyber Resilience Act.** The essential requirements of Annex I and the
  manufacturer obligations of Articles 13 and 14, as defences under the risks of
  a compromised product, an unhandled vulnerability and enforcement.
- Both are also available as control frameworks, so a defence can cite them by
  hand.

## Fixes

- **Chart panels are no longer cut off.** *Add from template* and *Suggest* on
  a card with nothing beneath it, such as a new assessment's root, opened a
  panel clipped just below the card. The card now scrolls its panel into view.

## Images

Release images are now public packages on the GitHub Container Registry. A
host pulls them without logging in; names and tags are unchanged.

## Upgrading from 2.6.2

`docker compose pull && docker compose up -d` with `ARGUESECURE_VERSION=2.6` in
`.env`. There are no database changes and the compose file is the same.

**Templates already loaded stay as they were.** The two new templates are
loaded when the first administrator is created, so an existing instance does
not get them by itself. Load them with:

```bash
docker compose exec app php artisan db:seed --class='Database\Seeders\SampleContentSeeder' --force
```

It creates no users and skips templates that already exist.
