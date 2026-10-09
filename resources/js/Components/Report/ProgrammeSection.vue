<script setup lang="ts">
/**
 * What the plan costs and what it buys (W33).
 *
 * The executive summary answers "what are we exposed to" and "what do the
 * controls we already run cost". The question a reader actually arrives with
 * is the next one: *is the todo list worth doing?* That needs the planned
 * spend and the risk it removes side by side, in currency, which nothing in
 * the 2.0 report showed.
 *
 * Every figure here is a sum or a subtraction over engine output. Unpriced
 * controls are counted beside the total and never folded into it: a control
 * with no cost recorded is not a free control.
 */
import Money from '../UI/Money.vue';
import Percent from '../UI/Percent.vue';
import type { ProgrammeSummary } from './types';

const props = defineProps<{
    programme: ProgrammeSummary;
    currency: string;
}>();

const hasPlan = props.programme.planned.count > 0;
</script>

<template>
    <section
        class="report-section break-inside-avoid"
        aria-labelledby="programme-heading"
    >
        <h2
            id="programme-heading"
            class="text-text-primary text-xl font-semibold"
        >
            The plan: cost and benefit
        </h2>

        <p v-if="!hasPlan" class="text-text-secondary mt-2 text-sm">
            No defences are marked as planned, so the target state matches the
            current one and there is nothing here to cost.
        </p>

        <div v-else class="mt-4 grid gap-6 sm:grid-cols-2">
            <div>
                <div class="text-text-muted text-xs font-medium uppercase">
                    Planned controls ({{ programme.planned.count }})
                </div>
                <dl class="mt-1 space-y-1 text-sm">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-text-secondary">
                            Annualised cost
                            <span class="text-text-muted text-xs"
                                >(recurring + one-off spread over the
                                amortization period)</span
                            >
                        </dt>
                        <dd class="text-text-primary font-medium">
                            <Money
                                :value="programme.planned.annualisedCost"
                                :currency="currency"
                            />
                        </dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-text-secondary">One-off cost</dt>
                        <dd class="text-text-primary">
                            <Money
                                :value="programme.planned.oneOffCost"
                                :currency="currency"
                            />
                        </dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-text-secondary">Recurring cost</dt>
                        <dd class="text-text-primary">
                            <Money
                                :value="programme.planned.recurringCost"
                                :currency="currency"
                            />
                        </dd>
                    </div>
                    <div
                        v-if="programme.planned.unpricedCount > 0"
                        class="flex items-baseline justify-between gap-4"
                    >
                        <dt class="text-text-secondary">
                            No cost recorded
                            <span class="text-text-muted text-xs"
                                >— excluded from the totals above, not counted
                                as free</span
                            >
                        </dt>
                        <dd class="text-text-primary">
                            {{ programme.planned.unpricedCount }}
                        </dd>
                    </div>
                    <div
                        v-if="programme.planned.freeCount > 0"
                        class="flex items-baseline justify-between gap-4"
                    >
                        <dt class="text-text-secondary">Recorded as free</dt>
                        <dd class="text-text-primary">
                            {{ programme.planned.freeCount }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div>
                <div class="text-text-muted text-xs font-medium uppercase">
                    Risk removed, per year
                </div>
                <dl class="mt-1 space-y-1 text-sm">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-text-secondary">
                            By the controls already implemented
                        </dt>
                        <dd class="text-text-primary">
                            <Money
                                :value="programme.riskReduced.inherentToCurrent"
                                :currency="currency"
                            />
                        </dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-text-secondary font-medium">
                            By the plan
                        </dt>
                        <dd class="text-text-primary font-medium">
                            <Money
                                :value="programme.riskReduced.currentToTarget"
                                :currency="currency"
                            />
                        </dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-text-secondary">Both together</dt>
                        <dd class="text-text-primary">
                            <Money
                                :value="programme.riskReduced.inherentToTarget"
                                :currency="currency"
                            />
                        </dd>
                    </div>
                    <div
                        class="border-border-subtle mt-2 flex items-baseline justify-between gap-4 border-t pt-2"
                    >
                        <dt class="text-text-secondary">
                            Return on the plan
                            <span class="text-text-muted text-xs"
                                >— risk removed per unit spent</span
                            >
                        </dt>
                        <dd class="text-text-primary font-medium">
                            <template v-if="programme.returnOnPlanned !== null">
                                <Percent
                                    :value="programme.returnOnPlanned"
                                    :digits="0"
                                />
                            </template>
                            <span v-else class="text-text-muted text-sm">
                                No cost recorded for the planned controls
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>
</template>
