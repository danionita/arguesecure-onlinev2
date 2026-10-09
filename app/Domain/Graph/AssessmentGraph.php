<?php

namespace App\Domain\Graph;

/**
 * Plain PHP values, no Eloquent, fully serializable. This is the only input
 * Calculation\RiskCalculator (W7) accepts — passing Eloquent models into the
 * engine is a design violation, because it is what makes the engine pure,
 * testable in isolation, snapshot-replayable, and mirrorable in TypeScript
 * (schema.md §4, calculation-engine.md §11.1).
 *
 * Every node is keyed by its uuid, not its database id, because uuid is the
 * stable identity across clones and snapshots (schema.md §3.4).
 *
 * An attack-defence link's `rationale` is an optional key: the calculation
 * engine never reads it and the fixture-built graphs in tests/Unit/Calculation
 * omit it, but the loader always supplies it. The node modal's Links tab lets
 * a user record why a defence is rated as it is, and while the DTO dropped the
 * column that text could be saved and never shown again after a reload.
 *
 * @phpstan-type RiskData array{
 *     uuid: string,
 *     title: string,
 *     impact_mode: ?string,
 *     impact_amount: ?float,
 *     impact_level_id: ?int,
 * }
 * @phpstan-type AttackData array{
 *     uuid: string,
 *     title: string,
 *     likelihood_mode: ?string,
 *     likelihood_aro: ?float,
 *     likelihood_level_id: ?int,
 * }
 * @phpstan-type DefenceData array{
 *     uuid: string,
 *     title: string,
 *     is_transfer: bool,
 *     status: ?string,
 *     capex_amount: ?float,
 *     opex_annual_amount: ?float,
 * }
 * @phpstan-type RiskAttackLink array{
 *     risk_uuid: string,
 *     attack_uuid: string,
 *     position: int,
 * }
 * @phpstan-type AttackDefenceLink array{
 *     attack_uuid: string,
 *     defence_uuid: string,
 *     position: int,
 *     strength_mode: ?string,
 *     strength_fraction: ?float,
 *     strength_level_id: ?int,
 *     reduction_target: ?string,
 *     rationale?: ?string,
 * }
 * @phpstan-type ScaleLevelData array{
 *     id: int,
 *     scale_id: int,
 *     kind: string,
 *     unit: string,
 *     code: string,
 *     label: string,
 *     ordinal: int,
 *     lower_bound: float,
 *     upper_bound: ?float,
 *     representative: float,
 * }
 * @phpstan-type ScaleData array{
 *     id: int,
 *     kind: string,
 *     unit: string,
 *     is_system: bool,
 * }
 */
final readonly class AssessmentGraph
{
    /**
     * @param  array<string, array{uuid: string, title: string, impact_mode: ?string, impact_amount: ?float, impact_level_id: ?int}>  $risks  keyed by risk uuid
     * @param  array<string, array{uuid: string, title: string, likelihood_mode: ?string, likelihood_aro: ?float, likelihood_level_id: ?int}>  $attacks  keyed by attack uuid
     * @param  array<string, array{uuid: string, title: string, is_transfer: bool, status: ?string, capex_amount: ?float, opex_annual_amount: ?float}>  $defences  keyed by defence uuid
     * @param  list<array{risk_uuid: string, attack_uuid: string, position: int}>  $riskAttackLinks
     * @param  list<array{attack_uuid: string, defence_uuid: string, position: int, strength_mode: ?string, strength_fraction: ?float, strength_level_id: ?int, reduction_target: ?string, rationale?: ?string}>  $attackDefenceLinks
     * @param  array<int, array{id: int, scale_id: int, kind: string, unit: string, code: string, label: string, ordinal: int, lower_bound: float, upper_bound: ?float, representative: float}>  $scaleLevels  keyed by scale_level id
     * @param  array<int, array{id: int, kind: string, unit: string, is_system: bool}>  $scales  keyed by scale id — the parent rows behind $scaleLevels, needed only to decide `uncalibrated_scales` (calculation-engine.md §12); defaults to `[]` so existing callers that pre-date this field keep compiling
     */
    public function __construct(
        public string $assessmentUuid,
        public string $currency,
        public int $amortizationYears,
        public ?string $rootTitle,
        public ?string $rootDescription,
        public array $risks,
        public array $attacks,
        public array $defences,
        public array $riskAttackLinks,
        public array $attackDefenceLinks,
        public array $scaleLevels,
        public array $scales = [],
    ) {}
}
