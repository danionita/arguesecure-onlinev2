<script setup lang="ts">
/**
 * The three scale cards — impact, likelihood and defence strength (W65,
 * relocated and reduced in W87; ui.md §8, PLAN-2.5.md D44-D47).
 *
 * ## One component, two screens
 *
 * Assessment settings edits an assessment's own calibration; the admin
 * **Defaults** tab edits the seeded system set new assessments start from.
 * D47 is explicit that the admin "wants the same band editor the assessment
 * gets, not a different one", so this is that editor, and the two screens
 * differ only in the URLs they hand it and the sentence above it.
 *
 * ## What W87 removed, and why each removal is not a regression
 *
 * - **The code column** (D44). `scale_levels.code` is internal: it is derived
 *   from the label on create and frozen on rename, and the editor carries it
 *   back untouched without drawing it. Asking a user for a machine key they
 *   never see the effect of was the input equivalent of a control that does
 *   nothing.
 * - **The colour input** (D45). Nothing outside the old calibration screen
 *   read `scale_levels.color`. The column stays with its `#9CA3AF` default.
 * - **The live preview** ("a €50,000 loss falls in moderate"). It answered a
 *   question the band table above it already answers by being a table of
 *   ranges, and it was the only reason `color` reached the client.
 * - **The "Still using the system defaults" warning.** Using the defaults is
 *   the normal state — most assessments never calibrate — and a warning says
 *   something is wrong. What the flag actually means is still stated, as a
 *   fact on the card: this scale is shared, and saving gives the assessment
 *   its own copy.
 *
 * ## Saving is per scale, and that has not changed
 *
 * Three separate requests. The three scales are agreed in different
 * conversations — impact with the business, likelihood with whoever watches
 * the logs — and one Save spanning all three would make each of those a change
 * to the other two. It also keeps a rejected calibration local: a bad impact
 * band cannot block a good likelihood one.
 */
import BandTable from '@/Components/Scales/BandTable.vue';
import type { EditableBand, ScaleDetail } from '@/Components/Scales/bands';
import Button from '@/Components/UI/Button.vue';
import Chip from '@/Components/UI/Chip.vue';
import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        scales: Record<string, ScaleDetail | null>;
        currency: string;
        topBandMultiplier: number;
        /** `PUT` target per kind. */
        saveUrls: Record<string, string>;
        /**
         * `PUT` target per kind for "Reset to default", or absent where there
         * is nothing above to reset to — the admin's Defaults tab *is* the
         * default, so it offers no reset (D47).
         */
        resetUrls?: Record<string, string>;
        /**
         * Whether to state where each scale comes from. True for an
         * assessment, where "shared" versus "this assessment's own" decides
         * whether a save clones; meaningless on the admin tab, where every
         * scale is shared by definition.
         */
        showOrigin?: boolean;
        errors?: Record<string, string>;
    }>(),
    { resetUrls: undefined, showOrigin: false, errors: () => ({}) },
);

const KINDS = ['impact', 'likelihood', 'strength'] as const;

type Kind = (typeof KINDS)[number];

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

/** Working copies, so an unsaved edit is never confused with a stored one. */
const draft = reactive<Record<string, EditableBand[]>>(
    Object.fromEntries(
        KINDS.map((kind) => [
            kind,
            (props.scales[kind]?.bands ?? []).map((band) => ({
                // Carried, never rendered, never edited. This is what freezes
                // a code across a rename (D44).
                code: band.code,
                label: band.label,
                lower_bound: Number(band.lower_bound),
                upper_bound:
                    band.upper_bound === null ? null : Number(band.upper_bound),
                representative: Number(band.representative),
            })),
        ]),
    ),
);

const busy = ref<string | null>(null);

function save(kind: Kind) {
    busy.value = kind;

    router.put(
        props.saveUrls[kind],
        // Cast at the boundary rather than giving EditableBand an index
        // signature. Inertia's FormDataConvertible requires one, and adding it
        // to the interface would let any key at all pass type-checking into a
        // payload whose shape is a precise contract with the controller's
        // validation rules — which is where the actual guarantee lives.
        { bands: draft[kind] as unknown as FormDataConvertible[] },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

function reset(kind: Kind) {
    const url = props.resetUrls?.[kind];
    if (url === undefined) return;

    busy.value = kind;

    router.put(
        url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}
</script>

<template>
    <div>
        <p
            v-if="errors.bands"
            class="border-danger-border bg-danger-bg text-danger-text mb-6 rounded-lg border p-3 text-sm"
            data-testid="band-errors"
        >
            {{ errors.bands }}
        </p>

        <section
            v-for="kind in KINDS"
            :key="kind"
            class="border-border-subtle bg-surface-1 mb-6 rounded-lg border p-4"
            :data-scale="kind"
        >
            <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3
                        class="text-text-primary flex items-center gap-2 text-sm font-semibold"
                    >
                        {{ COPY[kind].title }}
                        <!--
                            A fact, not a warning (W87). It says what a save
                            will do — clone, or edit in place — which is the
                            only thing `is_system` decides for the person
                            reading it.
                        -->
                        <Chip v-if="showOrigin && scales[kind]?.is_system"
                            >Instance default</Chip
                        >
                        <Chip v-else-if="showOrigin"
                            >This assessment’s own</Chip
                        >
                    </h3>
                    <p class="text-text-secondary mt-0.5 text-xs">
                        {{ COPY[kind].blurb }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="resetUrls?.[kind]"
                        variant="secondary"
                        :disabled="busy !== null"
                        :data-testid="`reset-${kind}`"
                        @click="reset(kind)"
                    >
                        Reset to default
                    </Button>
                    <Button
                        variant="primary"
                        :loading="busy === kind"
                        :data-testid="`save-${kind}`"
                        @click="save(kind)"
                    >
                        Save {{ COPY[kind].title.toLowerCase() }}
                    </Button>
                </div>
            </div>

            <BandTable
                v-if="scales[kind]"
                :bands="draft[kind]"
                :unit="scales[kind]!.unit"
                :currency="currency"
                :top-band-multiplier="topBandMultiplier"
                :disabled="busy !== null"
                @update:bands="(next) => (draft[kind] = next)"
            />
            <p v-else class="text-text-muted text-xs" data-testid="no-scale">
                This scale is not configured, so nothing here can be edited yet.
            </p>
        </section>

        <!--
            The consequence, said once at the foot. Recalibrating is not an
            edit to a setting — it moves every figure already computed from
            this scale, including ones people have quoted.
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
