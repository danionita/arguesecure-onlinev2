# Schema Specification

> Related: [calculation-engine.md](calculation-engine.md) consumes this schema; [security.md](security.md) specifies the authorization rules the policies enforce.

This document describes the database schema (MariaDB 11; MySQL 8.0.16+ also works), the Eloquent models over it, and the structural services that maintain its invariants.

## 1. Shape: one `nodes` table, two typed edge tables

The domain is **Target of Assessment → Risks → Attacks → Defences/Transfers**, but it is a **DAG, not a tree**: an attack may belong to several risks, and a defence to several attacks. There is no `parent_id` column. Edges live in two pivot tables.

The schema is a single `nodes` table using single-table inheritance on a `type` column, plus two explicit typed pivot tables (`risk_attack`, `attack_defence`).

### Why one table

1. **Every cross-cutting concern is a real foreign key.** Comments, revisions, tags, references, locks, snapshot diffs, undo entries and reordering all point at "a node". With separate per-type tables these would need polymorphic `nodeable_type` + `nodeable_id` pairs, and MySQL/MariaDB cannot put a foreign key on a polymorphic pair: no `ON DELETE CASCADE`, orphan rows, manual sweep jobs.
2. **The DAG needs one table.** Both edge tables reference the same table on both ends, so traversal, cycle checks and "load the whole assessment" are uniform. **Four queries load any assessment**: nodes, `risk_attack`, `attack_defence`, scale levels (the two edge queries are skipped when there are no risks or no attacks).
3. **Ordering and re-parenting are uniform.** Position lives on the edge, and both edge tables have the same shape.
4. **Frontend homogeneity.** One `Node` TypeScript interface; the tree component, inspector, lock indicator, comment thread and tag chip all take a `Node`.

### Alternatives not used

- **Closure table.** The graph has exactly three levels and typed edges, so there is no arbitrary-depth ancestor query, and a closure table would cost O(n²) maintenance per re-parent.
- **Generic `node_links` edge table.** The two edge kinds carry different payloads (`risk_attack` carries only ordering; `attack_defence` carries strength, mode, level, reduction target, rationale). A generic table would make all of those nullable and lose endpoint-type constraints.
- **Hybrid (`nodes` for identity + per-type detail tables).** It buys per-type `NOT NULL` at the cost of a join on every read and two inserts per write, for a handful of columns.

### Costs of the single-table choice and their mitigations

| Cost | Severity | Mitigation |
|---|---|---|
| About a dozen columns are meaningful for only one type and must be nullable | Medium | Named `CHECK` constraints assert wrong-type columns are `NULL`; PHP STI subclasses so application code never sees a wrong accessor |
| No per-type `NOT NULL` | Low | `is_transfer` is `NOT NULL DEFAULT 0` for all rows and ignored for non-defences |
| Every query needs `WHERE type = ?` | Low | Each STI subclass adds a booted global scope |
| Wider rows, slightly worse cache density | Negligible | Assessments are hundreds of rows, not millions |

---

## 2. Conventions

- All tables `InnoDB`, character set `utf8mb4`. The collation is `config('database.connections.*.collation')`, `utf8mb4_unicode_ci` unless `DB_COLLATION` overrides it.
- All primary keys `BIGINT UNSIGNED AUTO_INCREMENT` (`$table->id()`), except where a table states a different key (`node_tag`, `node_assignees`, `notifications`, `instance_settings`, and the framework tables).
- `timestamps` means Laravel's `timestamps()`: `created_at` and `updated_at`, both nullable `TIMESTAMP`. Columns this document types `datetime` are `DATETIME`.
- Money is `DECIMAL(20,2)`; rates are `DECIMAL(14,6)`; fractions are `DECIMAL(7,6)`; scale bounds and representatives are `DECIMAL(20,6)`.
- **PDO returns `DECIMAL` as strings.** Cast explicitly in the model `$casts` and again at graph-load time (`AssessmentGraphLoader` casts to `float`). A string never reaches the calculation engine.
- Every `CHECK` constraint is **named**; the name is the error message a developer sees.
- MariaDB 11 and MySQL 8.0.16+ both enforce `CHECK`.
- Framework tables created by Laravel's default migrations (`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`) are not documented here.

---

## 3. Tables

### 3.1 `users`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `name` | varchar(255) | no | display name |
| `email` | varchar(255) | no | UNIQUE |
| `email_verified_at` | timestamp | yes | |
| `password` | varchar(255) | no | hashed by the framework (`hashed` cast). 255 characters, so the algorithm can change without a migration. |
| `is_admin` | boolean | no | default `0` |
| `is_active` | boolean | no | default `1`. Deactivation preserves authorship attribution; deletion does not. |
| `must_change_password` | boolean | no | default `0`. Set when an administrator creates an account or resets a password; cleared automatically whenever the password changes without the flag being set in the same save (`User::booted()`). |
| `last_login_at` | datetime | yes | |
| `two_factor_secret` | text | yes | The TOTP secret, **encrypted** with the app key (the model casts it). Null unless a second factor has been enrolled. |
| `two_factor_recovery_codes` | text | yes | Encrypted JSON array of keyed hashes (HMAC-SHA256 under the app key) of the recovery codes; a used code is removed. Never the codes themselves. |
| `two_factor_confirmed_at` | timestamp | yes | Set when a code from the new secret was confirmed; **this** is what says an account has the factor (`User::hasTwoFactorEnabled()`). An enrolment started and never finished leaves no trace in the row. |
| `two_factor_last_step` | bigint unsigned | yes | The RFC 6238 time step of the last code accepted; a code is accepted only for a greater step. |
| `remember_token` | varchar(100) | yes | |
| timestamps | | | |

Indexes: `users_email_unique (email)`, `users_is_admin_index (is_admin)`.

#### `user_identities`

Links an account to an OpenID Connect identity.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `user_id` | bigint unsigned FK → `users` | no | ON DELETE CASCADE |
| `issuer` | varchar(255) | no | the OpenID Connect provider's issuer |
| `subject` | varchar(255) | no | the provider's stable `sub` for this person |
| `last_used_at` | timestamp | yes | |
| timestamps | | | |

Indexes: UNIQUE `(issuer, subject)`, UNIQUE `(user_id, issuer)` (one provider per instance, so one identity per account). The email address is only how a link is first made.

### 3.2 `scales` and `scale_levels`

Three scale **kinds** only: `impact`, `likelihood`, `strength`. **There is no severity scale**: computed results are rendered as currency, never band-mapped. (See [calculation-engine.md](calculation-engine.md), "Qualitative mapping".)

#### `scales`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `assessment_id` | bigint unsigned | yes | `NULL` = system scale. FK → `assessments.id` **ON DELETE CASCADE** |
| `kind` | enum('impact','likelihood','strength') | no | |
| `name` | varchar(120) | no | |
| `unit` | enum('currency','rate_per_year','fraction') | no | Derived from `kind` but stored: impact→currency, likelihood→rate_per_year, strength→fraction |
| `is_system` | boolean | no | default `0`. A system scale has `assessment_id = NULL` and is shared by every assessment that points at it. |
| `set_key` | varchar(64) | yes | Which system **scale set** this scale belongs to. `NULL` on every assessment-owned scale. |
| timestamps | | | |

Indexes: `scales_assessment_kind_index (assessment_id, kind)`, `scales_system_kind_index (is_system, kind)`, `scales_system_set_index (is_system, set_key)`.

An assessment starts out pointing at the three shared system scales of a set. The first time its bands are calibrated, `ScaleCalibrator` clones the system scale into an assessment-owned copy (`is_system = 0`, `assessment_id` set), repoints the assessment at it and edits the copy, so one assessment never moves another's figures. An administrator edits a system scale itself through `SystemScaleCalibrator`, which first gives every assessment still pointing at that scale its own identical copy, so no existing assessment's figures change.

#### Scale sets

