<script setup lang="ts">
/**
 * Executive summary (PLAN.md Phase 7 §2): inherent / current / target
 * annual loss, the reduction each buys, and the total annual cost of
 * controls. Baseline visibility follows the chosen report option
 * (`options.baseline`) — inherent is always shown, since both the current
 * and the target reduction figures are meaningless without it.
 */
import Money from '../UI/Money.vue';
import Percent from '../UI/Percent.vue';
import BaselineBarChart from './BaselineBarChart.vue';
import ReportTotal from './ReportTotal.vue';
import type { ExecutiveSummary, ReportBaseline } from './types';

const props = defineProps<{
    summary: ExecutiveSummary;
    baseline: ReportBaseline;
    currency: string;
    /** W44. Passed straight through to the chart, which draws the line. */
    riskTolerance?: number | null;
}>();

const showCurrent = props.baseline !== 'target';
const showTarget = props.baseline !== 'current';
</script>

<template>
    <section
        class="report-section break-inside-avoid"
        aria-labelledby="exec-heading"
    >
        <h2 id="exec-heading" class="text-text-primary text-xl font-semibold">
            Executive summary
        </h2>

        <div class="mt-4 grid gap-6 sm:grid-cols-3">
            <ReportTotal
                :block="summary.inherent"
                :currency="currency"
                show-baseline-label
                size="lg"
            />
            <ReportTotal
                v-if="showCurrent"
                :block="summary.current"
                :currency="currency"
                show-baseline-label
                size="lg"
            />
            <ReportTotal
                v-if="showTarget"
                :block="summary.target"
                :currency="currency"
                show-baseline-label
                size="lg"
            />
        </div>

        <div class="mt-6 grid gap-6 sm:grid-cols-2">
            <div>
                <div class="text-text-muted text-xs font-medium uppercase">
                    Reduction bought
                </div>
                <dl class="mt-1 space-y-1 text-sm">
                    <div v-if="showCurrent" class="flex items-center gap-2">
                        <dt class="text-text-secondary">Inherent → current</dt>
                        <dd class="text-text-primary font-medium">
                            <Percent :value="summary.reductionCurrent" />
                        </dd>
                    </div>
                    <div v-if="showTarget" class="flex items-center gap-2">
                        <dt class="text-text-secondary">Inherent → target</dt>
                        <dd class="text-text-primary font-medium">
                            <Percent :value="summary.reductionTarget" />
                        </dd>
                    </div>
                </dl>
            </div>

            <div>
                <div class="text-text-muted text-xs font-medium uppercase">
                    Total annual cost of controls (implemented)
                </div>
                <dl class="mt-1 space-y-1 text-sm">
                    <div class="flex items-center gap-2">
                        <dt class="text-text-secondary">Priced</dt>
                        <dd class="text-text-primary font-medium">
                            <Money
                                :value="summary.annualCostOfControls.priced"
                                :currency="currency"
                            />
                        </dd>
                    </div>
                    <div
                        v-if="summary.annualCostOfControls.unpricedCount > 0"
                        class="text-text-muted text-xs"
                    >
                        +
                        {{ summary.annualCostOfControls.unpricedCount }}
                        implemented control{{
                            summary.annualCostOfControls.unpricedCount === 1
                                ? ''
                                : 's'
                        }}
                        with no cost estimated
                    </div>
                </dl>
            </div>
        </div>

        <div class="mt-6 max-w-xl">
            <BaselineBarChart
                :blocks="[summary.inherent, summary.current, summary.target]"
                :currency="currency"
                :risk-tolerance="riskTolerance ?? null"
            />
        </div>
    </section>
</template>
