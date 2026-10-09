/**
 * Mirrors app/Http/Controllers/SnapshotController.php's Inertia props
 * byte-for-byte (PLAN.md Phase 8 item 3, schema.md §3.13). `results` reuses
 * the existing engine-mirror type from resources/js/calc — the same
 * CalculationResults shape RiskCalculator produces server-side, since a
 * snapshot's `results` column is that exact object frozen, never
 * recomputed.
 */
import type { CalculationResults } from '@/calc';

export interface SnapshotSummary {
    id: number;
    name: string;
    /** The `snapshots.graph` format this one was written in. 2 and above carry labels (W62). */
    schemaVersion: number;
    createdBy: string | null;
    createdAt: string | null;
}

/** A label as it looked when the snapshot was taken — by value, never by id (W62). */
export interface CanonicalTag {
    name: string;
    slug: string;
    color: string | null;
}

/** Deliberately no email: a snapshot is readable by everyone who can view the assessment. */
export interface CanonicalAssignee {
    id: number;
    name: string;
}

export interface CanonicalNode {
    uuid: string;
    type: 'root' | 'risk' | 'attack' | 'defence';
    title: string | null;
    description: string | null;
    position: number;
    /** Absent on a v1 snapshot. Read `graph.schemaVersion` before relying on either. */
    tags?: CanonicalTag[];
    assignees?: CanonicalAssignee[];
    [key: string]: unknown;
}

export interface CanonicalGraph {
    schemaVersion: number;
    assessment: { uuid: string; currency: string; amortizationYears: number };
    root: { title: string | null; description: string | null };
    nodes: Record<string, CanonicalNode>;
    riskAttackLinks: Array<{
        risk_uuid: string;
        attack_uuid: string;
        position: number;
    }>;
    attackDefenceLinks: Array<{
        attack_uuid: string;
        defence_uuid: string;
        position: number;
        strength_mode: string | null;
        strength_fraction: number | null;
        strength_level_id: number | null;
        reduction_target: string | null;
        rationale?: string | null;
    }>;
    scales: unknown[];
    scaleLevels: unknown[];
}

export interface SnapshotDetail extends SnapshotSummary {
    note: string | null;
    graph: CanonicalGraph;
    results: CalculationResults;
}

export interface DiffFieldChange {
    field: string;
    from: unknown;
    to: unknown;
}

export interface DiffNodeRef {
    uuid: string;
    type: string | null;
    title: string | null;
}

export interface DiffNodeChange extends DiffNodeRef {
    fields: DiffFieldChange[];
}

export interface DiffEdgeChange {
    edge: Record<string, unknown>;
    fields: DiffFieldChange[];
}

export interface SnapshotDiffData {
    from: { id: number; name: string; createdAt: string | null };
    to: { id: number; name: string; createdAt: string | null };
    nodes: {
        added: DiffNodeRef[];
        removed: DiffNodeRef[];
        changed: DiffNodeChange[];
    };
    riskAttackLinks: {
        added: Record<string, unknown>[];
        removed: Record<string, unknown>[];
        changed: DiffEdgeChange[];
    };
    attackDefenceLinks: {
        added: Record<string, unknown>[];
        removed: Record<string, unknown>[];
        changed: DiffEdgeChange[];
    };
    totals: Record<
        'inherent' | 'current' | 'target',
        { from: number | null; to: number | null; delta: number | null }
    >;
}