A **set** is a trio of system scales (exactly one `impact`, one `likelihood`, one `strength`) sharing a `set_key`. The interface calls a set a **scale**: a named calibration of impact, likelihood and strength. A new assessment's three scale foreign keys are filled from the set chosen in the New assessment dialog (Standard unless another is chosen).

**Selection is by set and only by set** (`App\Domain\Scales\ScaleSets::resolve()`); a duplicate kind inside one set throws rather than choosing one arbitrarily.

Rules:

1. A set is **complete** only with all three kinds. Incomplete sets are never offered as a choice: an assessment's three scale foreign keys are NOT NULL, so a set missing a kind cannot start one.
2. `default` is the set every instance has. It is used when no set is chosen, and it is the set whose absence stops an assessment from being created.
3. **Level codes are shared across sets.** A calibration says what "moderate" is worth, not which words exist, and the catalogue and template fixtures reference levels by code, so a set that renamed its bands would stop resolving them.
4. Sets are seeded by `SystemScaleSeeder`, not by a migration. The seeder is idempotent and runs on every container start that has `RUN_MIGRATIONS=true`.

##### `scale_sets`

A set's three `scales` rows are found by `scales.set_key`; the set's display name lives here.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned | no | PK. |
| `key` | varchar(64) | no | Unique. The value of `scales.set_key`. There is deliberately **no foreign key**: `ScaleSets::resolve()` reads `set_key` as a plain string. |
| `name` | varchar(64) | no | Unique (case-insensitive under the table collation). What the dropdowns show. |
| `is_builtin` | boolean | no | default `0`. The two seeded scales. A built-in scale can be edited, never renamed or deleted. |
| `position` | int unsigned | no | default `0`. Order among built-ins; custom scales sort after them by name. |
| timestamps | | | |

