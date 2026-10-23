<script setup lang="ts">
/**
 * The three band editors of a scale — impact, likelihood and defence strength
 * (W65, relocated and reduced in W87; ui.md §8, PLAN-2.5.md D44-D47; made a
 * controlled component in W126, D94).
 *
 * ## One component, two screens
 *
 * Assessment settings edits an assessment's own bands; the admin Defaults tab
 * edits each admin-defined scale. D47 is explicit that the admin "wants the
 * same band editor the assessment gets, not a different one", so this is that
 * editor.
 *
 * ## It saves nothing (W126)
 *
 * It used to hold its own working copy and save each kind on its own request.
 * A scale is now saved as a whole — one Save for all three kinds — and the
 * assessment page swaps the whole draft when a scale is picked from the
 * dropdown, so the draft lives in the parent and this component edits it
 * through `v-model`. Where the Save button is, and what it does, is the
 * parent's business.
 *
 * ## What W87 removed, and why each removal is not a regression
 *
 * - **The code column** (D44). `scale_levels.code` is internal: derived from
 *   the label on create, frozen on rename, carried back untouched, drawn
 *   nowhere.
 * - **The colour input** (D45). Nothing outside the old calibration screen
 *   read `scale_levels.color`.
 * - **The live preview** and the **"Still using the system defaults"
 *   warning**: using the defaults is the normal state, and a warning says
 *   something is wrong.
 */
import BandTable from '@/Components/Scales/BandTable.vue';
import {
    KINDS,
    type Kind,
    type ScaleDetail,
    type ScaleDraft,
} from '@/Components/Scales/bands';

const props = withDefaults(
    defineProps<{
        /** Only for each kind's unit; the bands being edited are `modelValue`. */
        scales: Record<string, ScaleDetail | null>;
        modelValue: ScaleDraft;
        currency: string;
        topBandMultiplier: number;
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{ 'update:modelValue': [draft: ScaleDraft] }>();

const COPY: Record<Kind, { title: string; blurb: string }> = {
    impact: {
        title: 'Impact',
        // The §8.5 labelling rule, restated where the bands are set:
        // everything downstream breaks if these bands are read as annual loss.
        blurb: 'What one occurrence costs — loss each time it happens, not per year.',
    },
    likelihood: {
        title: 'Likelihood',
        blurb: 'How often per year. A rate, not a probability — it can exceed 1.',
    },
    strength: {
        title: 'Defence strength',
        blurb: 'How much of a risk a control removes, as a fraction between 0 and 1.',
    },
};

function update(kind: Kind, bands: ScaleDraft[Kind]) {
    emit('update:modelValue', { ...props.modelValue, [kind]: bands });
}
</script>

<template>
    <div>
        <section
            v-for="kind in KINDS"
            :key="kind"
            class="border-border-subtle bg-surface-1 mb-6 rounded-lg border p-4"
            :data-scale="kind"
        >
            <div class="mb-3">
                <h3 class="text-text-primary text-sm font-semibold">
                    {{ COPY[kind].title }}
                </h3>
                <p class="text-text-secondary mt-0.5 text-xs">
                    {{ COPY[kind].blurb }}
                </p>
            </div>

            <BandTable
                v-if="scales[kind]"
                :bands="modelValue[kind]"
                :unit="scales[kind]!.unit"
                :currency="currency"
                :top-band-multiplier="topBandMultiplier"
                :disabled="disabled"
                @update:bands="(next) => update(kind, next)"
            />
            <p v-else class="text-text-muted text-xs" data-testid="no-scale">
                These bands are not configured, so nothing here can be edited
                yet.
            </p>
        </section>

        <!--
            The consequence, said once at the foot: a band change moves every
            figure computed from it, including ones people have quoted.
        -->
        <p class="text-text-muted text-xs">
            <slot name="footnote">
                Changing a band changes every number derived from it, everywhere
                in this assessment. Reports already saved as snapshots keep the
                scale they were taken with and are not affected.
            </slot>
        </p>
    </div>
</template>
