<script setup lang="ts">
/**
 * Inherent / current / target annual loss as a horizontal inline-SVG bar
 * chart (ui.md §9: "Charts are inline SVG — no canvas, no chart library
 * that renders to bitmap — so they survive printing at full resolution").
 * Deliberately no chart library: package.json ships none, and CLAUDE.md
 * forbids adding a new dependency for something a dozen lines of SVG does.
 *
 * A partial total is still plotted at its (lower-bound) value — the "≥"
 * qualifier lives in the figure next to the bar (ReportTotal), not in the
 * chart, since a chart has no natural way to draw "at least".
 */
import { computed } from 'vue';
import type { TotalBlock } from './types';

const props = withDefaults(
    defineProps<{
        blocks: TotalBlock[];
        currency?: string;
        /**
         * The assessment's risk tolerance (W44), drawn as a threshold line.
         * Null or absent when none is set — which is not the same as zero,
         * and a line at the axis on every unconfigured assessment would read
         * as "everything is over tolerance".
         */
        riskTolerance?: number | null;
    }>(),
    {
        currency: 'EUR',
        riskTolerance: null,
    },
);

const labels: Record<TotalBlock['baseline'], string> = {
    inherent: 'Inherent',
    current: 'Current',
    target: 'Target',
};

// Sequential magnitude ramp tokens already defined for this purpose
// (resources/css/app.css "5-step SEQUENTIAL ramp... for contribution bars").
const colors: Record<TotalBlock['baseline'], string> = {
    inherent: 'var(--as-magnitude-5-bg)',
    current: 'var(--as-magnitude-3-bg)',
    target: 'var(--as-magnitude-1-bg)',
};

/**
 * The tolerance is included in the scale, not just plotted against it. A
 * threshold well above every bar would otherwise sit off the right edge and
 * the reader would see no line at all — which reads as "no tolerance set"
 * rather than "comfortably under it", the opposite of the truth.
 */
const maxValue = computed(() =>
    Math.max(1, ...props.blocks.map((b) => b.total), props.riskTolerance ?? 0),
);

/** x position of the threshold line, or null when there is none to draw. */
const toleranceX = computed(() =>
    props.riskTolerance === null || props.riskTolerance === undefined
        ? null
        : (props.riskTolerance / maxValue.value) * 400,
);

const formatter = computed(
    () =>
        new Intl.NumberFormat('en-GB', {
            style: 'currency',
            currency: props.currency,
            maximumFractionDigits: 0,
        }),
);

const rowHeight = 34;
const chartHeight = computed(() => props.blocks.length * rowHeight);
</script>

<template>
    <svg
        role="img"
        aria-label="Annual loss by baseline"
        :viewBox="`0 0 400 ${chartHeight}`"
        class="w-full"
        :style="{ height: `${chartHeight}px` }"
        preserveAspectRatio="none"
    >
        <g
            v-for="(block, i) in blocks"
            :key="block.baseline"
            :transform="`translate(0, ${i * rowHeight})`"
        >
            <text
                x="0"
                y="14"
                class="fill-current text-[11px] font-medium"
                style="fill: var(--as-text-secondary)"
            >
                {{ labels[block.baseline] }}
            </text>
            <rect
                x="0"
                y="18"
                :width="Math.max(2, (block.total / maxValue) * 400)"
                height="12"
                rx="2"
                :fill="colors[block.baseline]"
            />
            <text
                :x="Math.max(4, (block.total / maxValue) * 400) + 6"
                y="28"
                class="text-[11px] tabular-nums"
                style="fill: var(--as-text-primary)"
            >
                {{ block.isPartial ? '≥ ' : ''
                }}{{ formatter.format(block.total) }}
            </text>
        </g>

        <!--
            The tolerance (W44). A dashed rule and a label, drawn over the
            bars — deliberately not a coloured region behind them, and
            deliberately not recolouring the bars it crosses:
            calculation-engine.md forbids band-mapping a computed figure, and
            turning a bar red for crossing a user-chosen line is that rule
            broken with the user's own number as cover. The line states where
            the threshold is; the reader draws the conclusion.
        -->
        <g v-if="toleranceX !== null" data-testid="tolerance-marker">
            <line
                :x1="toleranceX"
                y1="0"
                :x2="toleranceX"
                :y2="chartHeight"
                stroke-width="1"
                stroke-dasharray="3 2"
                style="stroke: var(--as-text-muted)"
            />
            <title>
                Risk tolerance: {{ formatter.format(riskTolerance ?? 0) }}
            </title>
        </g>
    </svg>
</template>
