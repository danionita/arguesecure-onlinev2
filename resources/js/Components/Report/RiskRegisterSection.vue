<script setup lang="ts">
/**
 * Risk register (PLAN.md Phase 7 §3): every risk with impact, contributing
 * attacks, current and target ALE, and the reduction.
 */
import Chip from '../UI/Chip.vue';
import Money from '../UI/Money.vue';
import Percent from '../UI/Percent.vue';
import type { ReportBaseline, RiskRegisterRow } from './types';

const props = defineProps<{
    risks: RiskRegisterRow[];
    baseline: ReportBaseline;
    currency: string;
}>();

const showCurrent = props.baseline !== 'target';
const showTarget = props.baseline !== 'current';
</script>

<template>
    <section class="report-section" aria-labelledby="risk-register-heading">
        <h2
            id="risk-register-heading"
            class="text-text-primary text-xl font-semibold"
        >
            Risk register
        </h2>

        <div class="mt-4 space-y-4">
            <article
                v-for="risk in risks"
                :key="risk.uuid"
                class="report-card border-border-subtle break-inside-avoid rounded-md border p-3"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 class="text-text-primary font-semibold">
                            {{ risk.title }}
                        </h3>
                        <div class="text-text-muted mt-0.5 text-xs">
                            Impact:
                            <Money
                                v-if="risk.impactMode === 'quantitative'"
                                :value="risk.impactAmount"
                                :currency="currency"
                            />
                            <span v-else-if="risk.impactLevelCode">{{
                                risk.impactLevelCode
                            }}</span>
                            <Chip v-else missing />
                            each occurrence
                        </div>
                    </div>
                    <div class="flex gap-4 text-right">
                        <div v-if="showCurrent">
                            <div class="text-text-muted text-[10px] uppercase">
                                Current ALE
                            </div>
                            <Money
                                :value="risk.current.ale"
                                :currency="currency"
                            />
                            <div class="text-text-muted text-[10px]">
                                <Percent :value="risk.reductionCurrent" />
                                reduced
                            </div>
                        </div>
                        <div v-if="showTarget">
                            <div class="text-text-muted text-[10px] uppercase">
                                Target ALE
                            </div>
                            <Money
                                :value="risk.target.ale"
                                :currency="currency"
                            />
                            <div class="text-text-muted text-[10px]">
                                <Percent :value="risk.reductionTarget" />
                                reduced
                            </div>
                        </div>
                    </div>
                </div>

                <table class="mt-3 w-full text-left text-xs">
                    <thead>
                        <tr
                            class="text-text-muted border-border-subtle border-b"
                        >
                            <th class="py-1 font-medium">
                                Contributing attack
                            </th>
                            <th
                                v-if="showCurrent"
                                class="py-1 text-right font-medium"
                            >
                                Current ALE
                            </th>
                            <th
                                v-if="showTarget"
                                class="py-1 text-right font-medium"
                            >
                                Target ALE
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="attack in risk.attacks"
                            :key="attack.uuid"
                            class="border-border-subtle border-b last:border-0"
                        >
                            <td class="text-text-secondary py-1">
                                {{ attack.title }}
                            </td>
                            <td v-if="showCurrent" class="py-1 text-right">
                                <Money
                                    v-if="attack.currentValued"
                                    :value="attack.currentAle"
                                    :currency="currency"
                                />
                                <Chip v-else missing />
                            </td>
                            <td v-if="showTarget" class="py-1 text-right">
                                <Money
                                    v-if="attack.targetValued"
                                    :value="attack.targetAle"
                                    :currency="currency"
                                />
                                <Chip v-else missing />
                            </td>
                        </tr>
                        <tr v-if="risk.attacks.length === 0">
                            <td
                                :colspan="
                                    1 + Number(showCurrent) + Number(showTarget)
                                "
                                class="text-text-muted py-1 italic"
                            >
                                No attack paths identified — contributes nothing
                                to the total.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </article>
        </div>
    </section>
</template>
