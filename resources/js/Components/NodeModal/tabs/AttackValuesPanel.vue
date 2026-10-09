<script setup lang="ts">
/**
 * Attack — Likelihood (ui.md §7, calculation-engine.md §8.5). Labelled
 * "how often per year" with the live hint
 * `p = 1 − e^(−ARO)` ("≈ N% chance of at least one occurrence this year")
 * — workshop participants think in probabilities, ALE needs rates, and
 * values above 1 are legitimate and must not be rejected (schema.md §3.4:
 * `likelihood_aro` "occurrences per year, ≥ 0, may exceed 1").
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

const { levelsForKind, isUncalibrated } = useScales();
const likelihoodLevels = computed(() => levelsForKind('likelihood'));

const modeOptions = [
    { value: 'quantitative', label: 'Quantitative' },
    { value: 'qualitative', label: 'Qualitative' },
];

function setMode(mode: string) {
    if (!node.value) return;
    props.store.updateNode(props.uuid, {
        likelihood_mode: mode as ValueMode,
        likelihood_aro:
            mode === 'quantitative' ? node.value.likelihood_aro : null,
        likelihood_level_id:
            mode === 'qualitative' ? node.value.likelihood_level_id : null,
    });
}

function setAro(value: string) {
    const aro = stringToNullableNumber(value);

    props.store.updateNode(props.uuid, {
        likelihood_aro: aro,
        // W25: the mode must travel with the number.
        //
        // The segmented control above renders `node.likelihood_mode ??
        // 'quantitative'`, so a brand-new attack *looks* like it is already
        // in quantitative mode — and a user typing a rate therefore never
        // clicks it, which used to mean setMode() never ran and
        // `likelihood_mode` stayed NULL. calculation-engine.md §6.1 treats a
        // null mode as *missing*, so the attack was excluded from every
        // total: the figure was accepted, echoed back and displayed on the
        // card, and the assessment still reported zero. Filling in an entire
        // assessment by hand produced €0 → €0 → €0.
        //
        // Setting it here rather than defaulting the column in the database
        // keeps "the user has not said which mode this is" distinguishable
        // from "the user chose quantitative", which is the distinction the
        // missing-value chip depends on — an untouched attack must still read
        // as missing rather than as a quantitative zero.
        //
        // Clearing the field clears the mode with it, for the same reason:
        // an emptied value has to go back to missing, not to "quantitative,
        // unset".
        likelihood_mode: aro === null ? null : 'quantitative',
    });
}

function setLevel(value: string) {
    props.store.updateNode(props.uuid, {
        likelihood_level_id: value === '' ? null : Number(value),
    });
}

const levelOptions = computed(() =>
    likelihoodLevels.value.map((level) => ({
        value: String(level.id),
        label: `${level.label} (${level.lower_bound}${level.upper_bound === null ? '+' : `–${level.upper_bound}`}/yr)`,
    })),
);

/** p = 1 - e^(-ARO), calculation-engine.md §8.5. */
const approxProbability = computed(() => {
    const aro = node.value?.likelihood_aro;
    if (aro === null || aro === undefined) return null;
    return 1 - Math.exp(-aro);
});
</script>

<template>
    <div v-if="node" class="flex flex-col gap-3">
        <SegmentedControl
            :model-value="node.likelihood_mode ?? 'quantitative'"
            :options="modeOptions"
            @update:model-value="setMode"
        />

        <p
            v-if="isUncalibrated('likelihood')"
            class="text-status-todo-text bg-status-todo-bg border-status-todo-border rounded-md border px-2 py-1 text-xs"
        >
            This assessment still uses the unmodified system likelihood scale.
        </p>

        <div v-if="(node.likelihood_mode ?? 'quantitative') === 'quantitative'">
            <label class="text-text-primary mb-1 block text-sm font-medium">
                How often per year
            </label>
            <p class="text-text-muted mb-1 text-xs">
                A rate, not a probability — values above 1 are legitimate (e.g.
                3/yr means it typically happens three times a year).
            </p>
            <div class="relative flex items-center">
                <input
                    type="number"
                    min="0"
                    step="any"
                    :disabled="!canEdit"
                    :value="nullableNumberToString(node.likelihood_aro)"
                    placeholder="Not yet entered"
                    class="border-border-default bg-surface-1 text-text-primary placeholder:text-text-muted focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md pr-10 text-sm shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                    @input="setAro(($event.target as HTMLInputElement).value)"
                />
                <span class="text-text-muted absolute right-2.5 text-sm"
                    >/yr</span
                >
            </div>
            <p
                v-if="approxProbability !== null"
                class="text-text-muted mt-1 text-xs"
            >
                ≈ {{ (approxProbability * 100).toFixed(0) }}% chance of at least
                one occurrence this year
            </p>
        </div>

        <div v-else>
            <Select
                label="Likelihood level"
                :model-value="
                    node.likelihood_level_id === null
                        ? ''
                        : String(node.likelihood_level_id)
                "
                :options="levelOptions"
                placeholder="Not yet entered"
                :disabled="!canEdit"
                @update:model-value="setLevel"
            />
        </div>

        <div
            v-if="
                node.likelihood_mode === null ||
                (node.likelihood_mode === 'quantitative' &&
                    node.likelihood_aro === null) ||
                (node.likelihood_mode === 'qualitative' &&
                    node.likelihood_level_id === null)
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
