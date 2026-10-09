<script setup lang="ts">
/**
 * Statistics: everything the engine computed about this node, read-only, all
 * three baselines at once (W39, PLAN-2.2.md item 11).
 *
 * The division of labour with the value tabs next door is the point. Impact,
 * Likelihood, Cost and Strength are where a human *enters* something.
 * Statistics is where the machine answers. Nothing here is editable, and
 * nothing here is a number anyone typed — which is why the impact figure a
 * risk was given does not appear on this tab even though it is arithmetically
 * involved in every row of it.
 *
 * The rows themselves are built in `statistics.ts`, which is pure and
 * separately tested. This file supplies the two things that module refuses to
 * import: the store, and the assessment's currency.
 */
import type { DefenceStatus } from '@/calc/types';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { formatMoney, formatPercent } from '@/Components/UI/format';
import Money from '@/Components/UI/Money.vue';
import Tooltip from '@/Components/UI/Tooltip.vue';
import { reductionChipLabel } from '@/constants/valueHints';
import type { AssessmentStore } from '@/stores/assessmentStore';
import { computed } from 'vue';
import { useScales } from '../useScales';
import BaselineStatTable from './BaselineStatTable.vue';
import {
    attackStatRows,
    completenessNote,
    defenceCostBucketNote,
    defenceCostLines,
    defenceStatRows,
    plannedImprovementNote,
    riskStatRows,
    rootStatRows,
    type StatCell,
    type StatFormatters,
} from './statistics';

const props = defineProps<{
    store: AssessmentStore;
    uuid: string;
}>();

const node = computed(() => props.store.nodes[props.uuid]);
const results = computed(() => props.store.results.value);
const currency = computed(() => props.store.assessment.currency);
const { levelById } = useScales();

/**
 * A hint that quotes a figure has to quote the *same string* the cell shows,
 * or the tab reads as two sources disagreeing to one decimal place. Hence one
 * formatter pair, passed down, rather than each call site reaching for
 * `formatMoney` with its own options.
 */
const fmt = computed<StatFormatters>(() => ({
    money: (value) => formatMoney(value, { currency: currency.value }) ?? '—',
    percent: (value) => formatPercent(value) ?? '—',
}));

const rows = computed(() => {
    const n = node.value;
    if (!n) return [];

    if (n.type === 'root') return rootStatRows(results.value, fmt.value);
    if (n.type === 'risk')
        return riskStatRows(results.value, props.uuid, fmt.value);
    if (n.type === 'attack') return attackStatRows(results.value, props.uuid);

    return defenceStatRows(
        results.value,
        props.uuid,
        (n.status ?? 'todo') as DefenceStatus,
    );
});

const caption = computed(() =>
    node.value?.type === 'root'
        ? 'Assessment totals by baseline'
        : `Computed figures for ${node.value?.title || 'this node'}, by baseline`,
);

/** See BaselineStatTable: a union type inline in a template reads as a filter. */
function numeric(cell: StatCell): number | null {
    return typeof cell.value === 'number' ? cell.value : null;
}

// --- root only -------------------------------------------------------------

const plannedNote = computed(() =>
    node.value?.type === 'root'
        ? plannedImprovementNote(results.value, fmt.value)
        : null,
);

const coverageNote = computed(() =>
    node.value?.type === 'root' ? completenessNote(results.value) : null,
);

// --- defence only ----------------------------------------------------------

const isDefence = computed(() => node.value?.type === 'defence');

const costLines = computed(() =>
    isDefence.value && node.value
        ? defenceCostLines(
              results.value,
              props.uuid,
              node.value,
              props.store.assessment.amortization_years,
          )
        : [],
);

const costNote = computed(() =>
    isDefence.value ? defenceCostBucketNote(results.value, props.uuid) : null,
);

/**
 * Strength is per edge, so a defence has as many strengths as it has attacks
 * (CLAUDE.md: "one control may be 80% effective against one attack and 20%
 * against another"). Reading the cost-benefit rows above without seeing that
 * spread invites the wrong conclusion about where the number came from.
 *
 * Read-only, deliberately: the Strength tab beside this one is the editor, and
 * two editable copies of one field is how they drift.
 */
const edges = computed(() => {
    if (!isDefence.value) return [];

    return props.store.attacksForDefence(props.uuid).map((attackUuid) => {
        const link = props.store.attackDefenceLinks.find(
            (l) =>
                l.attack_uuid === attackUuid && l.defence_uuid === props.uuid,
        );
        const fraction =
            link?.strength_fraction ??
            levelById(link?.strength_level_id ?? null)?.representative ??
            null;

        return {
            uuid: attackUuid,
            title: props.store.nodes[attackUuid]?.title || 'Untitled attack',
            label: reductionChipLabel(
                link?.reduction_target ?? null,
                formatPercent(fraction),
            ),
        };
    });
});
</script>

<template>
    <div v-if="node" class="space-y-6 p-4">
        <BaselineStatTable
            :rows="rows"
            :currency="currency"
            :caption="caption"
        />

        <p v-if="plannedNote" class="text-text-secondary text-sm">
            {{ plannedNote }}
        </p>
        <p v-if="coverageNote" class="text-text-muted text-xs">
            {{ coverageNote }}
        </p>

        <section v-if="isDefence" class="space-y-2">
            <h3 class="text-text-primary text-sm font-medium">Cost</h3>
            <dl class="space-y-1 text-sm">
                <div
                    v-for="line in costLines"
                    :key="line.key"
                    class="flex items-baseline justify-between gap-4"
                    :data-cost-line="line.key"
                >
                    <dt class="text-text-secondary">
                        <Tooltip v-if="line.hint" :text="line.hint">
                            <span
                                class="border-border-strong border-b border-dotted"
                            >
                                {{ line.label }}
                            </span>
                        </Tooltip>
                        <span v-else>{{ line.label }}</span>
                    </dt>
                    <dd class="text-text-primary">
                        <Money
                            :value="numeric(line.cell)"
                            :currency="currency"
                        />
                    </dd>
                </div>
            </dl>
            <p v-if="costNote" class="text-text-muted text-xs">
                {{ costNote }}
            </p>
        </section>

        <section v-if="isDefence" class="space-y-2">
            <h3 class="text-text-primary text-sm font-medium">
                Applies to
                <span class="text-text-muted font-normal"
                    >— edit these on the Strength tab</span
                >
            </h3>
            <ul v-if="edges.length" class="space-y-1 text-sm">
                <li
                    v-for="edge in edges"
                    :key="edge.uuid"
                    class="flex items-baseline justify-between gap-4"
                    :data-edge="edge.uuid"
                >
                    <span class="text-text-primary">{{ edge.title }}</span>
                    <span class="text-text-secondary text-xs">{{
                        edge.label
                    }}</span>
                </li>
            </ul>
            <EmptyState
                v-else
                icon="link-2"
                title="Not linked to any attack"
                description="A defence only reduces risk through the attacks it is linked to, so nothing above can be computed yet."
            />
        </section>
    </div>
</template>
