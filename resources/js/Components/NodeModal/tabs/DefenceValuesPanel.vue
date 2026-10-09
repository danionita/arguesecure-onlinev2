<script setup lang="ts">
/**
 * Defence — **Cost**, and nothing else (W72 item 9; ui.md §7).
 *
 * The tab is labelled Cost since W39 and until W72 it was not about cost. It
 * carried the status control, the transfer flag, a read-only strength summary
 * and a jump to the Strength tab; the two money fields it is named after were
 * a third of it. Three of those four have somewhere better to be:
 *
 * - **Status and the transfer flag moved to Details.** Neither is a cost.
 *   They say what this defence *is* and where it has got to, which is the
 *   question Details answers for every other type.
 * - **The strength summary is gone, not moved.** Strength is the Strength
 *   tab's whole subject, per edge, which is where it belongs (schema.md §3.6)
 *   — and that tab is one click away on the same node. Restating it here as
 *   read-only prose plus a "go and edit it over there" link was the legacy
 *   indirection in miniature: two places showing one number, only one of them
 *   editable. W39's own ui.md text asked for that summary; W72 removes it
 *   from both the tab and the spec, deliberately and in the same commit.
 *
 * What is left is capex, opex, and the annualised total that ties them
 * together through the assessment's amortisation period — the three things a
 * tab called Cost is for.
 */
import Money from '@/Components/UI/Money.vue';
import type { AssessmentStore } from '@/stores/assessmentStore';
import { computed } from 'vue';
import { useNodeModal } from '../useNodeModal';
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

function setCapex(value: string) {
    props.store.updateNode(props.uuid, {
        capex_amount: stringToNullableNumber(value),
    });
}

function setOpex(value: string) {
    props.store.updateNode(props.uuid, {
        opex_annual_amount: stringToNullableNumber(value),
    });
}

const amortizationYears = computed(
    () => props.store.assessment.amortization_years || 1,
);

/** capex annualized over the assessment's amortization period, plus opex. */
const annualizedTotal = computed(() => {
    if (!node.value) return null;
    const capex = node.value.capex_amount;
    const opex = node.value.opex_annual_amount;
    if (capex === null && opex === null) return null;
    return (capex ?? 0) / amortizationYears.value + (opex ?? 0);
});
</script>

<template>
    <div v-if="node" class="flex flex-col gap-4">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="text-text-primary mb-1 block text-sm font-medium"
                    >One-off cost (capex)</label
                >
                <div class="relative flex items-center">
                    <span class="text-text-muted absolute left-2.5 text-sm">{{
                        currency
                    }}</span>
                    <input
                        type="number"
                        min="0"
                        step="any"
                        :disabled="!canEdit"
                        :value="nullableNumberToString(node.capex_amount)"
                        class="border-border-default bg-surface-1 text-text-primary focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md pl-10 text-sm shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                        @input="
                            setCapex(($event.target as HTMLInputElement).value)
                        "
                    />
                </div>
            </div>
            <div>
                <label class="text-text-primary mb-1 block text-sm font-medium"
                    >Annual cost (opex)</label
                >
                <div class="relative flex items-center">
                    <span class="text-text-muted absolute left-2.5 text-sm">{{
                        currency
                    }}</span>
                    <input
                        type="number"
                        min="0"
                        step="any"
                        :disabled="!canEdit"
                        :value="nullableNumberToString(node.opex_annual_amount)"
                        class="border-border-default bg-surface-1 text-text-primary focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md pl-10 text-sm shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                        @input="
                            setOpex(($event.target as HTMLInputElement).value)
                        "
                    />
                </div>
            </div>
        </div>

        <p class="text-text-muted text-xs">
            Annualized total (capex over
            {{ amortizationYears }} year{{ amortizationYears === 1 ? '' : 's' }}
            + opex):
            <Money :value="annualizedTotal" :currency="currency" />
        </p>
    </div>
</template>
