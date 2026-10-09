<script setup lang="ts">
/**
 * Diffs two snapshots (PLAN.md Phase 8 item 3 "Diff two snapshots — match
 * nodes on uuid... show added, removed and changed nodes and edges, plus
 * the movement in each headline figure"). Everything here is exactly what
 * SnapshotDiffer::diff() computed server-side from the two snapshots'
 * already-frozen `graph`/`results` columns — no recomputation happens in
 * this component either.
 */
import type { SnapshotDiffData } from '@/Components/Snapshots/types';
import Chip from '@/Components/UI/Chip.vue';
import Money from '@/Components/UI/Money.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    assessment: { uuid: string; currency: string };
    diff: SnapshotDiffData;
}>();

const baselineLabels: Record<string, string> = {
    inherent: 'Inherent',
    current: 'Current',
    target: 'Target',
};

function formatValue(value: unknown): string {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'boolean') return value ? 'true' : 'false';
    return String(value);
}
</script>

<template>
    <Head title="Compare versions" />

    <div class="bg-surface-0 min-h-screen">
        <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6">
            <Link
                :href="route('assessments.history.index', assessment.uuid)"
                class="text-text-muted hover:text-text-primary text-xs"
            >
                ← Back to history
            </Link>

            <h1 class="text-text-primary mt-1 text-xl font-semibold">
                {{ diff.from.name }} → {{ diff.to.name }}
            </h1>

            <!-- Headline figures -->
            <div
                class="border-border-subtle bg-surface-1 mt-6 grid gap-4 rounded-lg border p-4 sm:grid-cols-3"
            >
                <div
                    v-for="baseline in [
                        'inherent',
                        'current',
                        'target',
                    ] as const"
                    :key="baseline"
                >
                    <div
                        class="text-text-muted text-xs font-medium tracking-wide uppercase"
                    >
                        {{ baselineLabels[baseline] }}
                    </div>
                    <div class="mt-1 flex items-baseline gap-2 text-sm">
                        <Money
                            :value="diff.totals[baseline].from"
                            :currency="assessment.currency"
                        />
                        <span class="text-text-muted">→</span>
                        <Money
                            :value="diff.totals[baseline].to"
                            :currency="assessment.currency"
                        />
                    </div>
                    <div
                        v-if="diff.totals[baseline].delta !== null"
                        class="text-xs"
                        :class="
                            diff.totals[baseline].delta! > 0
                                ? 'text-node-attack-text'
                                : 'text-status-implemented-text'
                        "
                    >
                        {{ diff.totals[baseline].delta! > 0 ? '+' : ''
                        }}<Money
                            :value="diff.totals[baseline].delta"
                            :currency="assessment.currency"
                        />
                    </div>
                </div>
            </div>

            <!-- Nodes -->
            <div
                class="border-border-subtle bg-surface-1 mt-6 rounded-lg border p-4"
            >
                <h2 class="text-text-primary text-sm font-semibold">Nodes</h2>

                <div
                    v-if="
                        diff.nodes.added.length === 0 &&
                        diff.nodes.removed.length === 0 &&
                        diff.nodes.changed.length === 0
                    "
                    class="text-text-muted mt-2 text-sm"
                >
                    No structural changes.
                </div>

                <ul v-else class="mt-2 space-y-1.5 text-sm">
                    <li
                        v-for="node in diff.nodes.added"
                        :key="'add-' + node.uuid"
                        class="flex items-center gap-2"
                    >
                        <Chip tone="implemented">Added</Chip>
                        <span class="text-text-primary">{{
                            node.title ?? node.uuid
                        }}</span>
                        <span class="text-text-muted text-xs"
                            >({{ node.type }})</span
                        >
                    </li>
                    <li
                        v-for="node in diff.nodes.removed"
                        :key="'rem-' + node.uuid"
                        class="flex items-center gap-2"
                    >
                        <Chip tone="attack">Removed</Chip>
                        <span class="text-text-primary">{{
                            node.title ?? node.uuid
                        }}</span>
                        <span class="text-text-muted text-xs"
                            >({{ node.type }})</span
                        >
                    </li>
                    <li
                        v-for="node in diff.nodes.changed"
                        :key="'chg-' + node.uuid"
                    >
                        <div class="flex items-center gap-2">
                            <Chip tone="todo">Changed</Chip>
                            <span class="text-text-primary">{{
                                node.title ?? node.uuid
                            }}</span>
                            <span class="text-text-muted text-xs"
                                >({{ node.type }})</span
                            >
                        </div>
                        <ul
                            class="text-text-muted mt-1 ml-16 space-y-0.5 text-xs"
                        >
                            <li
                                v-for="change in node.fields"
                                :key="change.field"
                            >
                                {{ change.field }}:
                                {{ formatValue(change.from) }} →
                                {{ formatValue(change.to) }}
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>

            <!-- Edges -->
            <div
                class="border-border-subtle bg-surface-1 mt-6 rounded-lg border p-4"
            >
                <h2 class="text-text-primary text-sm font-semibold">
                    Risk → attack links
                </h2>
                <div
                    v-if="
                        diff.riskAttackLinks.added.length === 0 &&
                        diff.riskAttackLinks.removed.length === 0 &&
                        diff.riskAttackLinks.changed.length === 0
                    "
                    class="text-text-muted mt-2 text-sm"
                >
                    No changes.
                </div>
                <ul v-else class="mt-2 space-y-1 text-xs">
                    <li
                        v-for="(edge, i) in diff.riskAttackLinks.added"
                        :key="'ra-add-' + i"
                    >
                        <Chip tone="implemented">Added</Chip>
                        {{ edge.risk_uuid }} → {{ edge.attack_uuid }}
                    </li>
                    <li
                        v-for="(edge, i) in diff.riskAttackLinks.removed"
                        :key="'ra-rem-' + i"
                    >
                        <Chip tone="attack">Removed</Chip>
                        {{ edge.risk_uuid }} → {{ edge.attack_uuid }}
                    </li>
                </ul>
            </div>

            <div
                class="border-border-subtle bg-surface-1 mt-6 rounded-lg border p-4"
            >
                <h2 class="text-text-primary text-sm font-semibold">
                    Attack → defence links
                </h2>
                <div
                    v-if="
                        diff.attackDefenceLinks.added.length === 0 &&
                        diff.attackDefenceLinks.removed.length === 0 &&
                        diff.attackDefenceLinks.changed.length === 0
                    "
                    class="text-text-muted mt-2 text-sm"
                >
                    No changes.
                </div>
                <ul v-else class="mt-2 space-y-1.5 text-xs">
                    <li
                        v-for="(edge, i) in diff.attackDefenceLinks.added"
                        :key="'ad-add-' + i"
                    >
                        <Chip tone="implemented">Added</Chip>
                        {{ edge.attack_uuid }} → {{ edge.defence_uuid }}
                    </li>
                    <li
                        v-for="(edge, i) in diff.attackDefenceLinks.removed"
                        :key="'ad-rem-' + i"
                    >
                        <Chip tone="attack">Removed</Chip>
                        {{ edge.attack_uuid }} → {{ edge.defence_uuid }}
                    </li>
                    <li
                        v-for="(entry, i) in diff.attackDefenceLinks.changed"
                        :key="'ad-chg-' + i"
                    >
                        <div>
                            <Chip tone="todo">Changed</Chip>
                            {{ entry.edge.attack_uuid }} →
                            {{ entry.edge.defence_uuid }}
                        </div>
                        <ul class="text-text-muted mt-1 ml-16 space-y-0.5">
                            <li
                                v-for="change in entry.fields"
                                :key="change.field"
                            >
                                {{ change.field }}:
                                {{ formatValue(change.from) }} →
                                {{ formatValue(change.to) }}
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