An administrator creates a custom scale by copying a complete one (three system `scales` rows and their levels; `set_key` is a slug of the name, made unique, and never `custom`, which is the interface's own value for "no scale"). Renaming a custom scale renames its three `scales` rows too. Deleting one first gives every assessment still pointing at its rows private, identical copies (`SystemScaleCalibrator::release()`), because the assessment foreign keys are `RESTRICT`, then removes the rows; every assessment that was labelled with the key or used its rows becomes **Custom** (`scale_set_key = NULL`).

Seeded sets:

| `set_key` | Label | What it is for |
|---|---|---|
| `default` | Standard | The general-purpose calibration. |
| `small-organisation` | Small organisation | Impact shifted down two decades (0→100 … 100k→∞), likelihood boundaries lower and wider, strength more pessimistic at the top. A ten-person firm's real losses fall in the Standard set's bottom two bands, and a scale whose top bands are unreachable cannot rank the risks it is used on. |

#### `scale_levels`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `scale_id` | bigint unsigned | no | FK → `scales.id` **ON DELETE CASCADE** |
| `ordinal` | tinyint unsigned | no | 1 = lowest |
| `code` | varchar(32) | no | Machine name, e.g. `moderate`. **Internal**: derived from the label on create, frozen on rename, and shown to a user nowhere. Catalogue defaults reference levels by code, not id. |
| `label` | varchar(64) | no | display name |
| `color` | char(7) | no | `#RRGGBB`, default `#9CA3AF`. Not editable; nothing outside `ScaleCalibrator` reads it. |
| `lower_bound` | decimal(20,6) | no | **inclusive** |
| `upper_bound` | decimal(20,6) | yes | **exclusive**; `NULL` = +∞, permitted on the top band only |
| `representative` | decimal(20,6) | no | the number used in arithmetic |
| timestamps | | | |

Indexes: `scale_levels_ordinal_unique (scale_id, ordinal)`, `scale_levels_code_unique (scale_id, code)`, `scale_levels_lookup_index (scale_id, lower_bound)`.

**Invariants** are enforced by `App\Domain\Structure\ScaleValidator` on save, not by the database:

1. Ordinals are contiguous `1..n`.
2. `level[i].upper_bound == level[i+1].lower_bound` exactly. No gaps, no overlaps.
3. `level[1].lower_bound == 0`.
4. `level[n].upper_bound IS NULL`, except for `unit = 'fraction'`, where the top band closes at `1` and the lookup treats it as `[lower, 1]` inclusive.
5. `lower_bound <= representative` and (`upper_bound IS NULL` OR `representative < upper_bound`); for the closed top band of a fraction scale, `representative <= upper_bound`.

**Invariant 5 is load-bearing**: it is what makes the qualitative → numeric → qualitative round trip lossless. Without it, "moderate" could resolve to a number that bands back to "major". Unit tests (PHP and TypeScript) assert `toLevel(toNumber(level)) === level` for every level of every system scale.

**`code` is internal, derived on create, frozen on rename.** No editor asks for one and none is shown to a user. It remains the unique key within a scale, is `impact_level_code` / `aro_level_code` in the published calculation payload ([calculation-engine.md](calculation-engine.md)), and is what `CatalogInserter` and `AssessmentCloner` look a level up by.

An id cannot replace it. A custom scale is **cloned per assessment**, so the row id of "major" differs in every assessment and does not exist until the clone runs; a catalogue fixture is written once and must resolve in all of them.

- **On create**, `App\Domain\Scales\LevelCodeDeriver` slugs the label (`Very strong` → `very_strong`), prefixing `band_` where the result would not start with a letter, and resolves collisions with a `_2` suffix. The taken set is every code currently on the scale, including bands the same save removes: deleting "Minor" and adding a band called "Minor" produces `minor_2`, so the new band inherits neither the old row's stored values nor the catalogue entries that named it.
- **On rename**, nothing happens. Re-deriving would silently detach every catalogue entry naming the old value. The editor carries the code it was sent back untouched, as a value it never displays; a band the editor has just created carries `null`.

**Renumbering ordinals needs the range cleared first.** `scale_levels_ordinal_unique (scale_id, ordinal)` makes a one-row-at-a-time renumber impossible (removing the lowest band writes old-band-2 → ordinal 1 while band 1 still holds it). `App\Domain\Scales\LevelOrdinals` shifts every existing ordinal up by 100 in one statement before any final value is written, and re-syncs the in-memory models so a band whose new ordinal equals its old one is still saved.

#### System default scales (seeded, `is_system = 1`, `assessment_id = NULL`)

Standard set (`set_key = default`), band ranges lower → upper, with the stored representative:

| Kind | Unit | Bands |
|---|---|---|
| impact | currency | 0→1k (rep 250); 1k→10k (rep 3,162); 10k→100k (rep 31,623); 100k→1M (rep 316,228); 1M→∞ (rep 3,000,000) |
| likelihood | rate_per_year | 0→0.02 (rep 0.005); 0.02→0.2 (rep 0.06); 0.2→1 (rep 0.45); 1→10 (rep 3.16); 10→∞ (rep 30) |
| strength | fraction | 0→0.2 (rep 0.1); 0.2→0.5 (rep 0.35); 0.5→0.8 (rep 0.65); 0.8→0.95 (rep 0.875); 0.95→1 (rep 0.975) |

Labels are `negligible / minor / moderate / major / severe` for impact, `rare / unlikely / possible / likely / frequent` for likelihood, and `weak / limited / moderate / strong / very strong` for strength (codes are the lower-cased labels with `_` for spaces). The seeded representatives approximate the generation rule in §3.2.1.

Small organisation set (`set_key = small-organisation`), same labels and codes:

| Kind | Bands |
|---|---|
| impact | 0→100 (rep 25); 100→1k (rep 316); 1k→10k (rep 3,162); 10k→100k (rep 31,623); 100k→∞ (rep 300,000) |
| likelihood | 0→0.1 (rep 0.025); 0.1→0.5 (rep 0.224); 0.5→2 (rep 1); 2→12 (rep 4.899); 12→∞ (rep 36) |
| strength | 0→0.1 (rep 0.05); 0.1→0.3 (rep 0.2); 0.3→0.6 (rep 0.45); 0.6→0.85 (rep 0.725); 0.85→1 (rep 0.925) |

##### 3.2.1 Default representative generation

When a band is created or edited, `representative` is pre-filled by `App\Domain\Scales\RepresentativeGenerator`:

- **currency, rate_per_year**: geometric mean `√(lower × upper)`, because loss and frequency distributions are heavy-tailed and log-uniform is the right uninformative prior within a band. If `lower = 0`, use `upper / 4`. For the open top band, use `lower × 3` (`arguesecure.scales.top_band_multiplier`), since the geometric mean of `(L, ∞)` is undefined.
- **fraction**: arithmetic midpoint `(lower + upper) / 2`, which is bounded and roughly uniform. An open top band (which a fraction scale does not normally have) uses `lower + (1 − lower) / 2`.

Every generated value is editable; invariant 5 is re-validated on save.

### 3.3 `assessments`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `uuid` | char(36) | no | UNIQUE |
| `owner_user_id` | bigint unsigned | yes | FK → `users.id` **ON DELETE CASCADE**. `NULL` exactly when `is_template = 1` (CHECK `assessments_template_ownerless`); not in `$fillable`. |
| `currency` | char(3) | no | default `'EUR'`, ISO 4217. One currency per assessment; no FX. |
| `display_mode` | enum('quantitative','qualitative','mixed') | no | default `'mixed'`. Affects **input defaults and report rendering only**, never the arithmetic. |
| `impact_scale_id` | bigint unsigned | no | FK → `scales.id` **ON DELETE RESTRICT** |
| `likelihood_scale_id` | bigint unsigned | no | FK → `scales.id` **ON DELETE RESTRICT** |
| `strength_scale_id` | bigint unsigned | no | FK → `scales.id` **ON DELETE RESTRICT** |
| `scale_set_key` | varchar(64) | yes | Which scale the assessment's bands were last set to; `NULL` is **Custom**. A label, not an input to any calculation, and not a foreign key. Written at creation (and copied by the cloner) and by `AssessmentScaleApplier`, which compares the stored bands with every scale (label, bounds, representative, in order, per kind). It is never recomputed on page load, so a later administrator edit to a scale does not turn an assessment into Custom. |
| `amortization_years` | tinyint unsigned | no | default `3`; annualizes one-off defence costs. The settings form caps it at `config('arguesecure.max_amortization_years')` (10), past which the annual figure stops being checkable. |
| `risk_tolerance` | decimal(20,2) | yes | An annual-loss threshold. **Display only**: a badge on the Target of Assessment card and a line on the report chart; it never enters `RiskCalculator` and never band-maps a result. Nullable with **no default**: "not set" and "zero" are different statements. |
| `summary_narrative` | text | yes | The executive-summary text shown in the report. Not in `$fillable`; written through one controller that assigns it as a property. |
| `summary_narrative_generated` | boolean | no | default `0`. Whether `summary_narrative` was accepted from an assistant draft. Not in `$fillable`. |
| `summary_draft` | text | yes | A draft produced by the optional assistant, awaiting a person's accept or discard. Written only by a queued job; not in `$fillable`. See [../ASSISTANT.md](../ASSISTANT.md). |
| `is_public` | boolean | no | default `0`. Listed publicly and readable by anyone; read only. |
| `share_token` | char(43) | yes | UNIQUE; base64url of 32 random bytes; unlisted revocable read-only link |
| `share_token_created_at` | datetime | yes | |
| `is_template` | boolean | no | default `0`. A template has no owner. Not in `$fillable`. |
| `calc_version` | int unsigned | no | default `0`; bumped on any change affecting totals. The calculation cache key. |
| timestamps | | | |
| `deleted_at` | timestamp | yes | soft delete; a soft-deleted assessment is in the trash (§3.4, deletion semantics) |

Indexes: `assessments_uuid_unique`, `assessments_share_token_unique`, `assessments_owner_index (owner_user_id, deleted_at)`, `assessments_public_index (is_public, deleted_at)`, `assessments_template_index (is_template)`.

CHECK: `assessments_template_ownerless` is `(is_template = 1) = (owner_user_id IS NULL)`.

**The assessment has no `title` column.** Title and description live on its root node; listings join `nodes ON nodes.assessment_id = assessments.id AND nodes.type = 'root'`. One join avoids a class of staleness bugs.

### 3.4 `nodes`: the core table

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `uuid` | char(36) | no | UNIQUE. Stable identity across clones and snapshots; the client-side key. |
| `assessment_id` | bigint unsigned | no | FK → `assessments.id` **ON DELETE CASCADE** |
| `type` | enum('root','risk','attack','defence') | no | |
| `title` | varchar(255) | no | may be an empty string immediately after a keyboard insert |
| `description` | varchar(500) | yes | short summary |
| `position` | int unsigned | no | default `0`. Order of **risks under the root** and of **defences in the assessment library**. Attack order lives on `risk_attack.position`; defence order under an attack on `attack_defence.position`. |
| `impact_mode` | enum('quantitative','qualitative') | yes | **risk only** |
| `impact_amount` | decimal(20,2) | yes | **risk only**; loss **per occurrence** |
| `impact_level_id` | bigint unsigned | yes | **risk only**; FK → `scale_levels.id` **ON DELETE RESTRICT** |
| `likelihood_mode` | enum('quantitative','qualitative') | yes | **attack only** |
| `likelihood_aro` | decimal(14,6) | yes | **attack only**; occurrences per year, ≥ 0, may exceed 1 |
| `likelihood_level_id` | bigint unsigned | yes | **attack only**; FK → `scale_levels.id` **ON DELETE RESTRICT** |
| `is_transfer` | boolean | no | default `0`; meaningful for defences only |
| `status` | enum('implemented','todo','shelved') | yes | **defence only**; the `Defence` model defaults it to `'todo'` on create |
| `capex_amount` | decimal(20,2) | yes | **defence only**; one-off cost |
| `opex_annual_amount` | decimal(20,2) | yes | **defence only**; recurring annual cost |
| `created_by` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `updated_by` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| timestamps | | | `updated_at` is the optimistic-concurrency token |
| `deleted_at` | timestamp | yes | soft delete: undo and history depend on rows surviving deletion |
| `root_marker` | bigint unsigned GENERATED ALWAYS AS (CASE WHEN `type`='root' THEN `assessment_id` END) VIRTUAL | yes | UNIQUE: enforces exactly one root per assessment |

Indexes:

- `nodes_uuid_unique (uuid)`
- `nodes_root_unique (root_marker)`
- `nodes_assessment_type_index (assessment_id, type, deleted_at, position)`: the workhorse; serves the whole-assessment load
- `nodes_impact_level_index (impact_level_id)`, `nodes_likelihood_level_index (likelihood_level_id)`
- `nodes_updated_by_index (updated_by)`
- `FULLTEXT nodes_search_fulltext (title, description)`

Named CHECK constraints:

```sql
chk_nodes_risk_cols:
  type = 'risk' OR (impact_mode IS NULL AND impact_amount IS NULL AND impact_level_id IS NULL)

chk_nodes_attack_cols:
  type = 'attack' OR (likelihood_mode IS NULL AND likelihood_aro IS NULL AND likelihood_level_id IS NULL)

chk_nodes_defence_cols:
  type = 'defence' OR (status IS NULL AND capex_amount IS NULL AND opex_annual_amount IS NULL)

chk_nodes_impact_mode:
  impact_mode IS NULL
  OR (impact_mode = 'quantitative' AND impact_level_id IS NULL)
  OR (impact_mode = 'qualitative'  AND impact_amount IS NULL)

chk_nodes_likelihood_mode:
  likelihood_mode IS NULL
  OR (likelihood_mode = 'quantitative' AND likelihood_level_id IS NULL)
  OR (likelihood_mode = 'qualitative'  AND likelihood_aro IS NULL)

chk_nodes_nonneg:
  (impact_amount IS NULL OR impact_amount >= 0)
  AND (likelihood_aro IS NULL OR likelihood_aro >= 0)
  AND (capex_amount IS NULL OR capex_amount >= 0)
  AND (opex_annual_amount IS NULL OR opex_annual_amount >= 0)
```

The mode checks deliberately permit `mode` set with the value still `NULL`. That is the legitimate "user picked quantitative but has not typed a number yet" state, and the engine treats it as *missing* (see [calculation-engine.md](calculation-engine.md), "Missing values").

**Deletion semantics.** Node deletion is always soft. Deleting an assessment from the editor soft-deletes it (`deleted_at`) into the trash, from which it can be restored. Hard deletion ("Erase permanently") applies only to an assessment already in the trash, and is available to the owner and to administrators; the scheduled command `arguesecure:purge-deleted` (daily) erases assessments that have been in the trash longer than `trash_retention_days` (`TRASH_RETENTION_DAYS`, default 30, minimum 1). Templates are deleted permanently from the Templates page. When a hard delete fires, pivots, comments, revisions, tags and references cascade away; history survives soft deletes, and a hard delete is a genuine purge.

Every hard delete goes through `App\Domain\Retention\AssessmentEraser`, never a bare `DELETE`, for reasons the cascade alone does not cover:

- An assessment with its own calibrated scales both owns them (cascade) and references them (`RESTRICT`), and MariaDB refuses that `DELETE` with error 1451. The eraser first points the assessment's three `*_scale_id` columns at a system scale per kind (preferring the `default` set); this runs only when the assessment owns scales.
- `node_revisions` rows go with the assessment (`assessment_id` cascades) rather than being left with a null `node_id`.
- Notifications naming the assessment (`data.assessment_uuid`) are deleted with it.

### 3.5 `risk_attack`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `risk_node_id` | bigint unsigned | no | FK → `nodes.id` **ON DELETE CASCADE** |
| `attack_node_id` | bigint unsigned | no | FK → `nodes.id` **ON DELETE CASCADE** |
| `position` | int unsigned | no | default `0`: order of this attack under this risk |
| timestamps | | | |

Indexes: `risk_attack_unique (risk_node_id, attack_node_id)`, `risk_attack_reverse_index (attack_node_id, risk_node_id)`, `risk_attack_order_index (risk_node_id, position)`.

Application invariants, enforced in `LinkService` and asserted in tests (not database-enforceable): both endpoints share an `assessment_id`; `risk_node_id` has `type = 'risk'`; `attack_node_id` has `type = 'attack'`.

### 3.6 `attack_defence`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `attack_node_id` | bigint unsigned | no | FK → `nodes.id` **ON DELETE CASCADE** |
| `defence_node_id` | bigint unsigned | no | FK → `nodes.id` **ON DELETE CASCADE** |
| `position` | int unsigned | no | default `0` |
| `strength_mode` | enum('quantitative','qualitative') | yes | `NULL` = strength not yet stated |
| `strength_fraction` | decimal(7,6) | yes | `[0,1]`; 0.8 = 80% reduction |
| `strength_level_id` | bigint unsigned | yes | FK → `scale_levels.id` **ON DELETE RESTRICT** |
| `reduction_target` | enum('likelihood','impact') | yes | **`NULL` = combined loss, and `NULL` is the default** |
| `rationale` | varchar(500) | yes | why this strength; matters for defensibility in a client report |
| timestamps | | | |

Indexes: `attack_defence_unique (attack_node_id, defence_node_id)`, `attack_defence_reverse_index (defence_node_id, attack_node_id)`, `attack_defence_order_index (attack_node_id, position)`.

CHECKs:

```sql
chk_ad_strength_mode:
  strength_mode IS NULL
  OR (strength_mode = 'quantitative' AND strength_level_id IS NULL)
  OR (strength_mode = 'qualitative'  AND strength_fraction IS NULL)

chk_ad_fraction:
  strength_fraction IS NULL OR (strength_fraction >= 0 AND strength_fraction <= 1)
```

**Strength lives on the pivot, not on the defence**, because the same control may be 80% effective against one attack and 20% against another.

**Application default on link creation** (`LinkService`): `reduction_target = 'impact'` if the defence has `is_transfer = 1`, else `NULL`. Stored explicitly and user-overridable. Under multiplicative composition this affects displayed intermediates only, never the total (see [calculation-engine.md](calculation-engine.md), "Reduction targets").

### 3.7 `assessment_user`: sharing

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `assessment_id` | bigint unsigned | no | FK → `assessments.id` **ON DELETE CASCADE** |
| `user_id` | bigint unsigned | no | FK → `users.id` **ON DELETE CASCADE** |
| `role` | enum('viewer','commenter','editor','manager') | no | |
| `invited_by` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `invited_at` | datetime | no | |
| `accepted_at` | datetime | yes | |
| timestamps | | | |

Indexes: `assessment_user_unique (assessment_id, user_id)`, `assessment_user_user_index (user_id, accepted_at)`.

Role ladder: `viewer` (read) < `commenter` (read + comment) < `editor` (read/write nodes, links, values) < `manager` (editor + share, rename, configure scales, snapshot, soft-delete). The **owner** is `assessments.owner_user_id`, not a row here; only the owner or an administrator transfers ownership or erases permanently.

### 3.8 `comments`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `assessment_id` | bigint unsigned | no | FK → `assessments.id` **ON DELETE CASCADE**. Denormalized for channel scoping and authorization without a join. |
| `node_id` | bigint unsigned | no | FK → `nodes.id` **ON DELETE CASCADE** |
| `parent_comment_id` | bigint unsigned | yes | FK → `comments.id` **ON DELETE CASCADE** |
| `user_id` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `body` | text | no | |
| `resolved_at` | datetime | yes | |
| `resolved_by` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| timestamps | | | |
| `deleted_at` | timestamp | yes | |

Indexes: `comments_node_index (node_id, created_at)`, `comments_thread_index (parent_comment_id, created_at)`, `comments_assessment_index (assessment_id, created_at)`.

**Threading depth is exactly two levels**: a top-level comment plus replies. `parent_comment_id` must reference a comment whose own `parent_comment_id IS NULL`, on the same node and assessment. Enforced in `CommentService`.

Comments attach to **nodes only, not to links**.

### 3.9 `node_revisions`: change history

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `assessment_id` | bigint unsigned | no | FK → `assessments.id` **ON DELETE CASCADE** |
| `node_id` | bigint unsigned | yes | FK → `nodes.id` **ON DELETE SET NULL**: history outlives a purge |
| `node_uuid` | char(36) | no | denormalized so history stays readable after a purge |
| `user_id` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `action` | enum('create','update','delete','restore','move','link','unlink','link_update') | no | |
| `changes` | json | no | `{"field": {"from": x, "to": y}, …}`; for link actions `{"other_uuid":…, "other_title":…, "pivot": {…}}`; for assessment settings `{"scope": "assessment", "fields": {…}}` |
| `operation_id` | bigint unsigned | yes | FK → `assessment_operations.id` **ON DELETE SET NULL**: groups revisions written by one user action |
| `created_at` | datetime | no | no `updated_at`; revisions are immutable **except inside the coalescing window** below |

Indexes: `node_revisions_node_index (node_id, id)`, `node_revisions_assessment_index (assessment_id, id)`, `node_revisions_operation_index (operation_id)`.

**What is recorded.** `RevisionRecorder` writes `action = 'update'` and `action = 'link_update'`. The other six enum values are reserved: structural changes (create, delete, move, link, unlink) are recorded in `assessment_operations` (§3.10), and `TimelineReader` merges the two logs into one timeline.

| Mutation | Recorded in `node_revisions`? | Notes |
|---|---|---|
| Node field edit | yes, one row per save | |
| Node create / delete / move / link / unlink | no; in `assessment_operations` only | |
| Strength, reduction target, rationale on `attack_defence` | yes: `action = 'link_update'`, coalesced per user per edge | strength is a direct multiplicand in `ALE = ARO × Impact × Π(1 − strength)`; recording it keeps a past total reconstructable |
| Labels (`node_tag`) | yes, recorded in `TagService` | the editor's filter selects on them |
| Assignees (`node_assignees`) | yes | |
| `currency`, `amortization_years`, `risk_tolerance` | yes: `{"scope": "assessment", "fields": {…}}` on the **root node** | amortisation divides every annual cost in the report |
| Scale recalibration | no | not recorded |

**Coalescing.** The link strength editor saves on input, so typing `0.85` into a strength field is four PATCH requests. Further `link_update` edits by the **same user** to the **same edge** within **120 seconds** fold into the open revision: the earliest `from` is kept, the latest `to` wins, a field edited back to its starting value drops out, and a row emptied that way is deleted. This is the one place a revision row is written twice, and the immutability rule above is qualified to exactly this window. The window is per user on purpose: two people moving the same number a minute apart is the collision a history exists to show. Link-revision coalescing never folds an edit into a row older than the latest saved version (§3.13).

**Assessment-scope revisions.** A settings revision hangs off the **root node** (the row that already carries the assessment's title and description) and is marked `{"scope": "assessment", "fields": {…}}`. The marker sits beside the fields rather than among them, so a setting is never mistaken for a node column of the same name. `TimelineReader` renders the subject as "the assessment", and `RevisionReverter` refuses the scope explicitly. Title and description are not among the tracked setting fields: they are edited on the root node itself and recorded by the ordinary node path. A settings save that also renames the assessment writes two rows, one per subject, because a settings edit is not a structural change (§3.10).

**Restoring a version.** `SnapshotRestorer` applies a frozen graph back **in place**, matched by uuid so comments and labels survive on every node that still exists, with a safety snapshot of the current state taken first in the same transaction. The operation's inverse points at that safety snapshot, so undoing a restore is another restore. Every revision it writes is stamped with the operation id through `RevisionRecorder::withinOperation()`, so many node writes read as one timeline entry.

**Not part of a snapshot.** Comments are a conversation rather than a version of the work, and a node matched by uuid keeps its own either way.

### 3.10 `assessment_operations`: the undo stack

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `assessment_id` | bigint unsigned | no | FK → `assessments.id` **ON DELETE CASCADE** |
| `user_id` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `kind` | varchar(64) | no | one of `node.create`, `node.delete`, `node.move`, `link.create`, `link.delete`, `subtree.clone`, `catalog.insert`, `snapshot.restore` (a fixed list; `UndoService::record()` rejects anything else) |
| `summary` | varchar(255) | no | human-readable: `Deleted attack 'Phishing'` |
| `inverse` | json | no | a serialized command that undoes it |
| `undone_at` | datetime(6) | yes | microsecond precision: redo orders by it |
| `undone_by` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `redone_at` | datetime | yes | set when a redo consumes this row; it stays undone |
| `superseded_at` | datetime | yes | set on every undone row when a new operation is recorded; it stays undone but can no longer be redone |
| `created_at` | datetime | no | |

Index: `assessment_operations_stack_index (assessment_id, undone_at, id)`.

**Undo semantics**

- **One linear stack per assessment, not per user.** Per-user undo in a live multi-user editor produces incoherent history.
- Only the newest not-yet-undone operation is undoable. Any editor may undo it, but the interface names the author: *"Undo Dana's deletion of 'Phishing'?"*
- Undoing applies the stored `inverse`, stamps `undone_at` and `undone_by`, and replaces `inverse` with the command that would redo it.
- Redo re-applies that command and pushes the result as a **new** operation. The row it consumed keeps `undone_at` and gets `redone_at`, which takes it off both stacks (undo skips it because it is undone, redo because it is redone). Redo takes the most recently undone row, which is why `undone_at` keeps microseconds: at one-second precision two undos in one second would tie, and no tiebreak on `id` is right for both undo, undo, redo and undo, redo, undo.
- A new operation ends the redo stack: `UndoService::record()` stamps `superseded_at` on every row that is undone and not yet redone, and `redo()` skips stamped rows. Redo's own pushed row does not stamp, so undo, undo, redo, redo works. It is a column because it cannot be derived: redo pushes a row, which would otherwise count as newer work.
- Every applier resolves the uuids in a command inside the operation's own assessment (`CommandApplier::apply($command, $assessmentId)`); a uuid from another assessment reads as a node that does not exist. The `inverse` is written only by server code, so this is defence in depth.
- **If the inverse cannot be applied cleanly** (a referenced node was hard-deleted, or a field has since been edited by someone else) the operation is refused with an explicit message rather than partially applied. Undo and redo each run in one transaction.
- Only structural changes enter the stack. Field edits are recovered through `node_revisions` ("revert this field"), which is recorded as a normal edit, not an undo.

### 3.10a `security_events`: the stored security log

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `created_at` | datetime | no | UTC; indexed |
| `level` | varchar(16) | no | `info` or `warning` |
| `event` | varchar(64) | no | a `SecurityEvent` value |
| `actor_id` | bigint unsigned | yes | **no FK**: a row outlives the account |
| `actor_email` | varchar(254) | yes | |
| `target` | json | yes | `{type, id, email?}` as in security.md |
| `ip` | varchar(45) | yes | |
| `details` | json | yes | already redacted |

Indexes: `(created_at)`, `(event, created_at)`. Written only by the `security` log channel; deleted only by `arguesecure:purge-security-events` (daily) after `SECURITY_LOG_RETENTION_DAYS` (default 90, minimum 1).

### 3.11 `tags` and `node_tag`

`tags`: `id`; `assessment_id` bigint unsigned NOT NULL FK → `assessments.id` **ON DELETE CASCADE**; `name` varchar(60) NOT NULL; `slug` varchar(60) NOT NULL; `color` char(7) NOT NULL default `#3B82F6`; timestamps. Unique `tags_slug_unique (assessment_id, slug)`.

`node_tag`: `node_id` + `tag_id`, composite primary key `(node_id, tag_id)`, both FK **ON DELETE CASCADE**, plus `node_tag_reverse_index (tag_id, node_id)`.

**Created by being used.** There is no vocabulary-management screen: naming a label on a node creates it for that assessment, and naming one whose **slug** already exists reuses it. The slug is `Str::slug(name)`, falling back to the lower-cased name when transliteration yields nothing (a name with no Latin characters would otherwise slug to the empty string and collide with every other such name through the unique index). The first spelling wins the display name; resolving a label never renames an existing tag.

**`node_tag` is cloned with the assessment.** `AssessmentCloner` copies both the tag vocabulary and the attachments.

**Tags are assessment-scoped.** Scoping to the assessment makes colours meaningful (a client's own taxonomy), makes cloning trivial and makes deletion a cascade; one user's rename never affects another assessment.

### 3.11a `node_assignees`: who is responsible

`node_assignees`: `node_id` + `user_id`, composite primary key `(node_id, user_id)`, both FK **ON DELETE CASCADE**, plus `node_assignees_user_index (user_id, node_id)`.

**No role, no timestamps, no `assigned_by`.** An assignment grants nothing: every ability still comes from the assignee's collaborator role through `AssessmentPolicy`, and there is no node-scoped grant anywhere in the codebase. A role column here would create one by implication.

**The reverse index serves the dashboard.** The composite key answers "who is on this node?"; `node_assignees_user_index` answers "what is assigned to me?", which runs on every sign-in.

**Removing a collaborator removes their assignments** (`AssessmentUser`'s `deleted` model hook, not a constraint; the foreign keys cascade on a deleted *node* or *account*, which is a different event). Otherwise a row would name someone on a node they can no longer open. A **role change** deliberately does not fire the hook: a viewer can still be the person responsible.

**An assignee must already be a collaborator** (the owner or a member). Naming anyone else is refused with 422 unless the caller holds `manage`, because adding a person to an assessment is a `manage` action even when reached through the assign route (`AssignmentController`).

### 3.11b `notifications`: the in-app feed

Laravel's own `notifications` table (UUID primary key, `type`, `notifiable` morph, `data` text, `read_at`, timestamps), with one added index: `notifications_unread_index (notifiable_type, notifiable_id, read_at)`. The unread badge is a shared Inertia prop, so its count runs on every authenticated request. The sources are a mention in a comment (`MentionedInComment`), a node assigned to you (`NodeAssigned`) and a drafted summary (`SummaryDrafted`); they differ only in `type` and the contents of `data`.

**`data` is denormalised on purpose.** A notification records what was true when it was sent; the excerpt is the comment as it read at the moment of the mention. `data.assessment_uuid` ties a notification to its assessment (erasing the assessment deletes them), and `data.comment_id` makes an edit re-parse safe: someone already told about a comment is not told again, while someone the edit newly names is.

**Display names only, never addresses.** A feed shows other people's activity, which is where an address would leak.

**Mentions match against collaborators only**, never against all `users`; see [security.md](security.md).

**In-app only; there is no email notification.**

### 3.12 `node_references`: CVE and friends

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `node_id` | bigint unsigned | no | FK → `nodes.id` **ON DELETE CASCADE** |
| `kind` | enum('cve','cwe','capec','attack_technique','control','url','other') | no | `control` is accepted on a defence only |
| `identifier` | varchar(64) | yes | required for every kind except `url`; trimmed and upper-cased (a `url` is only trimmed) |
| `url` | varchar(2048) | yes | required for `url` and `other`; derived for the rest |
| `title` | varchar(255) | yes | user-entered label. **No live API calls**: nothing is fetched. |
| `position` | int unsigned | no | default `0` |
| timestamps | | | |

Indexes: `node_references_unique (node_id, kind, identifier)`, `node_references_identifier_index (kind, identifier)`. The latter answers "which assessments mention CVE-2024-3094?".

Validation patterns and link templates live in `config/arguesecure.php` (`references`) and `App\Domain\References\ReferenceValidator`:

| Kind | Pattern | Link |
|---|---|---|
| `cve` | `/^CVE-\d{4}-\d{4,}$/` | `https://nvd.nist.gov/vuln/detail/{id}` |
| `cwe` | `/^CWE-\d{1,5}$/` | `https://cwe.mitre.org/data/definitions/{n}.html` |
| `capec` | `/^CAPEC-\d{1,4}$/` | `https://capec.mitre.org/data/definitions/{n}.html` |
| `attack_technique` | `/^T\d{4}(\.\d{3})?$/` | `https://attack.mitre.org/techniques/{T}/{sub}/` |
| `control` | `FRAMEWORK:CONTROL`; the framework from `references.frameworks`, the control part against that framework's own pattern | the framework's page, never the control's |
| `url` | must match `#^https?://#i` | rendered with `rel="noopener nofollow"` |
| `other` | any non-blank identifier, plus a URL matching the `url` pattern | rendered with `rel="noopener nofollow"` |

CVE is the primary kind (`references.primary_kind`) and the others sit behind a "more" affordance, except on a defence, where the form opens on **Control**.

**`control`** cites the framework control a defence implements: `ISO27001-2022:A.5.23`, `ISO42001-2023:A.6.2.2`, `EUAIACT:ART.9`, `GDPR:ART.32`, `NIS2:ART.21(2)(D)`, `SOC2:CC6.1`, `CIS8:4.1`, `CSF2:PR.AA-01`, `CRA:ANNEX.I.I(2)(A)`. The list of frameworks is closed and lives in config; a code is never renamed once an assessment may cite it. Identifiers are upper-cased, so `Art.32` and `ART.32` are one citation; the report prints `Art. 32`, and `ANNEX.I.I(2)(A)` as `Annex I Part I(2)(a)`. A `control` reference on a risk, an attack or the root is refused with 422, because the coverage view reads defences only. The link goes to the framework because the standards' text is not free to deep-link. The bundled standards templates cite controls on their defences.

### 3.13 `snapshots`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `assessment_id` | bigint unsigned | no | FK → `assessments.id` **ON DELETE CASCADE** |
| `name` | varchar(120) | no | |
| `note` | varchar(500) | yes | |
| `kind` | varchar(16) | no | default `manual`; `auto` (saved by `AutoVersioner`), `safety` (taken by a restore) |
| `created_by` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `graph` | longtext | no | base64 of the gzipped canonical JSON graph (`SnapshotGraphCodec`): assessment currency and amortisation years, root title and description, nodes keyed by `uuid` (title, description, position, values, **labels and assignees inlined**), both pivots, and the **scales and levels inlined** |
| `results` | json | no | the frozen `CalculationResult` for all three baselines |
| `schema_version` | smallint unsigned | no | for forward-compatible readers. Current value **2**; version 1 snapshots (no labels or assignees) are still readable. |
| `created_at` | datetime | no | |

Index: `snapshots_assessment_index (assessment_id, created_at)`.

**Presented as versions.** The History page is built from this table: each snapshot is a numbered *version*, holding the history-log entries written between the previous one and itself (`VersionTimeline`), with unsaved entries under "Current version". `AutoVersioner` (scheduled every five minutes by `arguesecure:save-versions`) saves an `auto` version for any assessment whose latest change is older than 30 minutes (`IDLE_MINUTES`), or that has 50 changes (`MAX_CHANGES`) since its last version; nothing is saved for an assessment with no changes.

**Snapshots inline the scales** so that recalibrating a scale later cannot rewrite history, and `results` is frozen so reports are reproducible. **Restoring one** applies `graph` back in place; `results` is frozen output and is never replayed into the live assessment. `schema_version` is checked before anything is written and a version the code does not know is refused outright (`SnapshotRestorer::READABLE_VERSIONS` is a list, not a floor, so a shape with something removed from it cannot be half-applied). Diffing two snapshots is pure PHP: match nodes by `uuid`, produce added/removed/changed field sets and added/removed edges, and diff the `results` totals. **Snapshot reports never recompute.**

#### `graph` v2: labels and assignees

Each node entry carries two keys:

```json
"tags":      [{"name": "PCI scope", "slug": "pci-scope", "color": "#3B82F6"}],
"assignees": [{"id": 42, "name": "Dana Okonkwo"}]
```

Four properties hold, asserted in `tests/Feature/Snapshots/SnapshotLabelsTest.php`:

- **Labels are stored by value, matched by slug.** An id left pointing into `tags` would make a frozen version follow a later rename or deletion. `slug` is the assessment-scoped stable name (§3.11); `name` and `color` are carried so the label renders as it looked. A restore reuses the live tag with that slug, or recreates it from the frozen values if it has been deleted.
- **Assignees store `id` and `name`, never an email.** A snapshot is readable by anyone holding `view`, which a share token grants with no account at all, so an email here would be disclosed to that audience for as long as the snapshot exists. The id is what a restore re-attaches by; the name lets the snapshot still say who it was after the account is gone. The guard is the serializer that names exactly those two keys; narrowing the eager-load to `assignees:id,name` is a second line.
- **A restore replaces, it does not merge.** Both sets are `sync()`ed, so a label added since the snapshot comes off. The exception is a **v1** snapshot: it says nothing about labels, so syncing it to an empty set would turn silence into a deletion. A v1 restore leaves labels and assignees alone and reports the count it could not restore.
- **A frozen assignment is re-checked, never just re-attached.** `AssessmentUser`'s `deleted` hook removes assignments when someone loses access. Assignments naming someone who is no longer a collaborator, or whose account is gone, are dropped and **counted** in the restore message.

No migration rewrites an existing snapshot; v1 is a shape the restorer reads permanently.

### 3.14 Templates and catalogue

Two mechanisms.

**(a) Template assessments.** `assessments.is_template = 1`, with `owner_user_id = NULL`. A template is nobody's assessment: only administrators reach it through the assessment routes (`AssessmentPolicy::before()`), and the Templates page is the only place one is created, edited (in the ordinary editor) or deleted (permanently). "Publish as template" copies an assessment through `AssessmentCloner::cloneAsTemplate()` into a new template and writes nothing to the original; there is no link back and no unpublish. "New from template" runs `AssessmentCloner::cloneAssessment()`, which also clones assessment-owned scales, tags, `node_tag` and `node_references` (§3.12) and remaps every `uuid`. A clone copies `currency`, `display_mode`, `amortization_years` and `scale_set_key`; it starts private (`is_public = 0`) with `calc_version = 0`, and does not copy risk tolerance, summary text, assignees, comments or history.

Inserting a catalogue entry copies no references: a `catalog_entries` row has none of its own.

**(b) `catalog_entries`**: a flat library of standard risks, attacks and defences to drag in.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `kind` | enum('risk','attack','defence') | no | |
| `title` | varchar(255) | no | |
| `description` | varchar(500) | yes | |
| `defaults` | json | yes | e.g. `{"likelihood_mode":"qualitative","likelihood_level_code":"possible"}` |
| `owner_user_id` | bigint unsigned | yes | `NULL` = system catalogue. FK → `users.id` **ON DELETE CASCADE** |
| `is_public` | boolean | no | default `0` |
| timestamps | | | |

An entry is visible to a user when it is in the system catalogue (`owner_user_id IS NULL`), is marked `is_public`, or is the user's own (`CatalogSearch`).

`catalog_entry_links`: `parent_entry_id`, `child_entry_id`, `defaults` json (pivot defaults such as a default strength level code), timestamps, both FK **ON DELETE CASCADE**, unique `catalog_entry_links_unique (parent_entry_id, child_entry_id)`. This lets an entry be a **bundle**: a "Phishing" entry shipping with MFA, awareness training and mail filtering pre-linked at default strengths.

**`defaults` store level *codes*, never level ids** (`impact_level_code`, `likelihood_level_code`, and the strength code in a link's `defaults`). That lets a catalogue entry insert correctly into any assessment regardless of its calibration: `CatalogInserter` resolves the code against the target assessment's scales at insert time. A code that does not resolve fails the insert with a `CatalogInsertionException` rather than falling back to a default or leaving a value silently unset.

Inserting is a **copy**. Later edits to a catalogue entry do not propagate into assessments already built from it.

### 3.15 Locking: deliberately no table

Field-level pessimistic locks live only in Redis (`App\Domain\Locking\LockManager`, `LockController`):

- Key `lock:{assessment_uuid}:{node_uuid}:{field}`, where `field` is one of `node` (the whole node, held while the node modal is open), `title`, `description`, `impact_amount`, `impact_level_id`, `likelihood_aro`, `likelihood_level_id`, `status`, `capex_amount`, `opex_annual_amount`. Any other field is refused with 422.
- Value: JSON `{"user_id":…, "name":…}`.
- Acquire with `SET key value NX PX 45000` (`POST …/locks/{node}/{field}`). On failure, the current holder is reported with 409.
- Refresh with a compare-and-extend Lua script (`PATCH`), driven by the client every 15 seconds while the field is focused.
- Release with a compare-and-delete Lua script (`DELETE`) on blur, save and navigation.
- All lock state changes go over authenticated HTTP, so the identity compared against comes from the server-side session. Reverb whispers are used only to make the "locked by …" badge appear immediately for other viewers; a forged whisper can mislead a badge but never grants or denies the real lock.
- Writes to a node whose `node` lock is held by someone else are refused (`NodeController::update`); the holder's own writes pass, including from other tabs.
- **The TTL is the sweep.** There is no sweep job, no `locked`/`lock_time` column and no polling endpoint.

**Locks are cooperative UX, not the correctness guarantee.** Every node PATCH carries the node's `updated_at`; a mismatch returns **409** with the current server state. That version check is what prevents lost updates.

### 3.16 `instance_settings`

A deliberate **singleton row**, not a key/value table: there is exactly one instance of the application, so one row with a `CHECK` pinning its id gives every setting a real, typed column. It backs `App\Domain\Accounts\InstanceSettings` (model `App\Models\InstanceSetting`). Durable configuration lives in the database rather than a cache store, so a cache flush cannot revert an instance to defaults.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | always `1`, enforced by `chk_instance_settings_singleton`. Deliberately **not** `AUTO_INCREMENT`: MariaDB rejects a CHECK constraint that references one, so `InstanceSetting` sets it explicitly. |
| `default_currency` | char(3) | no | default `'EUR'`, ISO 4217; pre-fills `assessments.currency` on creation |
| `public_assessments_allowed` | boolean | no | default `1`; enforced server-side in `ShareController::updatePublic()`, not only reflected in the admin UI |
| `assistant_enabled` | boolean | no | default `0`. The administrator-controlled half of the assistant switch; the deployment half is `config('assistant.enabled')` and is not a column. Off until an administrator turns it on. |
| `assistant_help_enabled` | boolean | no | default `1` |
| `assistant_suggestions_enabled` | boolean | no | default `1` |
| `assistant_summary_enabled` | boolean | no | default `1`. One switch per assistant feature, each subordinate to the deployment's availability and able only to subtract from it: no value here makes a feature work where the deployment runs no inference runtime (`UpdateAssistantSettingsRequest` rejects enabling with `declined`). They default on because they are read only after `assistant_enabled` has been switched on. |
| `session_lifetime_minutes` | int unsigned | no | default `480`; read by `App\Http\Middleware\ApplyInstanceSessionLifetime` and applied to `config('session.lifetime')` before the session starts |
| `require_two_factor` | boolean | no | default `0`. On: an account with no second factor is sent to enrolment after its password. |
| `sso_only` | boolean | no | default `0`. On, and with a provider configured, non-administrators cannot sign in with a password. |
| `oidc_issuer`, `oidc_client_id`, `oidc_button_label`, `oidc_allowed_domains` | varchar(255), varchar(255), varchar(100), text | yes | The OpenID Connect provider as stored on the Authentication tab; null means not set here. A same-named `OIDC_*` environment value wins (`OidcConfig`). Domains are lower-case, comma-separated. |
| `oidc_client_secret` | text | yes | The client secret under the `encrypted` cast (`APP_KEY`), in `$hidden`, never returned to a page. |
| `oidc_trust_email` | boolean | no | default `0` |
| timestamps | | | |

Named CHECK:

```sql
chk_instance_settings_singleton:
  id = 1
```

The scale a new assessment starts from is not an instance setting; it is chosen per assessment in the New assessment dialog (§3.2). There is no `registration_locked` setting: accounts are administrator-created, so there is no registration to lock (see [security.md](security.md)).

---

## 4. Eloquent models

```
Node (base, table `nodes`, SoftDeletes)
├── RootNode   global scope type='root'
├── Risk       global scope type='risk'
├── Attack     global scope type='attack'
└── Defence    global scope type='defence'
```

**STI wiring.** `Node::newFromBuilder()` inspects `$attributes['type']` and instantiates the mapped subclass. Each subclass declares `const TYPE` and adds both a booted global scope on `type` and a `creating` hook that sets it (`Defence` also defaults `status` to `todo`). `Node` itself carries **no** type scope, so polymorphic relations (a comment's node, a revision's node) resolve to the correct subclass automatically. `Node::creating` assigns a `uuid` when none is set.

### Relationships

- **`Node`**: `assessment()` belongsTo; `createdBy()`/`updatedBy()` belongsTo `User`; `comments()` hasMany (top-level via `whereNull('parent_comment_id')`); `revisions()` hasMany ordered by id descending; `tags()` belongsToMany through `node_tag`; `references()` hasMany `NodeReference` ordered by `position`; `assignees()` belongsToMany `User` through `node_assignees`, ordered by name.
- **`RootNode`**: `risks()` is `hasMany(Risk::class, 'assessment_id', 'assessment_id')->orderBy('position')`. Risks attach by `assessment_id` rather than a parent pointer, because a risk belongs to exactly one assessment and there is exactly one root per assessment.
- **`Risk`**: `attacks()` is `belongsToMany(Attack::class, 'risk_attack', 'risk_node_id', 'attack_node_id')->using(RiskAttack::class)->withPivot('id','position')->withTimestamps()->orderBy('risk_attack.position')`; `impactLevel()` belongsTo `ScaleLevel`.
- **`Attack`**: `risks()` the inverse belongsToMany; `defences()` is `belongsToMany(Defence::class,'attack_defence','attack_node_id','defence_node_id')->using(AttackDefence::class)->withPivot('id','position','strength_mode','strength_fraction','strength_level_id','reduction_target','rationale')->withTimestamps()->orderBy('attack_defence.position')`; `likelihoodLevel()` belongsTo `ScaleLevel`.
- **`Defence`**: `attacks()` the inverse belongsToMany with the same pivot.
- **Pivot models** `RiskAttack` and `AttackDefence` extend `Pivot` with `$incrementing = true`. `AttackDefence` exposes `strengthFraction(): ?float` (resolving a qualitative level to its `representative`) and `effectiveTarget(): ?string`.
- **`Assessment`**: `rootNode()` hasOne `RootNode`; `nodes()` hasMany `Node`; `risks()`/`attacks()`/`defences()` hasMany of the subclass, ordered by `position`; `owner()` belongsTo `User`; `members()` belongsToMany `User` (through `AssessmentUser`) with pivot `role`, `invited_by`, `invited_at`, `accepted_at`; `impactScale()`/`likelihoodScale()`/`strengthScale()` belongsTo `Scale`; `tags()`, `snapshots()`, `operations()`, `comments()` hasMany. Accessors `title` and `description` proxy the root node.
- **`Scale`** hasMany `ScaleLevel` ordered by `ordinal`; **`ScaleLevel`** belongsTo `Scale`; **`ScaleSet`** is a plain model over `scale_sets`.
- **`Comment`** belongsTo `Node`, `Assessment` and `User`; `parent()`/`replies()` self-referential; `resolvedBy()`.
- **`User`**: `ownedAssessments()`, `sharedAssessments()`, `identities()`, `is_admin`, `hasTwoFactorEnabled()`.
- Plus `NodeRevision`, `Snapshot`, `Tag`, `NodeReference`, `AssessmentOperation`, `SecurityEventRecord`, `UserIdentity`, `InstanceSetting`, and `CatalogEntry` (with `catalogChildren()`/`catalogParents()`).

### Domain services under `app/Domain/`

| Class | Responsibility |
|---|---|
| `Graph\AssessmentGraphLoader` | Up to 4 queries → an immutable `AssessmentGraph` DTO |
| `Graph\AssessmentGraph` | Plain PHP values, no Eloquent, serializable. **The only input the calculation engine accepts.** |
| `Structure\AssessmentCreator` | Create an assessment (or template) with its root node and the three scales of a set |
| `Structure\LinkService` | Create/remove edges with endpoint-type and same-assessment validation; cycle-free by construction |
| `Structure\ReorderService` | Re-sequence siblings inside a transaction |
| `Structure\AssessmentCloner` | Clone an assessment (or as a template) or a subtree |
| `Structure\ScaleValidator` | Enforces the §3.2 invariants on save |
| `Catalog\CatalogInserter` | Insert a catalogue entry or bundle, resolving level codes against the target |
| `Scales\ScaleCalibrator`, `Scales\SystemScaleCalibrator`, `Scales\ScaleSetManager`, `Scales\AssessmentScaleApplier` | Calibrate an assessment's scales, edit system scales, manage scale sets, apply a set to an assessment |
| `History\RevisionRecorder` | Model observer writing `node_revisions`, grouped by `operation_id` |
| `History\UndoService` | Push/pop `assessment_operations` |
| `History\TimelineReader`, `History\VersionTimeline`, `History\RevisionReverter` | Merge both logs into a timeline, group it into versions, revert a field |
| `Snapshots\SnapshotService`, `Snapshots\SnapshotRestorer`, `Snapshots\SnapshotDiffer`, `Snapshots\AutoVersioner` | Create, restore, diff and automatically save versions |
| `Retention\AssessmentEraser`, `Retention\TrashPurger` | Hard-delete trashed assessments |
| `Locking\LockManager` | Redis field locks (§3.15) |

Calculation services are specified separately in [calculation-engine.md](calculation-engine.md).

---

## 5. Authorization

`AssessmentPolicy` is the single source of truth. Abilities on an assessment: `view`, `comment`, `update`, `manage`, `delete`, plus `transferOwnership` and `forceDelete` (owner or administrator), and the template abilities `createTemplate`, `saveAsTemplate` (administrators) and `useAsTemplate` (any signed-in user, on a template). Resolution order for the first five: `user.is_admin` → owner → `assessment_user.role` → `is_public` (`view` only) → a valid share token held in the session (`view` only). Roles grant `viewer`: view; `commenter`: view, comment; `editor`: those plus update; `manager`: those plus manage and delete.

For a template assessment, `AssessmentPolicy::before()` short-circuits: every ability except `useAsTemplate` is granted to administrators only, and `transferOwnership` is denied to everyone.

`NodePolicy` delegates `view`, `comment` and `update` to the owning assessment, and `delete` to the assessment's `update`. `CommentPolicy` delegates `view` and `create` the same way; a comment's author, or a user with `manage` on the assessment, may update or delete it. **No policy grants access based on a node's own attributes.**

Full rules and the required test matrix are in [security.md](security.md).

---

## 6. Migrations and seeding

Foreign keys fix the creation order of the base migrations (`database/migrations/2025_01_01_*`):

1. `users` (framework migration, then the application columns)
2. `scales`, `scale_levels`: created before `assessments`, which references them
3. `assessments`: with the three scale foreign keys
4. `scales.assessment_id` → `assessments.id`: added in a follow-up migration, because `scales` and `assessments` reference each other
5. `nodes`: including the generated `root_marker` column, the named CHECK constraints and the `FULLTEXT` index
6. `risk_attack`, `attack_defence`
7. `assessment_user`
8. `tags`, `node_tag`, `node_references`
9. `comments`
10. `assessment_operations`, then `node_revisions` (the latter references the former)
11. `snapshots`
12. `catalog_entries`, `catalog_entry_links`
13. `must_change_password` on `users`, and `instance_settings`

Later migrations (dated 2026) add columns and tables to this base; they are additive, and each one's `down()` reverses it.

**Seeding.** System scales are reference data, not schema. `SystemScaleSeeder` seeds the two built-in scale sets (it is idempotent) and runs from `docker/entrypoint.sh` after `php artisan migrate --force` when `RUN_MIGRATIONS=true`. Templates and the catalogue need an owning administrator, so they are seeded by the first-run setup when the first administrator is created. No seeder, factory, test or fixture contains a password, key or token literal, and no account is seeded.

## 7. Verified properties

- Every `CHECK` constraint in this document is created by a migration under a stable name.
- A second `type = 'root'` row for one assessment violates `nodes_root_unique`.
- The seeded system scale sets satisfy every §3.2 invariant, and `toLevel(toNumber(level)) === level` holds for every level of every system scale (PHP and TypeScript unit tests).
- Model factories exist for `User`, `Assessment`, `RootNode`, `Risk`, `Attack`, `Defence`, `Scale`, `ScaleLevel`, `Tag`, `Comment`, `NodeReference` and `CatalogEntry`.
- No seeder, factory, test or fixture contains a password, key or token literal.
