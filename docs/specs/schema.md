# Schema Specification

> Implements Phase 2 of `docs/PLAN.md`. Work packages **W5** (migrations) and **W6** (models, policies, structural services).
>
> Related: [calculation-engine.md](calculation-engine.md) consumes this schema; [security.md](security.md) specifies the authorization rules the policies enforce.

## 1. Shape: one `nodes` table, two typed edge tables

The domain is **Target of Assessment → Risks → Attacks → Defences/Transfers**, but it is a **DAG, not a tree**: an attack may belong to several risks, and a defence to several attacks. A `parent_id` column would therefore be wrong. Edges live in pivot tables.

**Decision: a single `nodes` table using single-table inheritance on a `type` column, plus two explicit typed pivot tables (`risk_attack`, `attack_defence`).**

### Why one table

1. **Every cross-cutting concern becomes a real foreign key.** Comments, revisions, tags, references, locks, snapshot diffs, undo entries and reordering all point at "a node". With separate per-type tables you need polymorphic `nodeable_type` + `nodeable_id` pairs, and **MySQL/MariaDB cannot put a foreign key on a polymorphic pair** — no `ON DELETE CASCADE`, orphan rows, manual sweep jobs. That is precisely the class of bug the legacy lock-sweep suffered from. This argument alone is decisive.
2. **The DAG cuts *for* one table.** Both edge tables reference the same table on both ends, so traversal, cycle checks and "load the whole assessment" are uniform. **Four queries load any assessment**, always: nodes, `risk_attack`, `attack_defence`, scale levels.
3. **Ordering and re-parenting are uniform** — position lives on the edge, and both edge tables have the same shape.
4. **Frontend homogeneity** — one `Node` TypeScript interface; the tree component, inspector, lock indicator, comment thread and tag chip all take a `Node`. Separate tables would mean four near-identical Vue components.

### Why not the alternatives

- **Closure table** — the graph has exactly three levels and typed edges. There is no arbitrary-depth ancestor query. A closure table solves a problem we do not have and costs O(n²) maintenance per re-parent. Reject.
- **Generic `node_links` edge table** — the two edge kinds carry different payloads (`risk_attack` carries only ordering; `attack_defence` carries strength, mode, level, reduction target, rationale). A generic table makes all of those nullable and loses endpoint-type constraints. Two small typed tables are clearer for no more code. Reject.
- **Hybrid (`nodes` for identity + per-type detail tables)** — buys real per-type `NOT NULL` at the cost of a join on every read and two inserts per write, for six columns. Not worth it at this scale. Reject — **but revisit if type-specific attributes ever exceed ~15 columns per type.**

### Honest costs of the single-table choice, and their mitigations

| Cost | Severity | Mitigation |
|---|---|---|
| ~11 columns are meaningful for only one type and must be nullable | Medium | Named `CHECK` constraints asserting wrong-type columns are `NULL`; PHP STI subclasses so app code never sees a wrong accessor |
| No per-type `NOT NULL` | Low | `is_transfer` is `NOT NULL DEFAULT 0` for all rows and simply ignored for non-defences |
| Every query needs `WHERE type = ?` | Low | Each STI subclass adds a booted global scope. Nobody writes it by hand. |
| Wider rows, slightly worse cache density | Negligible | Assessments are hundreds of rows, not millions |

---

## 2. Conventions

- All tables `InnoDB`, `utf8mb4` with the server's default `utf8mb4` collation.
- All primary keys `BIGINT UNSIGNED AUTO_INCREMENT` (`$table->id()`).
- `timestamps` means `created_at DATETIME NULL` + `updated_at DATETIME NULL`.
- Money is `DECIMAL(20,2)`; rates are `DECIMAL(14,6)`; fractions are `DECIMAL(7,6)`; scale bounds are `DECIMAL(20,6)`.
- **PDO returns `DECIMAL` as strings.** Cast explicitly in the model `$casts` and once more at graph-load time. Never let a string reach the calculation engine.
- Every `CHECK` constraint is **named**, because the name is the error message a developer will see.
- MariaDB 11 and MySQL 8.0.16+ both enforce `CHECK`. Do not skip them assuming they are decorative.

---

## 3. Tables

### 3.1 `users`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `name` | varchar(255) | no | display name |
| `email` | varchar(255) | no | UNIQUE |
| `email_verified_at` | datetime | yes | |
| `password` | varchar(255) | no | bcrypt or argon2id — **255, not 60**, so the algorithm can change without a migration |
| `is_admin` | boolean | no | default `0`. Replaces the legacy `$user->name != "admin"` check. |
| `is_active` | boolean | no | default `1`. Deactivation preserves authorship attribution; deletion does not. |
| `last_login_at` | datetime | yes | |
| `two_factor_secret` | text | yes | W116. The TOTP secret, **encrypted** with the app key (the model casts it). Null unless a second factor is confirmed. |
| `two_factor_recovery_codes` | text | yes | W116. Encrypted JSON array of ten keyed hashes (HMAC-SHA256 under the app key); a used code is removed. Never the codes themselves. |
| `two_factor_confirmed_at` | datetime | yes | W116. Set when a code from the new secret was confirmed; **this** is what says an account has the factor. |
| `two_factor_last_step` | bigint unsigned | yes | W116. The RFC 6238 time step of the last code accepted; a code is accepted only for a greater step. |
| `remember_token` | varchar(100) | yes | |
| timestamps | | | |

Indexes: `users_email_unique (email)`, `users_is_admin_index (is_admin)`.

#### `user_identities` — W117

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `user_id` | bigint unsigned FK → `users` | no | ON DELETE CASCADE |
| `issuer` | varchar(255) | no | the OpenID Connect provider's issuer |
| `subject` | varchar(255) | no | the provider's stable `sub` for this person |
| `last_used_at` | datetime | yes | |
| timestamps | | | |

Indexes: UNIQUE `(issuer, subject)`, UNIQUE `(user_id, issuer)` (one provider per instance, so one identity per account). The email is only how a link is first made.

### 3.2 `scales` and `scale_levels`

