<script setup lang="ts">
/**
 * Transfers section (PLAN.md Phase 7 §6): what is transferred, to whom, and
 * the retained impact. The schema carries no separate "counterparty"
 * column (schema.md — a Transfer is a Defence row with is_transfer=1, a
 * presentation variant, not a distinct type with its own fields), so "to
 * whom" is whatever the defence's own title and description say (e.g.
 * "Cyber insurance — underwritten by Acme Insurers") rather than a
 * structured field this component invents.
 */
import Money from '../UI/Money.vue';
import Percent from '../UI/Percent.vue';
import StatusPill from '../UI/StatusPill.vue';
import type { ReportBaseline, TransferRow } from './types';

const props = defineProps<{
    transfers: TransferRow[];
    baseline: ReportBaseline;
    currency: string;
}>();

const showCurrent = props.baseline !== 'target';
const showTarget = props.baseline !== 'current';

const reductionTargetLabels: Record<string, string> = {
    likelihood: 'Reduces likelihood',
    impact: 'Reduces impact',
};
</script>

<template>
    <section class="report-section" aria-labelledby="transfers-heading">
        <h2
            id="transfers-heading"
            class="text-text-primary text-xl font-semibold"
        >
            Transfers
        </h2>

        <p v-if="transfers.length === 0" class="text-text-muted mt-2 text-sm">
            No transfers recorded in this assessment.
        </p>

        <div class="mt-4 space-y-4">
            <article
                v-for="transfer in transfers"
                :key="transfer.uuid"
                class="report-card border-border-subtle break-inside-avoid rounded-md border p-3"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h3 class="text-text-primary font-semibold">
                            {{ transfer.title }}
                        </h3>
                        <p
                            v-if="transfer.description"
                            class="text-text-secondary text-xs"
                        >
                            {{ transfer.description }}
                        </p>
                    </div>
                    <StatusPill :status="transfer.status" />
                </div>

                <div
                    v-for="link in transfer.links"
                    :key="link.attackUuid"
                    class="border-border-subtle mt-2 border-t pt-2 text-xs"
                >
                    <div
                        class="text-text-secondary flex flex-wrap items-center gap-2"
                    >
                        <span class="font-medium">{{ link.attackTitle }}</span>
                        <span class="text-text-muted"
                            >{{
                                link.reductionTarget
                                    ? reductionTargetLabels[
                                          link.reductionTarget
                                      ]
                                    : 'Reduces overall loss'
                            }}
                            · <Percent :value="link.strength" /> reduction</span
                        >
                    </div>

                    <table class="mt-1 w-full text-left">
                        <thead>
                            <tr class="text-text-muted">
                                <th class="py-0.5 font-medium">Risk</th>
                                <th
                                    v-if="showCurrent"
                                    class="py-0.5 text-right font-medium"
                                >
                                    Retained impact (current)
                                </th>
                                <th
                                    v-if="showTarget"
                                    class="py-0.5 text-right font-medium"
                                >
                                    Retained impact (target)
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in link.retained" :key="r.riskUuid">
                                <td class="text-text-secondary py-0.5">
                                    {{ r.riskTitle }}
                                </td>
                                <td
                                    v-if="showCurrent"
                                    class="py-0.5 text-right"
                                >
                                    <Money
                                        :value="r.currentRetainedImpact"
                                        :currency="currency"
                                    />
                                </td>
                                <td v-if="showTarget" class="py-0.5 text-right">
                                    <Money
                                        :value="r.targetRetainedImpact"
                                        :currency="currency"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>
    </section>
</template>
