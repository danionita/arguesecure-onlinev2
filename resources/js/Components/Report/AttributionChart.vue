<script setup lang="ts">
/**
 * "Share of reduction" chart, built from log-share attribution — never from
 * marginal contribution (calculation-engine.md §9.5: "Present MC for
 * decision-making... and Attr for reporting ('who gets credit?'). Do not
 * blend them." ui.md §9 repeats this as a hard rule for exactly this
 * chart). Attribution sums exactly to the inherent-to-current reduction, so
 * a stacked bar of `attribution` values is honest in a way a stacked bar of
 * `mc` values would not be (calculation-engine.md §9.4's non-additivity).
 *
 * Rows with `attribution === null` (not currently active in the baseline
 * being charted) are skipped — §9.5 is explicit that null there means "the
 * question does not apply", not zero.
 */
import { computed } from 'vue';
import type { DefenceRankingRow } from './types';

const props = withDefaults(
    defineProps<{
        rows: DefenceRankingRow[];
        currency?: string;
    }>(),
    {
        currency: 'EUR',
    },
);

const segments = computed(() => {
    const attributed = props.rows.filter(
        (r): r is DefenceRankingRow & { attribution: number } =>
            r.attribution !== null && r.attribution > 0,
    );
    const total = attributed.reduce((sum, r) => sum + r.attribution, 0);
    if (total <= 0) return [];

    // Reuse the same 5-step magnitude ramp the rest of the report uses for
    // contribution, cycling if there are more defences than ramp steps.
    const palette = [
        'var(--as-magnitude-5-bg)',
        'var(--as-magnitude-4-bg)',
        'var(--as-magnitude-3-bg)',
        'var(--as-magnitude-2-bg)',
        'var(--as-magnitude-1-bg)',
    ];

    let cursor = 0;
    return attributed
        .slice()
        .sort((a, b) => b.attribution - a.attribution)
        .map((r, i) => {
            const width = (r.attribution / total) * 100;
            const segment = {
                title: r.title,
                attribution: r.attribution,
                share: r.attribution / total,
                x: cursor,
                width,
                color: palette[i % palette.length],
            };
            cursor += width;
            return segment;
        });
});

const formatter = computed(
    () =>
        new Intl.NumberFormat('en-GB', {
            style: 'currency',
            currency: props.currency,
            maximumFractionDigits: 0,
        }),
);
</script>

<template>
    <template>
        <div v-if="segments.length">
            <svg
                role="img"
                aria-label="Share of risk reduction by defence, by log-share attribution"
                viewBox="0 0 400 28"
                class="w-full"
                style="height: 28px"
                preserveAspectRatio="none"
            >
                <rect
                    v-for="s in segments"
                    :key="s.title"
                    :x="(s.x / 100) * 400"
                    y="0"
                    :width="(s.width / 100) * 400"
                    height="20"
                    :fill="s.color"
                >
                    <title>
                        {{ s.title }}: {{ formatter.format(s.attribution) }}
                    </title>
                </rect>
            </svg>
            <ul
                class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-3"
            >
                <li
                    v-for="s in segments"
                    :key="s.title"
                    class="flex items-center gap-1.5"
                >
                    <span
                        class="inline-block h-2.5 w-2.5 shrink-0 rounded-sm"
                        :style="{ background: s.color }"
                    />
                    <span class="text-text-secondary truncate">{{
                        s.title
                    }}</span>
                    <span class="text-text-muted tabular-nums"
                        >{{ Math.round(s.share * 100) }}%</span
                    >
                </li>
            </ul>
        </div>
        <p v-else class="text-text-muted text-xs">
            No implemented defences currently attribute a measured reduction.
        </p>
    </template>
</template>
