<script setup lang="ts">
/**
 * Ranked defence list (PLAN.md Phase 7 §4/§5): three cost buckets, never
 * one merged list (calculation-engine.md §10). `cbr` renders as "—" for the
 * free bucket (never `∞`) and is omitted entirely for the unpriced bucket
 * (never `0`, never a fabricated number) — `costBucket` already tells the
 * three apart, so this component never infers a bucket from a null check.
 *
 * `benefitLabel` lets the same component serve both the importance list
 * ("If removed" / MC) and the opportunity list ("If implemented" / MCadd)
 * with the correct heading, since DefenceRankingRow.benefit is already
 * whichever of mc/mcAdd applies (ReportAssembler::bucketAndSort()).
 */
import Chip from '../UI/Chip.vue';
import Money from '../UI/Money.vue';
import Percent from '../UI/Percent.vue';
import StatusPill from '../UI/StatusPill.vue';
import type { DefenceBuckets, DefenceRankingRow } from './types';

const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        buckets: DefenceBuckets;
        benefitLabel: string;
        currency: string;
        headingLevel?: 'h2' | 'h3';
    }>(),
    {
        headingLevel: 'h2',
    },
);

const bucketMeta: { key: keyof DefenceBuckets; label: string; note: string }[] =
    [
        {
            key: 'priced',
            label: 'Priced',
            note: 'sorted by cost-benefit ratio',
        },
        {
            key: 'free',
            label: 'Free',
            note: 'sorted by benefit — no cost to rank by',
        },
        {
            key: 'unpriced',
            label: 'Unpriced',
            note: 'cost not estimated — a to-do list for the analyst',
        },
    ];

function rows(bucketKey: keyof DefenceBuckets): DefenceRankingRow[] {
    return props.buckets[bucketKey];
}
</script>

<template>
    <section class="report-section" :aria-label="title">
        <component
            :is="headingLevel"
            class="text-text-primary text-xl font-semibold"
        >
            {{ title }}
        </component>
        <p class="text-text-secondary mt-1 text-sm">{{ description }}</p>

        <div
            v-for="bucket in bucketMeta"
            :key="bucket.key"
            class="mt-4 break-inside-avoid"
        >
            <template v-if="rows(bucket.key).length">
                <h4 class="text-text-secondary text-xs font-semibold uppercase">
                    {{ bucket.label }}
                    <span class="text-text-muted font-normal normal-case"
                        >— {{ bucket.note }}</span
                    >
                </h4>
                <table class="mt-1 w-full text-left text-xs">
                    <thead>
                        <tr
                            class="text-text-muted border-border-subtle border-b"
                        >
                            <th class="py-1 font-medium">Defence</th>
                            <th class="py-1 font-medium">Status</th>
                            <th class="py-1 text-right font-medium">
                                {{ benefitLabel }}
                            </th>
                            <th
                                v-if="bucket.key === 'priced'"
                                class="py-1 text-right font-medium"
                            >
                                Annual cost
                            </th>
                            <th
                                v-if="bucket.key === 'priced'"
                                class="py-1 text-right font-medium"
                            >
                                CBR
                            </th>
                            <th
                                v-if="bucket.key === 'priced'"
                                class="py-1 text-right font-medium"
                            >
                                ROSI
                            </th>
                            <th class="py-1 text-right font-medium">
                                Net benefit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows(bucket.key)"
                            :key="row.uuid"
                            class="border-border-subtle border-b last:border-0"
                            :class="
                                row.cbr !== null && row.cbr < 1
                                    ? 'bg-status-todo-bg/40'
                                    : ''
                            "
                        >
                            <td class="text-text-primary py-1">
                                {{ row.title }}
                                <span
                                    v-if="row.isTransfer"
                                    class="text-text-muted"
                                    >(transfer)</span
                                >
                            </td>
                            <td class="py-1">
                                <StatusPill :status="row.status" />
                            </td>
                            <td class="py-1 text-right">
                                <Money
                                    :value="row.benefit"
                                    :currency="currency"
                                />
                            </td>
                            <td
                                v-if="bucket.key === 'priced'"
                                class="py-1 text-right"
                            >
                                <Money
                                    :value="row.annualCost"
                                    :currency="currency"
                                />
                            </td>
                            <td
                                v-if="bucket.key === 'priced'"
                                class="py-1 text-right"
                            >
                                {{
                                    row.cbr !== null ? row.cbr.toFixed(2) : '—'
                                }}
                            </td>
                            <td
                                v-if="bucket.key === 'priced'"
                                class="py-1 text-right"
                            >
                                <Percent :value="row.rosi" signed />
                            </td>
                            <td class="py-1 text-right">
                                <Money
                                    :value="row.netBenefit"
                                    :currency="currency"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>
            <p v-else class="text-text-muted text-xs italic">
                No {{ bucket.label.toLowerCase() }} defences in this list.
            </p>
        </div>

        <p
            v-if="rows('priced').some((r) => r.cbr !== null && r.cbr < 1)"
            class="text-status-todo-text mt-2 flex items-center gap-1 text-xs"
        >
            <Chip tone="todo" icon="alert-circle">CBR &lt; 1</Chip>
            One or more controls above cost more than they save.
        </p>
    </section>
</template>
