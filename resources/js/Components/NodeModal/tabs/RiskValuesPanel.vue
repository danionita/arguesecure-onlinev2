<script setup lang="ts">
/**
 * Risk — Impact (ui.md §7). "The field label must read 'loss each time
 * this occurs', with helper text distinguishing it from annual loss. This
 * is the single most consequential input error available in the tool."
 * calculation-engine.md §6.1: a risk is *missing* (excluded from every
 * total, never coerced to 0) whenever `impact_mode` is null, or the
 * mode-appropriate value column is null — which is exactly what leaving
 * the number input blank now produces via stringToNullableNumber().
 */
import Chip from '@/Components/UI/Chip.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import Select from '@/Components/UI/Select.vue';
import type { AssessmentStore, ValueMode } from '@/stores/assessmentStore';
import { computed } from 'vue';
import { useNodeModal } from '../useNodeModal';
import { useScales } from '../useScales';
import { nullableNumberToString, stringToNullableNumber } from './numeric';

const props = defineProps<{
    store: AssessmentStore;
    uuid: string;
}>();

const node = computed(() => props.store.nodes[props.uuid]);
// False while someone else has this node open (2.5.1, useNodeModal).
const { lockedBy } = useNodeModal();
const canEdit = computed(
    () => props.store.abilities.update && lockedBy.value === null,
);
const currency = computed(() => props.store.assessment.currency);

const { levelsForKind, isUncalibrated } = useScales();
const impactLevels = computed(() => levelsForKind('impact'));

const modeOptions = [
    { value: 'quantitative', label: 'Quantitative' },
    { value: 'qualitative', label: 'Qualitative' },
];

function setMode(mode: string) {
    if (!node.value) return;
    // Switching mode clears the other mode's value column rather than
    // leaving a stale number/level behind that the CHECK constraint
    // (schema.md §3.4 chk_nodes_impact_mode) would otherwise reject if
    // both ever reached the server at once.
    props.store.updateNode(props.uuid, {
        impact_mode: mode as ValueMode,
        impact_amount:
            mode === 'quantitative' ? node.value.impact_amount : null,
        impact_level_id:
            mode === 'qualitative' ? node.value.impact_level_id : null,
    });
}

function setAmount(value: string) {
    const amount = stringToNullableNumber(value);

    props.store.updateNode(props.uuid, {
        impact_amount: amount,
        // W25: the mode must travel with the number — see the identical
        // note in AttackValuesPanel.vue's setAro() for the full reasoning.
        // In short: the control above displays `impact_mode ??
        // 'quantitative'`, so nobody clicks it, so setMode() never fired and
        // the column stayed NULL, which calculation-engine.md §6.1 reads as
        // *missing* — a risk with an impact typed into it was excluded from
        // every total.
        impact_mode: amount === null ? null : 'quantitative',
    });
}

function setLevel(value: string) {
    props.store.updateNode(props.uuid, {
        impact_level_id: value === '' ? null : Number(value),
    });
}

const levelOptions = computed(() =>
    impactLevels.value.map((level) => ({
        value: String(level.id),
        label: `${level.label} (${level.lower_bound.toLocaleString()}${level.upper_bound === null ? '+' : `–${level.upper_bound.toLocaleString()}`})`,
    })),
);
</script>

<template>
    <div v-if="node" class="flex flex-col gap-3">
        <SegmentedControl
            :model-value="node.impact_mode ?? 'quantitative'"
            :options="modeOptions"
            @update:model-value="setMode"
        />

        <p
            v-if="isUncalibrated('impact')"
            class="text-status-todo-text bg-status-todo-bg border-status-todo-border rounded-md border px-2 py-1 text-xs"
        >
            This assessment still uses the unmodified shared impact bands.
        </p>

        <div v-if="(node.impact_mode ?? 'quantitative') === 'quantitative'">
            <label class="text-text-primary mb-1 block text-sm font-medium">
                Loss each time this occurs
            </label>
            <p class="text-text-muted mb-1 text-xs">
                Impact is per-occurrence, not annual loss — the assessment total
                multiplies this by how often the attack happens.
            </p>
            <div class="relative flex items-center">
                <span class="text-text-muted absolute left-2.5 text-sm">{{
                    currency
                }}</span>
                <input
                    type="number"
                    min="0"
                    step="any"
                    :disabled="!canEdit"
                    :value="nullableNumberToString(node.impact_amount)"
                    placeholder="Not yet entered"
                    class="border-border-default bg-surface-1 text-text-primary placeholder:text-text-muted focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md pl-10 text-sm shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                    @input="
                        setAmount(($event.target as HTMLInputElement).value)
                    "
                />
            </div>
        </div>

        <div v-else>
            <Select
                label="Impact level"
                :model-value="
                    node.impact_level_id === null
                        ? ''
                        : String(node.impact_level_id)
                "
                :options="levelOptions"
                placeholder="Not yet entered"
                :disabled="!canEdit"
                @update:model-value="setLevel"
            />
        </div>

        <div
            v-if="
                node.impact_mode === null ||
                (node.impact_mode === 'quantitative' &&
                    node.impact_amount === null) ||
                (node.impact_mode === 'qualitative' &&
                    node.impact_level_id === null)
            "
            class="flex items-center gap-1.5"
        >
            <Chip missing />
            <span class="text-text-muted text-xs"
                >Missing — excluded from every total until set (never treated as
                zero)</span
            >
        </div>
    </div>
</template>
