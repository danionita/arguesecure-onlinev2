<script setup lang="ts">
/**
 * Everything that lives on one `attack_defence` edge: strength (quantitative
 * fraction or qualitative level), reduction target, and the rationale
 * (W31, ui.md §7, schema.md §3.6 — "strength lives on the pivot, not on the
 * defence, because one control may be 80% effective against one attack and
 * 20% against another").
 *
 * Extracted from LinksTab.vue so the *same* editor renders from both ends of
 * the edge. Before this, a defence's own Links tab listed the attacks it
 * applied to with a read-only percentage and a line of prose telling the user
 * to go and open the attack instead — the legacy app's indirection rebuilt:
 * to change one number, navigate to a different node. There is one row in
 * `attack_defence` and there should be one editor for it; only the heading
 * around it differs.
 *
 * **Strength comes before the reduction target, and the order is pinned**
 * (W72 item 9). The tab is named after strength; the reduction target is a
 * refinement of a reduction that has to exist first, and it does not move the
 * assessment total at all (calculation-engine.md — everything multiplies).
 * Asking about the refinement above the thing it refines is the wrong order
 * to read the edge in. LinksTab.spec.ts asserts the label sequence rather
 * than the presence of the controls, so reordering this template turns it red.
 */
import Percent from '@/Components/UI/Percent.vue';
import Select from '@/Components/UI/Select.vue';
import type { AssessmentStore } from '@/stores/assessmentStore';
import { computed } from 'vue';
import { nullableNumberToString, stringToNullableNumber } from './tabs/numeric';
import { useLinkMutations } from './useLinkMutations';
import { useScales } from './useScales';

const props = defineProps<{
    store: AssessmentStore;
    attackUuid: string;
    defenceUuid: string;
    canEdit: boolean;
}>();

const mutations = useLinkMutations(props.store);
const { levelsForKind } = useScales();

const strengthLevelOptions = computed(() =>
    levelsForKind('strength').map((level) => ({
        value: String(level.id),
        label: level.label,
    })),
);

const reductionTargetOptions = [
    { value: 'likelihood', label: 'Reduces likelihood' },
    { value: 'impact', label: 'Reduces impact' },
];

const link = computed(() =>
    props.store.attackDefenceLinks.find(
        (l) =>
            l.attack_uuid === props.attackUuid &&
            l.defence_uuid === props.defenceUuid,
    ),
);

const strengthMode = computed(() => link.value?.strength_mode ?? '');
const strengthLevelId = computed(() =>
    link.value?.strength_level_id === null ||
    link.value?.strength_level_id === undefined
        ? ''
        : String(link.value.strength_level_id),
);
const strengthFraction = computed(() =>
    nullableNumberToString(link.value?.strength_fraction ?? null),
);

// W31: read straight off the store. The tab used to keep a local draft map
// because AssessmentGraphLoader did not select this column, so a saved
// rationale could never be displayed again after a reload — it does select
// it now, and the loader carries it through to the page props.
const rationale = computed(() => link.value?.rationale ?? '');

function setStrengthMode(mode: string) {
    mutations.updateAttackDefenceLink(props.attackUuid, props.defenceUuid, {
        strength_mode:
            mode === '' ? null : (mode as 'quantitative' | 'qualitative'),
        strength_fraction:
            mode === 'quantitative'
                ? (link.value?.strength_fraction ?? null)
                : null,
        strength_level_id:
            mode === 'qualitative'
                ? (link.value?.strength_level_id ?? null)
                : null,
    });
}

function setStrengthFraction(value: string) {
    mutations.updateAttackDefenceLink(props.attackUuid, props.defenceUuid, {
        strength_mode: 'quantitative',
        strength_fraction: stringToNullableNumber(value),
    });
}

function setStrengthLevel(value: string) {
    mutations.updateAttackDefenceLink(props.attackUuid, props.defenceUuid, {
        strength_mode: 'qualitative',
        strength_level_id: value === '' ? null : Number(value),
    });
}

function setReductionTarget(value: string) {
    mutations.updateAttackDefenceLink(props.attackUuid, props.defenceUuid, {
        reduction_target:
            value === '' ? null : (value as 'likelihood' | 'impact'),
    });
}

function commitRationale(value: string) {
    mutations.updateAttackDefenceLink(props.attackUuid, props.defenceUuid, {
        rationale: value.trim() === '' ? null : value,
    });
}
</script>

<template>
    <div>
        <div class="grid grid-cols-2 gap-2">
            <Select
                label="Strength mode"
                :model-value="strengthMode"
                :options="[
                    { value: 'quantitative', label: 'Quantitative' },
                    { value: 'qualitative', label: 'Qualitative' },
                ]"
                placeholder="Not yet stated"
                :disabled="!canEdit"
                @update:model-value="setStrengthMode"
            />

            <div v-if="strengthMode === 'qualitative'">
                <Select
                    label="Strength level"
                    :model-value="strengthLevelId"
                    :options="strengthLevelOptions"
                    placeholder="Not yet entered"
                    :disabled="!canEdit"
                    @update:model-value="setStrengthLevel"
                />
            </div>
            <div v-else>
                <label class="text-text-primary mb-1 block text-sm font-medium"
                    >Strength fraction</label
                >
                <div class="relative flex items-center">
                    <input
                        type="number"
                        min="0"
                        max="1"
                        step="any"
                        :disabled="!canEdit"
                        :value="strengthFraction"
                        placeholder="0 – 1"
                        class="border-border-default bg-surface-1 text-text-primary focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md text-sm shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                        @input="
                            setStrengthFraction(
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <Percent
                        v-if="link?.strength_fraction != null"
                        class="absolute right-2.5"
                        :value="link.strength_fraction"
                    />
                </div>
            </div>
        </div>

        <div class="mt-2">
            <Select
                label="Reduction target"
                :model-value="link?.reduction_target ?? ''"
                :options="reductionTargetOptions"
                placeholder="Reduces overall loss (default)"
                :disabled="!canEdit"
                @update:model-value="setReductionTarget"
            />
            <p class="text-text-muted mt-1 text-xs">
                This choice affects the intermediate figures shown on the attack
                and risk — the residual likelihood and the retained impact — but
                not the assessment total, because all reductions multiply
                together.
            </p>
        </div>

        <div class="mt-2">
            <label class="text-text-primary mb-1 block text-sm font-medium"
                >Rationale</label
            >
            <input
                type="text"
                maxlength="500"
                :disabled="!canEdit"
                :value="rationale"
                placeholder="Why this strength — matters for defensibility in a report"
                class="border-border-default bg-surface-1 text-text-primary placeholder:text-text-muted focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md text-sm shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                @change="
                    commitRationale(($event.target as HTMLInputElement).value)
                "
            />
        </div>
    </div>
</template>
