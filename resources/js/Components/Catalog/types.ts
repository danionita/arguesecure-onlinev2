/**
 * Mirrors app/Http/Controllers/CatalogController.php's and
 * TemplateController.php's Inertia props byte-for-byte (schema.md §3.14,
 * PLAN.md Phase 8 item 4).
 */

export type CatalogEntryKind = 'risk' | 'attack' | 'defence';

export interface CatalogEntrySummary {
    id: number;
    kind: CatalogEntryKind;
    title: string;
    description: string | null;
    isBundle: boolean;
    isPublic: boolean;
    isSystem: boolean;
}

export interface CatalogParentOption {
    uuid: string;
    title: string;
}

export interface CatalogParents {
    risks: CatalogParentOption[];
    attacks: CatalogParentOption[];
}

export interface CatalogFilters {
    kind: CatalogEntryKind | null;
    q: string | null;
}

/** A node created by a catalogue insert — the same shape NodeController's
 *  own store()/update() responses use, so the tree editor's store can
 *  merge either interchangeably. */
export interface InsertedNode {
    uuid: string;
    type: 'root' | 'risk' | 'attack' | 'defence';
    title: string;
    description: string | null;
    position: number;
    impact_mode: string | null;
    impact_amount: number | null;
    impact_level_id: number | null;
    likelihood_mode: string | null;
    likelihood_aro: number | null;
    likelihood_level_id: number | null;
    is_transfer: boolean;
    status: string | null;
    capex_amount: number | null;
    opex_annual_amount: number | null;
    updated_at: string | null;
}

export interface InsertedRiskAttackLink {
    risk_uuid: string;
    attack_uuid: string;
    position: number;
}

export interface InsertedAttackDefenceLink {
    attack_uuid: string;
    defence_uuid: string;
    position: number;
    strength_mode: string | null;
    strength_fraction: number | null;
    strength_level_id: number | null;
    reduction_target: string | null;
}

export interface CatalogInsertResult {
    nodes: InsertedNode[];
    riskAttackLinks: InsertedRiskAttackLink[];
    attackDefenceLinks: InsertedAttackDefenceLink[];
}

export interface TemplateSummary {
    uuid: string;
    title: string | null;
    description: string | null;
    owner: string | null;
    currency: string;
    updatedAt: string | null;
}