Three scale **kinds** only: `impact`, `likelihood`, `strength`. **There is no severity scale** — computed results are rendered as currency, never band-mapped. (See [calculation-engine.md §8](calculation-engine.md#8-qualitative-mapping).)

#### `scales`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `assessment_id` | bigint unsigned | yes | `NULL` = system template. FK → `assessments.id` **ON DELETE CASCADE** |
| `kind` | enum('impact','likelihood','strength') | no | |
| `name` | varchar(120) | no | |
| `unit` | enum('currency','rate_per_year','fraction') | no | Derived from `kind` but stored so `ScaleMapper` can assert unit agreement: impact→currency, likelihood→rate_per_year, strength→fraction |
| `is_system` | boolean | no | default `0`. System scales are read-only and cloned on edit. |
| `set_key` | varchar(64) | yes | W77. Which shared system **set** this scale belongs to. `NULL` on every assessment-owned scale. |
| timestamps | | | |

Indexes: `scales_assessment_kind_index (assessment_id, kind)`, `scales_system_kind_index (is_system, kind)`, `scales_system_set_index (is_system, set_key)`.

#### Scale sets

A **set** is a trio of system scales — exactly one `impact`, one `likelihood`, one `strength` — sharing a `set_key`. It is what `instance_settings.default_scale_calibration` names, and what a new assessment's three scale FKs are filled from.

Before W77 there was no key and exactly one trio, so a set was implicit and nothing could tell two of them apart. That is why `AssessmentCreator` selected its scales with `pluck('id', 'kind')` over every `is_system = 1, assessment_id IS NULL` row: correct for one set, and silently arbitrary for two, since `pluck` keeps one of the duplicate keys with nothing raised. **Selection is by set and only by set** (`App\Domain\Scales\ScaleSets::resolve()`); a duplicate kind inside one set throws rather than choosing.

Rules:

1. A set is **complete** only with all three kinds. Incomplete sets are never offered in the settings select — an assessment's three scale FKs are NOT NULL, so a set missing a kind cannot start one.
2. `default` is the set every instance has. It is what the W77 migration backfilled every pre-existing system scale to, what `default_scale_calibration` defaults to, and the fallback when the configured set has been removed.
3. **Level codes are shared across sets.** A calibration says what "moderate" is worth, not which words exist, and the catalogue and template fixtures reference levels by code — a set that renamed its bands would silently stop resolving them.
4. Sets live in `SystemScaleSeeder`, not in a migration (see below).

Seeded sets:

| `set_key` | Label | What it is for |
|---|---|---|
| `default` | Standard | The original system default, bands unchanged since W22. |
| `small-organisation` | Small organisation | W77. Impact shifted down two decades (0→100 … 100k→∞), likelihood boundaries lower and wider, strength more pessimistic at the top. A ten-person firm's real losses all fall in the default set's bottom two bands, and a scale whose top three bands are unreachable cannot rank the risks it is being used on. |

##### `scale_sets` (W126, D93)

"Set" is the code's word for what the interface calls a **scale**: a named calibration of impact, likelihood and strength. A set's three `scales` rows are found by `scales.set_key`; the name lives here.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned | no | PK. |
| `key` | varchar(64) | no | Unique. The value of `scales.set_key`. There is deliberately **no foreign key**: the W77 migration, the seeder and `ScaleSets::resolve()` read `set_key` as a plain string. |
| `name` | varchar(64) | no | Unique (case-insensitive under the table collation). What the dropdowns show. |
| `is_builtin` | boolean | no | The two seeded scales. A built-in scale can be edited, never renamed or deleted: `AssessmentCreator` falls back to `default`. |
| `position` | int unsigned | no | Order among built-ins; custom scales sort after them by name. |
| `created_at`, `updated_at` | timestamp | no | |

An admin creates a custom scale by copying a complete one (three system `scales` rows and their levels, `set_key` = a slug of the name made unique). Deleting one first gives every assessment still pointing at its rows private, identical copies (`SystemScaleCalibrator::release()`), because the assessment FKs are `RESTRICT`, then removes the rows. `default_scale_calibration` no longer exists (2.5.1); the scale a new assessment starts from is chosen in the New assessment pop-up.

#### `scale_levels`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `scale_id` | bigint unsigned | no | FK → `scales.id` **ON DELETE CASCADE** |
| `ordinal` | tinyint unsigned | no | 1 = lowest |
| `code` | varchar(32) | no | Machine name, e.g. `moderate`. **Internal** — derived from the label on create, frozen on rename, and shown to a user nowhere (W87, D44). Catalogue defaults reference levels by code, not id. |
| `label` | varchar(64) | no | display name |
| `color` | char(7) | no | `#RRGGBB`, default `#9CA3AF`. **Not editable** (W87, D45): nothing outside `ScaleCalibrator` reads it. The column stays because removing it buys nothing and touches snapshot restore. |
| `lower_bound` | decimal(20,6) | no | **inclusive** |
| `upper_bound` | decimal(20,6) | yes | **exclusive**; `NULL` = +∞, permitted on the top band only |
| `representative` | decimal(20,6) | no | the number used in arithmetic |
| timestamps | | | |

Indexes: `scale_levels_ordinal_unique (scale_id, ordinal)`, `scale_levels_code_unique (scale_id, code)`, `scale_levels_lookup_index (scale_id, lower_bound)`.

**Invariants — enforce in a `ScaleValidator` on save, not in the database:**

1. Ordinals are contiguous `1..n`.
2. `level[i].upper_bound == level[i+1].lower_bound` exactly. No gaps, no overlaps.
3. `level[1].lower_bound == 0`.
4. `level[n].upper_bound IS NULL`, except for `unit = 'fraction'` where the top band closes at `1` and the lookup treats it as `[lower, 1]` inclusive.
5. `lower_bound <= representative` and (`upper_bound IS NULL` OR `representative < upper_bound`).

**Invariant 5 is load-bearing**: it is the only thing that makes the qualitative → numeric → qualitative round trip lossless. Without it, "moderate" could resolve to a number that bands back to "major". There is a required unit test asserting `toLevel(toNumber(level)) === level` for every level of every system and custom scale.


**`code` is internal, derived on create, frozen on rename (W87, PLAN-2.5.md D44).**

No editor asks for one, and none is shown to a user anywhere — not on a card, not in a tooltip, not in a validation message, and not in the exported methodology table, which printed it as a column until W87. It is still in the database, still the unique key, still `impact_level_code` / `aro_level_code` in calculation-engine.md §8.3's published payload, and still what `AssessmentCloner::resolveLevelDefault()` and `CatalogInserter::resolveLevelDefault()` look a level up by.

An id could not replace it. A custom scale is **cloned per assessment**, so the row id of "major" differs in every assessment and does not exist until the clone runs; a catalogue fixture is written once and must resolve in all of them. That is the whole reason a second stable name exists.

- **On create** — `App\Domain\Scales\LevelCodeDeriver` slugs the label (`Very strong` → `very_strong`), prefixing `band_` where the result would not start with a letter, and resolves collisions with a `_2` suffix. The taken-set is every code **currently on the scale**, including bands the same save removes: deleting "Minor" and adding a band called "Minor" produces `minor_2`, so the new band inherits neither the old row's stored values nor the catalogue entries that named it.
- **On rename** — nothing happens. Re-deriving would silently detach every catalogue entry naming the old value, and it would pass any test that only checks the band still renders. The editor carries the code it was sent back untouched, as a value it never draws; a band the editor has just created carries `null`.

**Renumbering ordinals needs the range cleared first.** `scale_levels_ordinal_unique (scale_id, ordinal)` makes a one-row-at-a-time renumber impossible: removing the lowest band writes old-band-2 → ordinal 1 while band 1 still holds it. `App\Domain\Scales\LevelOrdinals` shifts every existing ordinal up by 100 in one statement before any final value is written, and re-syncs the in-memory models so a band whose new ordinal equals its old one is still saved.
#### System default scales (seeded, `is_system = 1`, `assessment_id = NULL`)

| Kind | Unit | Bands (lower → upper), representative |
|---|---|---|
| impact | currency | 0→1k (rep 250); 1k→10k (rep 3,162); 10k→100k (rep 31,623); 100k→1M (rep 316,228); 1M→∞ (rep 3,000,000) |
| likelihood | rate_per_year | 0→0.02 (rep 0.005); 0.02→0.2 (rep 0.06); 0.2→1 (rep 0.45); 1→10 (rep 3.16); 10→∞ (rep 30) |
| strength | fraction | 0→0.2 (rep 0.1); 0.2→0.5 (rep 0.35); 0.5→0.8 (rep 0.65); 0.8→0.95 (rep 0.875); 0.95→1 (rep 0.975) |

Labels are `negligible / minor / moderate / major / severe` for impact, `rare / unlikely / possible / likely / frequent` for likelihood, and `weak / limited / moderate / strong / very strong` for strength. Representatives follow the default-generation rule in §3.2.1.

##### 3.2.1 Default representative generation

When an admin creates or edits a band, pre-fill `representative` as:

- **currency, rate_per_year** — geometric mean `√(lower × upper)`, because loss and frequency distributions are heavy-tailed and log-uniform is the right uninformative prior within a band. If `lower = 0`, use `upper / 4`. For the open top band, use `lower × 3` (configurable as `arguesecure.scales.top_band_multiplier`), since the geometric mean of `(L, ∞)` is undefined.
- **fraction** — arithmetic midpoint `(lower + upper) / 2`, which is bounded and roughly uniform.

Every generated value is editable; invariant 5 is re-validated on save.

### 3.3 `assessments`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `uuid` | char(36) | no | UNIQUE |
| `owner_user_id` | bigint unsigned | no | FK → `users.id` **ON DELETE CASCADE** |
| `currency` | char(3) | no | default `'EUR'`, ISO 4217. One currency per assessment; no FX. |
| `display_mode` | enum('quantitative','qualitative','mixed') | no | default `'mixed'`. Affects **input defaults and report rendering only**, never the arithmetic. |
| `impact_scale_id` | bigint unsigned | no | FK → `scales.id` **ON DELETE RESTRICT** |
| `likelihood_scale_id` | bigint unsigned | no | FK → `scales.id` **ON DELETE RESTRICT** |
| `strength_scale_id` | bigint unsigned | no | FK → `scales.id` **ON DELETE RESTRICT** |
| `amortization_years` | tinyint unsigned | no | default `3`; annualizes one-off defence costs. Capped at `config('arguesecure.max_amortization_years')` by the settings form (W44) — past that the annual figure stops being checkable. |
| `risk_tolerance` | decimal(20,2) | yes | W44. An annual-loss threshold. **Display only** — a badge on the ToS card and a line on the report chart; it never enters `RiskCalculator` and never band-maps a result. Nullable with **no default**: "not set" and "zero" are different statements, and zero is a legitimate one. |
| `is_public` | boolean | no | default `0` — listed publicly and readable by anyone |
| `share_token` | char(43) | yes | UNIQUE; base64url of 32 random bytes; unlisted revocable read-only link |
| `share_token_created_at` | datetime | yes | |
| `is_template` | boolean | no | default `0`. **W141:** a template has no owner, and `owner_user_id` is `NULL` exactly when this is `1` (CHECK `assessments_template_ownerless`); not in `$fillable`. |
| `scale_set_key` | varchar(64) | yes | W126. Which admin scale the assessment's bands were last set to; `NULL` is **Custom**. A label, not an input to any calculation, and not a foreign key. Written only at creation and by `AssessmentScaleApplier`, which compares the stored bands with every scale (label, bounds, representative, in order, per kind) — never on page load, so a later admin edit to the scale does not turn the assessment into Custom. Backfilled once by the migration: `default` where the bands equal Standard's, else `NULL`. |
| `calc_version` | int unsigned | no | default `0`; bumped on any change affecting totals. The calculation cache key. |
| timestamps | | | |
| `deleted_at` | datetime | yes | soft delete |

Indexes: `assessments_uuid_unique`, `assessments_share_token_unique`, `assessments_owner_index (owner_user_id, deleted_at)`, `assessments_public_index (is_public, deleted_at)`, `assessments_template_index (is_template)`.

**The assessment has no `title` column.** Title and description live on its root node; listings join `nodes ON nodes.assessment_id = assessments.id AND nodes.type = 'root'`. One join is cheaper than a class of staleness bugs.

### 3.4 `nodes` — the core table

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `uuid` | char(36) | no | UNIQUE. Stable identity across clones and snapshots; the client-side key. |
| `assessment_id` | bigint unsigned | no | FK → `assessments.id` **ON DELETE CASCADE** |
| `type` | enum('root','risk','attack','defence') | no | |
| `title` | varchar(255) | no | may be empty string immediately after keyboard insert |
| `description` | varchar(500) | yes | short summary (legacy `description`) |
| `position` | int unsigned | no | default `0`. Order of **risks under the root** and of **defences in the assessment library**. Attack order lives on `risk_attack.position`; defence order under an attack on `attack_defence.position`. |
| `impact_mode` | enum('quantitative','qualitative') | yes | **risk only** |
| `impact_amount` | decimal(20,2) | yes | **risk only**; loss **per occurrence** |
| `impact_level_id` | bigint unsigned | yes | **risk only**; FK → `scale_levels.id` **ON DELETE RESTRICT** |
| `likelihood_mode` | enum('quantitative','qualitative') | yes | **attack only** |
| `likelihood_aro` | decimal(14,6) | yes | **attack only**; occurrences per year, ≥ 0, may exceed 1 |
| `likelihood_level_id` | bigint unsigned | yes | **attack only**; FK → `scale_levels.id` **ON DELETE RESTRICT** |
| `is_transfer` | boolean | no | default `0`; meaningful for defences only |
| `status` | enum('implemented','todo','shelved') | yes | **defence only**; application default `'todo'` |
| `capex_amount` | decimal(20,2) | yes | **defence only**; one-off cost |
| `opex_annual_amount` | decimal(20,2) | yes | **defence only**; recurring annual cost |
| `created_by` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `updated_by` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| timestamps | | | `updated_at` is the optimistic-concurrency token |
| `deleted_at` | datetime | yes | soft delete — **required**, undo and history depend on rows surviving deletion |
| `root_marker` | bigint unsigned GENERATED ALWAYS AS (CASE WHEN `type`='root' THEN `assessment_id` END) VIRTUAL | yes | UNIQUE — enforces exactly one root per assessment |

Indexes:

- `nodes_uuid_unique (uuid)`
- `nodes_root_unique (root_marker)`
- `nodes_assessment_type_index (assessment_id, type, deleted_at, position)` — the workhorse; serves the whole-assessment load
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

The mode checks deliberately permit `mode` set with the value still `NULL`. That is the legitimate "user picked quantitative but has not typed a number yet" state, and the engine treats it as *missing* — see [calculation-engine.md §6](calculation-engine.md#6-missing-values).

**Deletion semantics.** Node deletion is always soft. The `ON DELETE CASCADE` from `assessments` is the only cascade that fires in normal operation. Hard deletion exists for the owner and admins (*Erase permanently*) and for a scheduled empty-trash job (`arguesecure:purge-deleted`, daily, `trash_retention_days`); when it fires, pivots, comments, revisions, tags and references cascade away. History survives soft deletes; hard delete is a genuine purge. Both go through `App\Domain\Retention\AssessmentEraser` (W113), never a bare `DELETE`, for reasons the cascade alone does not cover: the assessment's three `*_scale_id` columns are pointed at a system scale before its row is deleted, because a calibrated assessment both owns its scales (cascade) and references them (`RESTRICT`), and MariaDB refuses that DELETE with 1451 (the repoint runs only when it owns scales, one system scale per kind); `node_revisions` rows go with the assessment (`assessment_id` cascades) rather than being left with a null `node_id`. Notifications naming the assessment (`data.assessment_uuid`) are deleted with it.

### 3.5 `risk_attack`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `risk_node_id` | bigint unsigned | no | FK → `nodes.id` **ON DELETE CASCADE** |
| `attack_node_id` | bigint unsigned | no | FK → `nodes.id` **ON DELETE CASCADE** |
| `position` | int unsigned | no | default `0` — order of this attack under this risk |
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
| `rationale` | varchar(500) | yes | why this strength — matters for defensibility in a client report |
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

**Application default on link creation:** `reduction_target = 'impact'` if the defence has `is_transfer = 1`, else `NULL`. Stored explicitly and user-overridable. Note that under multiplicative composition this affects displayed intermediates only, never the total — see [calculation-engine.md §5](calculation-engine.md#5-reduction-targets).

### 3.7 `assessment_user` — sharing

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

Role ladder: `viewer` (read) < `commenter` (read + comment) < `editor` (read/write nodes, links, values) < `manager` (editor + share, rename, configure scales, snapshot, soft-delete). The **owner** is `assessments.owner_user_id`, not a row here; only the owner or an admin transfers ownership or hard-deletes. `commenter` costs nothing to add now and is the role people always ask for later.

### 3.8 `comments`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `assessment_id` | bigint unsigned | no | FK → `assessments.id` **ON DELETE CASCADE**. Denormalized for channel scoping and authorization without a join. |
| `node_id` | bigint unsigned | no | FK → `nodes.id` **ON DELETE CASCADE** |
| `parent_comment_id` | bigint unsigned | yes | FK → `comments.id` **ON DELETE CASCADE** |
| `user_id` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `body` | text | no | markdown |
| `resolved_at` | datetime | yes | |
| `resolved_by` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| timestamps | | | |
| `deleted_at` | datetime | yes | |

Indexes: `comments_node_index (node_id, created_at)`, `comments_thread_index (parent_comment_id, created_at)`, `comments_assessment_index (assessment_id, created_at)`.

**Threading depth is exactly two levels** — a top-level comment plus replies. `parent_comment_id` must reference a comment whose own `parent_comment_id IS NULL`. Enforce in `CommentService`. Arbitrary nesting is a UI problem nobody wants.

Comments attach to **nodes only, not to links**. A strength rating on an `attack_defence` row is exactly the kind of thing people argue about, so if link-level discussion is later wanted, add a nullable `attack_defence_id` alongside `node_id`. Deferred, noted in the backlog.

### 3.9 `node_revisions` — change history

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `assessment_id` | bigint unsigned | no | FK → `assessments.id` **ON DELETE CASCADE** |
| `node_id` | bigint unsigned | yes | FK → `nodes.id` **ON DELETE SET NULL** — history outlives a purge |
| `node_uuid` | char(36) | no | denormalized so history stays readable after a purge |
| `user_id` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `action` | enum('create','update','delete','restore','move','link','unlink','link_update') | no | |
| `changes` | json | no | `{"field": {"from": x, "to": y}, …}`; for link actions `{"other_uuid":…, "other_title":…, "pivot": {…}}`; for assessment settings `{"scope": "assessment", "fields": {…}}` |
| `operation_id` | bigint unsigned | yes | FK → `assessment_operations.id` **ON DELETE SET NULL** — groups revisions written by one user action |
| `created_at` | datetime | no | no `updated_at`; revisions are immutable **except inside the coalescing window** below |

Indexes: `node_revisions_node_index (node_id, id DESC)`, `node_revisions_assessment_index (assessment_id, id DESC)`, `node_revisions_operation_index (operation_id)`.

**Coalescing (W56).** `LinkStrengthEditor` saves on `@input`, so typing `0.85` into a strength field is four PATCHes. Further `link_update` edits by the **same user** to the **same edge** within **120 seconds** fold into the open revision: the earliest `from` is kept, the latest `to` wins, a field edited back to its starting value drops out, and a row emptied that way is deleted. This is the one place a revision row is written twice — the immutability rule above is qualified to exactly this window, and the alternative was a log nobody reads because every entry is a keystroke. The window is per user on purpose: two people moving the same number a minute apart is precisely the collision a history exists to show.

**What `node_revisions` does not record — the W48 audit, three of five closed by W56 and the fourth by W60.** `RevisionRecorder` writes `action = 'update'` and `action = 'link_update'`; the other six enum values remain reserved rather than used. That is by design (structural changes live in §3.10), but the coverage gaps below are not, and they are what stopped W48 from building D15's "revert to here". Each is pinned as a `DEFECT:` test in `tests/Feature/History/RevisionCoverageTest.php`, so fixing one turns its test red and forces a reading of the reasoning.

| Mutation | Recorded? | Why it matters for reconstruction |
|---|---|---|
| Node field edit | **yes**, one row per save | — |
| Node create / delete / move / link / unlink | in `assessment_operations` only | fine; the timeline merges both logs |
| **Strength, reduction target, rationale** on `attack_defence` | **yes** (W56) — `action = 'link_update'`, coalesced per user per edge | strength is a direct multiplicand in `ALE = ARO × Impact × Π(1 − strength)`; unrecorded, it made a past total unreconstructable |
| Labels (`node_tag`) | **yes** (W56) — recorded in `TagService`, so any caller is covered | the editor's filter selects on them; a restored graph's saved views would select different nodes |
| Assignees (`node_assignees`) | **yes** (W56) — on the same genuine-change branch W47's notification uses | changes no figure, but "Dana was made responsible" is exactly what a reader goes to history for |
| `currency`, `amortization_years`, `risk_tolerance` | **yes** (W60) — `{"scope": "assessment", "fields": {…}}` on the **root node** | amortisation divides every annual cost in the report |
| Scale recalibration | **no** — and there is no scale-editing screen either (see ui.md §8) | nothing to record today; a gap the moment one is built |

A reconstruction with any of these holes produces an assessment that **looks** restored and is quietly wrong, which is worse than no revert.

**W60 closed the settings hole.** The decision the earlier audit left open was where such a row lives, and it is neither a nullable `node_id` nor a new table: the revision hangs off the **root node** — the row that already carries the assessment's title and description — and is marked `{"scope": "assessment", "fields": {…}}`. The marker sits *beside* the fields rather than among them so a setting can never be mistaken for a node column of the same name, `TimelineReader` renders the subject as "the assessment", and `RevisionReverter` **refuses the scope explicitly** rather than relying on its `$fillable` allowlist, which rejects these today only because no node column happens to share a name with a setting. A settings save that also renames the assessment writes two rows — one per subject — because folding them would need an `operation_id`, and a settings edit is not a structural change (§3.10).

**W61 built the restore path.** The fallback the brief allowed — revert as far as the nearest snapshot — is now available: `SnapshotRestorer` applies a frozen graph back **in place**, matched by uuid so comments and labels survive on every node that still exists, with a safety snapshot of the current state taken first in the same transaction. The operation's inverse points at that safety row, so the inverse of a restore is another restore and undo works. Every revision it writes is stamped with the operation id through `RevisionRecorder::withinOperation()`, which is what makes fifty node writes read as one timeline entry — the `operation_id` grouping TimelineReader has supported since W48 and nothing had ever produced.

**W62 closed the limitation W61 could only report.** A snapshot's shape (§3.13) held no labels or assignees, so a node a restore *resurrected* came back bare and the count was shown on the page. `graph` v2 carries both, and a restore applies them — replacing rather than merging, and re-checking every frozen assignment against current membership before writing it. Snapshots already taken at v1 keep restoring, and keep saying what they cannot bring back.

**What is left is a limitation rather than a hole.** Comments are still not part of a snapshot and deliberately are not going to be — they are a conversation, not a version of the work, and a node matched by uuid keeps its own either way. Scale recalibration still has nothing to record until a calibration screen exists (ui.md §8).

### 3.10 `assessment_operations` — the undo stack

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `assessment_id` | bigint unsigned | no | FK → `assessments.id` **ON DELETE CASCADE** |
| `user_id` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `kind` | varchar(64) | no | `node.create`, `node.delete`, `node.move`, `link.create`, `link.delete`, `subtree.clone`, `catalog.insert` |
| `summary` | varchar(255) | no | human-readable: `Deleted attack 'Phishing'` |
| `inverse` | json | no | a serialized command that undoes it |
| `undone_at` | datetime(6) | yes | microseconds since W108: redo orders by it |
| `undone_by` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `redone_at` | datetime | yes | set when a redo consumes this row; it stays undone (W108) |
| `superseded_at` | datetime | yes | set on every undone row when a new operation is recorded; it stays undone but can no longer be redone (W121) |
| `created_at` | datetime | no | |

Index: `assessment_operations_stack_index (assessment_id, undone_at, id DESC)`.

**Undo semantics, decided:**

- **One linear stack per assessment, not per user.** Per-user undo in a live multi-user editor produces incoherent history and is a known trap.
- Only the newest not-yet-undone operation is undoable. Any editor may undo it, but the UI names the author: *"Undo Dana's deletion of 'Phishing'?"*
- Redo is the undo of an undo, pushed as a new operation. The row it consumed keeps `undone_at` and gets `redone_at`, which takes it off both stacks: undo skips it because it is undone, redo because it is redone. Clearing its `undone_at` instead, as 2.6 did, put it back on the undo stack holding the redo command, so a later undo re-applied the change (W108). Redo takes the most recently undone row, which is why `undone_at` keeps microseconds: at one-second precision two undos in one second tied, and no tiebreak on `id` is right for both Ctrl+Z, Ctrl+Z (the lower id came last) and undo, redo, undo (the higher one did).
- A new operation ends the redo stack: `UndoService::record()` stamps `superseded_at` on every row that is undone and not yet redone, and `redo()` skips stamped rows. Redo's own pushed row does not stamp, so undo, undo, redo, redo still works. It is a column because it cannot be derived: redo pushes a row, which would otherwise count as newer work (W121). Rows redone before W108's migration still hold a redo command as their `inverse`, and rows undone before W121's are not stamped; neither is repaired, since which rows are affected cannot be told from the row alone.
- Every applier resolves the uuids in a command inside the operation's own assessment (`CommandApplier::apply($command, $assessmentId)`); a uuid from another assessment reads as a node that does not exist (W121). The `inverse` is written only by server code, so this is defence in depth.
- **If the inverse cannot be applied cleanly** — a referenced node was hard-deleted, or a field has since been edited by someone else — **refuse with an explicit message rather than partially applying.**
- Only structural changes enter the stack. Field edits are recovered through `node_revisions` "revert this field", which is recorded as a normal edit, not an undo.

### 3.10a `security_events` — the stored security log (W128)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `created_at` | datetime | no | UTC; indexed |
| `level` | varchar(16) | no | `info` or `warning` |
| `event` | varchar(64) | no | a `SecurityEvent` value |
| `actor_id` | bigint unsigned | yes | **no FK**: a row outlives the account |
| `actor_email` | varchar(254) | yes | |
| `target` | json | yes | `{type, id, email?}` as in security.md §11.1 |
| `ip` | varchar(45) | yes | |
| `details` | json | yes | already redacted |

Index `(event, created_at)`. Written only by the `security` log channel; deleted only by `arguesecure:purge-security-events` after `SECURITY_LOG_RETENTION_DAYS`.

### 3.11 `tags` and `node_tag`

`tags`: `id`; `assessment_id` bigint unsigned NOT NULL FK → `assessments.id` **ON DELETE CASCADE**; `name` varchar(60) NOT NULL; `slug` varchar(60) NOT NULL; `color` char(7) NOT NULL default `#3B82F6`; timestamps. Unique `tags_slug_unique (assessment_id, slug)`.

`node_tag`: `node_id` + `tag_id`, composite primary key `(node_id, tag_id)`, both FK **ON DELETE CASCADE**, plus `node_tag_reverse_index (tag_id, node_id)`.

**Created by being used (W43).** There is no vocabulary-management screen: naming a label on a node creates it for that assessment, and naming one whose **slug** already exists reuses it. The slug is `Str::slug(name)`, falling back to the lowercased name when transliteration yields nothing (a name with no latin characters would otherwise slug to the empty string and collide with every other such name through the unique index). The first spelling wins the display name — `resolve()` never renames an existing tag.

**`node_tag` is cloned with the assessment (W43).** `AssessmentCloner` had copied the vocabulary since W6 and never the attachments, so a copy arrived with every label defined and none applied. Unreachable until W43, since nothing could attach one; silent data loss the moment something could.

**Tags are assessment-scoped — a deliberate change from the legacy global tags.** The old design plus its `pruneTags()` garbage collector was a bug factory: one user's rename hit everyone, and a full-table diff ran on every tag sync. Scoping to the assessment makes colours meaningful (a client's own taxonomy), makes cloning trivial, and makes deletion a cascade. A global autocomplete suggestion library can be added later as a separate table with no FK implications.

### 3.11a `node_assignees` — who is responsible (W46)

`node_assignees`: `node_id` + `user_id`, composite primary key `(node_id, user_id)`, both FK **ON DELETE CASCADE**, plus `node_assignees_user_index (user_id, node_id)`.

**No role, no timestamps, no `assigned_by`.** An assignment grants nothing — every ability still comes from the assignee's collaborator role through `AssessmentPolicy`, and there is no node-scoped grant anywhere in this codebase. A role column here would create one by implication, so the table has nowhere to put one.

**The reverse index is the dashboard's.** The composite key answers "who is on this node?"; `node_assignees_user_index` answers "what is assigned to me?", which runs on every sign-in and would otherwise scan the table.

**Removing a collaborator removes their assignments** (`AssessmentUser`'s `deleted` hook, not a constraint — the FKs cascade on a deleted *node* or *account*, which is a different event). The alternative leaves a row naming someone on a node they can no longer open: either the dashboard shows them work they cannot reach, or it hides them while the assessment goes on naming them indefinitely. A **role change** deliberately does not fire it — a viewer can still be the person responsible.

**An assignee must already be a collaborator** (PLAN-2.2.md D13). Assigning someone who cannot open the assessment produces a task nobody can do. Adding a new person is a `manage` action and stays one, even when reached through the assign route.

### 3.11b `notifications` — the in-app feed (W47)

Laravel's own table, generated by `notifications:table` and unmodified except for one index. Not a bespoke table: the framework's shape has a tested API behind it (`$user->notifications`, `unreadNotifications`, `markAsRead()`), and the two sources — a mention in a comment, a node assigned to you — differ only in `type` and the contents of `data`, which is exactly what that column is for.

Added: `notifications_unread_index (notifiable_type, notifiable_id, read_at)`. The unread badge is a shared Inertia prop, so it runs on **every authenticated request in the application**; the framework's default table has no index beyond the morph pair and that count would scan.

**`data` is denormalised on purpose.** A notification is a record of what was true when it was sent — the excerpt is the comment as it read at the moment of the mention. Rendering it live would let an edit rewrite a message someone has already been sent, with no way for the reader to tell.

**Display names only, never addresses.** A feed is one person reading about other people's activity, which is exactly where an address leaks.

**Mentions match against collaborators only**, never against `users` — see security.md §3.1c. `data.comment_id` is what makes an edit re-parse safe: someone already told about a comment is not told again, while someone the edit newly names is.

**In-app only. No email**, which needs a mail configuration, a digest policy and an unsubscribe path — out of scope, stated rather than discovered.

### 3.12 `node_references` — CVE and friends

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `node_id` | bigint unsigned | no | FK → `nodes.id` **ON DELETE CASCADE** |
| `kind` | enum('cve','cwe','capec','attack_technique','control','url','other') | no | `control` since W102, on a defence only |
| `identifier` | varchar(64) | yes | required for every kind except `url`; normalized to uppercase |
| `url` | varchar(2048) | yes | required for `url` and `other`; derived for the rest |
| `title` | varchar(255) | yes | user-entered label. **No live API calls** — nothing is fetched. |
| `position` | int unsigned | no | default `0` |
| timestamps | | | |

Indexes: `node_references_unique (node_id, kind, identifier)`, `node_references_identifier_index (kind, identifier)` — the latter answers "which assessments mention CVE-2024-3094?".

Validation patterns and link templates live in `config/arguesecure.php`:

| Kind | Pattern | Link |
|---|---|---|
| `cve` | `/^CVE-\d{4}-\d{4,}$/` | `https://nvd.nist.gov/vuln/detail/{id}` |
| `cwe` | `/^CWE-\d{1,5}$/` | `https://cwe.mitre.org/data/definitions/{n}.html` |
| `capec` | `/^CAPEC-\d{1,4}$/` | `https://capec.mitre.org/data/definitions/{n}.html` |
| `attack_technique` | `/^T\d{4}(\.\d{3})?$/` | `https://attack.mitre.org/techniques/{T}/{sub}/` |
| `control` | `FRAMEWORK:CONTROL`; the framework from `references.frameworks`, the control part against that framework's own pattern | the framework's page, never the control's |
| `url` | must be `https?://` | rendered with `rel="noopener nofollow"` |

CVE is the requirement; the others cost nothing because the validate-and-link mechanism is identical. Surface CVE prominently and the rest behind a "more" affordance — except on a defence, where the form opens on **Control**.

**`control` (W102, PLAN-2.7 D64)** cites the framework control a defence implements: `ISO27001-2022:A.5.23`, `ISO42001-2023:A.6.2.2`, `EUAIACT:ART.9`, `GDPR:ART.32`, `NIS2:ART.21(2)(D)`, `SOC2:CC6.1`, `CIS8:4.1`, `CSF2:PR.AA-01`, `CRA:ANNEX.I.I(2)(A)`. The list of frameworks is closed and lives in config; a code is never renamed once an assessment may cite it. Uppercased like every identifier, so `Art.32` and `ART.32` are one citation; the report prints `Art. 32`, and `ANNEX.I.I(2)(A)` as `Annex I Part I(2)(a)`. Refused with 422 on a risk, an attack or the root, because the coverage view (ui.md §9) reads defences only and a citation elsewhere would be one it never shows. The link is to the framework because the standards' text is not free to deep-link. The nine standards templates cite one on every defence whose title names a control.

### 3.13 `snapshots`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `assessment_id` | bigint unsigned | no | FK → `assessments.id` **ON DELETE CASCADE** |
| `name` | varchar(120) | no | |
| `note` | varchar(500) | yes | |
| `kind` | varchar(16) | no | 2.5.1. `manual` (default), `auto` (saved by `AutoVersioner`), `safety` (taken by a restore) |
| `created_by` | bigint unsigned | yes | FK → `users.id` **ON DELETE SET NULL** |
| `graph` | longtext | no | gzip + base64 of the canonical JSON graph: nodes keyed by `uuid`, both pivots, **scales inlined**, and from v2 each node's **labels and assignees inlined too** |
| `results` | json | no | the frozen `CalculationResult` for all three baselines |
| `schema_version` | smallint unsigned | no | for forward-compatible readers. **1** = W19's shape; **2** = W62 onward |
| `created_at` | datetime | no | |

Index: `snapshots_assessment_index (assessment_id, created_at DESC)`.

**Presented as versions (2.5.1).** The History page is built from this table: each snapshot is a numbered *version*, holding the history-log entries written between the previous one and itself (`VersionTimeline`), with unsaved entries under "Current version". `AutoVersioner` (scheduled every five minutes) saves an `auto` version for any assessment whose latest change is older than 30 minutes, or that has 50 changes since its last version; nothing is saved for an assessment with no changes. Link-revision coalescing (§3.9) never folds an edit into a row older than the latest version, so a version's changes do not move after it is saved.

**Snapshots inline the scales** so that recalibrating a scale later cannot silently rewrite history, and `results` is frozen so reports are reproducible. **Restoring one (W61)** applies `graph` back in place — never `results`, which stays frozen output and is never replayed into the live assessment. `schema_version` is checked before anything is written and a version this code does not know is refused outright, which is what the column was reserved for — `SnapshotRestorer::READABLE_VERSIONS` is a **list and not a floor**, so a shape with something removed from it cannot be silently half-applied. Diffing two snapshots is pure PHP: match nodes by `uuid`, produce added/removed/changed field sets and added/removed edges, and diff the `results` totals. **Snapshot reports never recompute.**

#### `graph` v2 — labels and assignees (W62)

Each node entry gained two keys:

```json
"tags":      [{"name": "PCI scope", "slug": "pci-scope", "color": "#3B82F6"}],
"assignees": [{"id": 42, "name": "Dana Okonkwo"}]
```

Four properties hold, and each is asserted in `tests/Feature/Snapshots/SnapshotLabelsTest.php`:

- **Labels are stored by value, matched by slug.** The same reasoning as the inlined scales: an id left pointing into `tags` would make a frozen version follow a later rename or deletion. `slug` is the assessment-scoped stable name (§3.11); `name` and `color` are carried so the label renders as it looked. A restore reuses the live tag with that slug, or **recreates** it from the frozen values if it has been deleted — a graph put back without the labels it was filed under has restored the work and lost the filing.
- **Assignees store `id` and `name`, never an email.** A snapshot is readable by anyone holding `view`, which a share token grants with no account at all, so an email here would be published to that audience and stay published for as long as the snapshot exists. The id is what a restore re-attaches by; the name is what lets the snapshot still say who it was after the account is gone. The guard is the serializer that names those two keys — narrowing the eager-load to `assignees:id,name` beside it is a second line, not the one holding.
- **A restore replaces, it does not merge.** Both sets are `sync()`ed, so a label added since the snapshot comes off with everything else. The exception is a **v1** snapshot: it does not say "this node had no labels", it says nothing about labels at all, so syncing it to an empty set would turn silence into a deletion. v1 leaves them alone and reports the count it could not restore.
- **A frozen assignment is re-checked, never just re-attached.** `AssessmentUser`'s `deleted` hook removes assignments when someone loses access to the assessment. Restoring one without re-checking membership would put every one of them back in a single click — not a weaker version of that guard, a way around it. Assignments naming someone who is no longer a collaborator, or whose account is gone, are dropped and **counted** into the message, because the person restoring is frequently the person who removed them.

Nothing rewrites an existing snapshot. v1 is not a migration step; it is a shape the restorer reads permanently.

### 3.14 Templates and catalogue

Two mechanisms, both needed.

**(a) Template assessments** — `assessments.is_template = 1`, with `owner_user_id = NULL` (W141, 2.7.0): a template is nobody's assessment, only admins reach it through the assessment routes, and the Templates page is the only place one is created, edited (in the ordinary editor) or deleted (permanently). "Publish as template" copies an assessment through `AssessmentCloner` into a new template and writes nothing to the original; there is no link back and no unpublish. "New from template" runs `AssessmentCloner`, which also clones assessment-owned scales, tags, `node_tag` and `node_references` (§3.12) and remaps every `uuid`. References were the one thing it silently dropped until W78, so a template's citations vanished on instantiation while the structure arrived intact.

Inserting a catalogue entry copies no references, and that is not an omission: a `catalog_entries` row has no reference of its own to copy.

**(b) `catalog_entries`** — a flat library of standard risks, attacks and defences to drag in.

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

`catalog_entry_links`: `parent_entry_id`, `child_entry_id`, `defaults` json (pivot defaults such as a default strength level code), both FK **ON DELETE CASCADE**, unique `(parent_entry_id, child_entry_id)`. This lets an entry be a **bundle** — a "Phishing" entry shipping with MFA, awareness training and mail filtering pre-linked at default strengths.

**Store level *codes*, never level ids, in `defaults`.** That is the detail which lets a catalogue entry insert correctly into any assessment regardless of that assessment's calibration; the code is resolved against the target assessment's scales at insert time. If a code does not resolve, leave the value unset rather than guessing.

Inserting is a **copy**. Later edits to a catalogue entry do not propagate into assessments already built from it — propagating changes into a signed-off assessment would be worse.

### 3.15 Locking — deliberately no table

Field-level pessimistic locks live only in Redis. See `docs/PLAN.md` Phase 6 and the summary here:

- Key `lock:{assessment_id}:{node_uuid}:{field}` where field ∈ `title`, `description`, `values`, `link:{pivot_id}`.
- Value: JSON `{"user_id":…, "name":…, "session":…, "at":…}`.
- Acquire with `SET key value NX PX 45000`. On failure, `GET` to report the holder.
- Refresh while focused via a presence-channel whisper every 15 s, extended by a compare-and-extend Lua script.
- Release on blur, save, navigate, and presence-leave with a compare-and-delete Lua script.
- **The TTL is the sweep.** No sweep job, no `locked`/`lock_time` columns, no polling endpoint. This removes the legacy `HeartbeatController`, the `Heartbeat` middleware and its full-table sweep entirely.

**Locks are cooperative UX, not the correctness guarantee.** Every node PATCH carries the node's `updated_at`; a mismatch returns **409** with the current server state. That version check is what actually prevents lost updates.

### 3.16 `instance_settings` — W8b

A deliberate **singleton row**, not a key/value table: there is exactly one instance of the application, so one row with a `CHECK` pinning its id gives every setting a real, typed column without the indirection a generic settings table would add. Backs `App\Domain\Accounts\InstanceSettings` (`app/Models/InstanceSetting.php`), which previously persisted this through `Cache::forever()` — durable configuration in a cache store means a cache flush silently reverts the instance to defaults, which is the gap this table closes.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | always `1` — enforced by `chk_instance_settings_singleton`. Deliberately **not** `AUTO_INCREMENT`: MariaDB rejects a CHECK constraint that references one, so `App\Models\InstanceSetting` sets it explicitly instead. |
| `default_currency` | char(3) | no | default `'EUR'`, ISO 4217; pre-fills `assessments.currency` on creation |
| `default_scale_calibration` | varchar(64) | no | default `'default'`. A **reference to `scales.set_key`** (§3.2), not free text: it names the system scale set new assessments start from. Validated `in:` the sets that exist. W77's migration normalised every pre-W77 value — free text nothing read — to `'default'`. |
| `public_assessments_allowed` | boolean | no | default `1`; enforced server-side in `ShareController::updatePublic()`, not only reflected in the admin UI |
| `assistant_enabled` | boolean | no | W50, default `0`. The admin-controlled half of PLAN-2.3-ai.md D25's switch; the deployment half is `config('assistant.enabled')` and is not a column. Off on every deployment until somebody decides otherwise. |
| `assistant_help_enabled` | boolean | no | W87 (D48), default `1`. |
| `assistant_suggestions_enabled` | boolean | no | W87 (D48), default `1`. |
| `assistant_summary_enabled` | boolean | no | W87 (D48), default `1`. One switch per assistant feature, each **subordinate to `Availability`** and able only to subtract from it — no value here makes a feature work where the deployment runs no runtime, which `UpdateSettingsRequest` enforces with `declined`. They default **on** because they are read only after an admin has switched `assistant_enabled` on; defaulting them off would make that a two-step act whose second step is invisible. |
| `session_lifetime_minutes` | int unsigned | no | default `480`; read by `App\Http\Middleware\ApplyInstanceSessionLifetime` and applied to `config('session.lifetime')` before the session starts |
| `require_two_factor` | boolean | no | W116 (D78), default `0`. On: an account with no second factor is sent to enrolment after its password. Off by default so switching it on never locks anyone out unannounced. |
| `sso_only` | boolean | no | W117 (D79), default `0`. On, and with a provider configured, non-admins cannot sign in with a password. |
| `oidc_issuer`, `oidc_client_id`, `oidc_button_label`, `oidc_allowed_domains` | varchar(255), varchar(255), varchar(100), text | yes | W133 (D106). The OpenID Connect provider as stored on the Authentication tab; null means not set here. A same-named `OIDC_*` environment value wins (`OidcConfig`). Domains are lower-case, comma-separated. |
| `oidc_client_secret` | text | yes | W133 (D106). The client secret under the `encrypted` cast (`APP_KEY`), in `$hidden`, never returned to a page. |
| `oidc_trust_email` | boolean | no | W133 (D106), default `0`. |
| timestamps | | | |

Named CHECK:

```sql
chk_instance_settings_singleton:
  id = 1
```

There is no `registration_locked` column. `CLAUDE.md` and [security.md §4](security.md#4-authentication) are explicit that there is no public registration, ever — accounts are admin-created — so a "registration locked" toggle has nothing to gate. W8 stored and displayed the key without wiring it to anything; W8b removed it rather than inventing behaviour for it to control, per the same document's "a control that appears to do something and does not is worse than no control."

---

## 4. Eloquent models

```
Node (base, table `nodes`, SoftDeletes)
├── RootNode   global scope type='root'
├── Risk       global scope type='risk'
├── Attack     global scope type='attack'
└── Defence    global scope type='defence'
```

**STI wiring.** Override `Node::newFromBuilder()` to inspect `$attributes['type']` and instantiate the mapped subclass. Each subclass declares `const TYPE` and adds both a booted global scope on `type` and a `creating` hook that sets it. `Node` itself carries **no** type scope, so polymorphic relations (a comment's node, a revision's node) resolve to the correct subclass automatically.

### Relationships

- **`Node`** — `assessment()` belongsTo; `createdBy()`/`updatedBy()` belongsTo `User`; `comments()` hasMany (top-level via `whereNull('parent_comment_id')`); `revisions()` hasMany ordered desc; `tags()` belongsToMany through `node_tag`; `references()` hasMany `NodeReference`.
- **`RootNode`** — `risks()` = `hasMany(Risk::class, 'assessment_id', 'assessment_id')->orderBy('position')`. Risks attach by `assessment_id` rather than a parent pointer, because a risk belongs to exactly one assessment and there is exactly one root per assessment.
- **`Risk`** — `attacks()` = `belongsToMany(Attack::class, 'risk_attack', 'risk_node_id', 'attack_node_id')->using(RiskAttack::class)->withPivot('id','position')->withTimestamps()->orderBy('risk_attack.position')`; `impactLevel()` belongsTo `ScaleLevel`.
- **`Attack`** — `risks()` the inverse belongsToMany; `defences()` = `belongsToMany(Defence::class,'attack_defence','attack_node_id','defence_node_id')->using(AttackDefence::class)->withPivot('id','position','strength_mode','strength_fraction','strength_level_id','reduction_target','rationale')->withTimestamps()->orderBy('attack_defence.position')`; `likelihoodLevel()` belongsTo `ScaleLevel`.
- **`Defence`** — `attacks()` the inverse belongsToMany with the same pivot.
- **Pivot models** `RiskAttack extends Pivot` and `AttackDefence extends Pivot`, both with `$incrementing = true`. `AttackDefence` exposes `strengthFraction(): ?float` (resolving a qualitative level to its `representative`) and `effectiveTarget(): ?string`.
- **`Assessment`** — `rootNode()` hasOne `RootNode`; `nodes()` hasMany `Node`; `risks()`/`attacks()`/`defences()` hasMany of the subclass; `owner()` belongsTo `User`; `members()` belongsToMany `User` withPivot role; `impactScale()`/`likelihoodScale()`/`strengthScale()` belongsTo `Scale`; `tags()`, `snapshots()`, `operations()` hasMany. Accessors `title` and `description` proxy the root node.
- **`Scale`** hasMany `ScaleLevel` ordered by `ordinal`; **`ScaleLevel`** belongsTo `Scale`.
- **`Comment`** belongsTo `Node` and `User`; `parent()`/`replies()` self-referential.
- Plus `NodeRevision`, `Snapshot`, `Tag`, `NodeReference`, `AssessmentOperation`, `CatalogEntry` (with `catalogChildren()`/`catalogParents()`), and `User` (`ownedAssessments()`, `sharedAssessments()`, `is_admin`).

### Domain services under `app/Domain/`

| Class | Responsibility |
|---|---|
| `Graph\AssessmentGraphLoader` | 4 queries → an immutable `AssessmentGraph` DTO |
| `Graph\AssessmentGraph` | Plain PHP values, no Eloquent, serializable. **The only input the calculation engine accepts.** |
| `Structure\LinkService` | Create/remove edges with endpoint-type and same-assessment validation; cycle-free by construction |
| `Structure\ReorderService` | Re-sequence siblings inside a transaction |
| `Structure\AssessmentCloner` | Clone an assessment, a subtree, or a catalogue entry |
| `History\RevisionRecorder` | Model observer writing `node_revisions` grouped by `operation_id` |
| `History\UndoService` | Push/pop `assessment_operations` |
| `Scales\ScaleValidator` | Enforces the §3.2 invariants on save |

Calculation services are specified separately in [calculation-engine.md §11](calculation-engine.md#11-implementation).

---

## 5. Authorization

`AssessmentPolicy` is the single source of truth. Abilities: `view`, `comment`, `update`, `manage`, `delete`. Resolution order: `user.is_admin` → owner → `assessment_user.role` → `is_public` (view only) → a valid share token held in the session (view only).

`NodePolicy` and `CommentPolicy` are thin delegates that load the owning assessment and defer. **No policy ever grants access based on a node's own attributes.**

Full rules, the legacy defects being fixed, and the required test matrix are in [security.md §3](security.md#3-authorization).

---

## 6. Migration ordering (W5)

Foreign keys require creation order. Write one migration per group, in this sequence:

1. `users`
2. `scales`, `scale_levels` — created before `assessments`, which references them. Seed system scales in a seeder, not a migration.
3. `assessments` — add the three scale foreign keys here.
4. `nodes` — including the generated `root_marker` column and the `FULLTEXT` index.
5. `risk_attack`, `attack_defence`
6. `assessment_user`
7. `tags`, `node_tag`, `node_references`
8. `comments`
9. `assessment_operations` then `node_revisions` (the latter references the former)
10. `snapshots`
11. `catalog_entries`, `catalog_entry_links`

`scales.assessment_id` → `assessments.id` is a circular reference with step 3. Create `scales` without that foreign key, then add it in a small follow-up migration after `assessments` exists.

## 7. W5 acceptance criteria

- `php artisan migrate:fresh` succeeds on MariaDB 11 **and** MySQL 8.
- `php artisan migrate:fresh --seed` produces the three system scales, each passing every §3.2 invariant.
- Every CHECK constraint is present and named; a test inserts a deliberately invalid row per constraint and asserts rejection.
- Attempting a second `type = 'root'` row for one assessment fails on `nodes_root_unique`.
- Model factories exist for `User`, `Assessment`, `Risk`, `Attack`, `Defence`, `Scale`, `ScaleLevel`, and can build the [worked example](calculation-engine.md#14-worked-example--the-first-golden-vector) in one call.
- No seeder, factory, test or fixture contains a password, key or token literal.
