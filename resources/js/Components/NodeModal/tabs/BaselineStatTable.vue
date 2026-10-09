<script setup lang="ts">
/**
 * Three baselines side by side, one row per figure (W39).
 *
 * A real `<table>`, not a grid of divs. Every cell here is a number whose
 * meaning comes from the intersection of its row and its column, which is the
 * definition of tabular data — and a screen reader announcing "Reduction vs
 * base, Current, €46,000" is the whole feature working, where a div grid would
 * announce three unrelated numbers.
 *
 * This is also where the formal baseline vocabulary now lives.
 * `BASELINE_LABELS` says "Inherent" while the ToS card says "Base"
 * (`ROOT_TOTAL_LABELS`, W36): the card is glanced at mid-workshop and this
 * table is read, so the table uses the word the report and the methodology
 * appendix use, and the column tooltip connects the two.
 */
import Chip from '@/Components/UI/Chip.vue';
import { formatRate } from '@/Components/UI/format';
import Money from '@/Components/UI/Money.vue';
import Percent from '@/Components/UI/Percent.vue';
import Tooltip from '@/Components/UI/Tooltip.vue';
import { BASELINE_HINTS, BASELINE_LABELS } from '@/constants/valueHints';
import { BASELINE_ORDER, type StatCell, type StatRow } from './statistics';

defineProps<{
    rows: StatRow[];
    currency: string;
    /** Names what the numbers are about, for the table's accessible caption. */
    caption: string;
}>();

/**
 * Narrowing lives here rather than as an inline cast in the template: a union
 * type written inline is Vue 2 filter syntax as far as the linter is
 * concerned, and it rejects it outright.
 *
 * `formatRate` is then called directly because there is no `<Rate>` component
 * — the node card formats rates inline too. What has to match across all four
 * kinds is the missing-value fallback: the grey chip, never a blank cell.
 */
function numeric(cell: StatCell): number | null {
    return typeof cell.value === 'number' ? cell.value : null;
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <caption class="sr-only">
                {{
                    caption
                }}
            </caption>
            <thead>
                <tr class="border-border-subtle border-b">
                    <th
                        scope="col"
                        class="text-text-muted py-2 pr-3 text-left text-xs font-medium"
                    >
                        <span class="sr-only">Figure</span>
                    </th>
                    <th
                        v-for="baseline in BASELINE_ORDER"
                        :key="baseline"
                        scope="col"
                        class="text-text-secondary px-3 py-2 text-right text-xs font-medium"
                    >
                        <Tooltip :text="BASELINE_HINTS[baseline]">
                            <span
                                class="border-border-strong border-b border-dotted"
                            >
                                {{ BASELINE_LABELS[baseline] }}
                            </span>
                        </Tooltip>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.key"
                    class="border-border-subtle border-b align-top last:border-b-0"
                    :data-stat-row="row.key"
                >
                    <th
                        scope="row"
                        class="text-text-secondary py-2 pr-3 text-left font-normal"
                    >
                        <Tooltip v-if="row.hint" :text="row.hint">
                            <span
                                class="border-border-strong border-b border-dotted"
                            >
                                {{ row.label }}
                            </span>
                        </Tooltip>
                        <span v-else>{{ row.label }}</span>
                    </th>
                    <td
                        v-for="(cell, index) in row.cells"
                        :key="BASELINE_ORDER[index]"
                        class="text-text-primary px-3 py-2 text-right"
                        :data-baseline="BASELINE_ORDER[index]"
                    >
                        <Money
                            v-if="cell.kind === 'money'"
                            :value="numeric(cell)"
                            :currency="currency"
                            :at-least="cell.atLeast"
                        />
                        <Percent
                            v-else-if="cell.kind === 'percent'"
                            :value="numeric(cell)"
                        />
                        <template v-else-if="cell.kind === 'rate'">
                            <Chip v-if="cell.value === null" missing />
                            <span v-else class="tabular-nums">
                                {{ formatRate(numeric(cell)) }}
                            </span>
                        </template>
                        <template v-else>
                            <Chip v-if="cell.value === null" missing />
                            <span v-else class="tabular-nums">{{
                                cell.value
                            }}</span>
                        </template>

                        <!--
                            Per-column, so it cannot live on the row label: a
                            defence is "planned, not implemented" in the
                            current column and counting normally in the target
                            one, and saying that once for the whole row would
                            be wrong in two thirds of it.
                        -->
                        <span
                            v-if="cell.note"
                            class="text-text-muted mt-0.5 block text-xs font-normal"
                        >
                            {{ cell.note }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
