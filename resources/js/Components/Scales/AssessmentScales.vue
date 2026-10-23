<script setup lang="ts">
/**
 * The Scales section of Assessment settings (W126, D94).
 *
 * A dropdown of every admin-defined scale, the three band editors, and one
 * Save for all three.
 *
 * - Choosing a scale **fills the editors** with its bands and sends nothing.
 *   Nothing is stored until Save; leaving the page discards the draft.
 * - Editing anything switches the dropdown to Custom at once. Which scale an
 *   assessment *is* is decided by the server when it stores the bands
 *   (`ScaleSetMatcher`), so this label is only a promise about the draft.
 * - Defaults are therefore applied in exactly two places: when an assessment
 *   is created, and when a scale is chosen here and saved. There is no
 *   "Reset to default": choosing the scale again is the reset.
 */
import ScaleCards from '@/Components/Scales/ScaleCards.vue';
import {
    draftFrom,
    draftsEqual,
    payloadFrom,
    type ScaleDetail,
    type ScaleDraft,
    type ScaleSetDetail,
} from '@/Components/Scales/bands';
import Button from '@/Components/UI/Button.vue';
import Select from '@/Components/UI/Select.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const CUSTOM = 'custom';

const props = defineProps<{
    /** The assessment's own bands as stored. */
    scales: Record<string, ScaleDetail | null>;
    scaleSets: ScaleSetDetail[];
    /** The scale the stored bands were last set to; null is Custom. */
    scaleSetKey: string | null;
    saveUrl: string;
    currency: string;
    topBandMultiplier: number;
    errors: Record<string, string>;
}>();

const stored = computed(() => draftFrom(props.scales));
const draft = ref<ScaleDraft>(draftFrom(props.scales));
const selected = ref<string>(initialKey());
const saving = ref(false);

function initialKey(): string {
    return props.scaleSetKey !== null &&
        props.scaleSets.some((set) => set.key === props.scaleSetKey)
        ? props.scaleSetKey
        : CUSTOM;
}

// A save, or any visit that brings new props, replaces the draft with what
// the server now holds.
watch(
    () => [props.scales, props.scaleSetKey],
    () => {
        draft.value = draftFrom(props.scales);
        selected.value = initialKey();
    },
);

const options = computed(() => [
    ...props.scaleSets.map((set) => ({ value: set.key, label: set.name })),
    { value: CUSTOM, label: 'Custom' },
]);

const dirty = computed(() => !draftsEqual(draft.value, stored.value));
useUnsavedChanges(dirty);

function choose(key: string) {
    if (key === CUSTOM) {
        // Nothing to load; "Custom" is only ever a consequence of editing.
        selected.value = CUSTOM;
        return;
    }

    const set = props.scaleSets.find((candidate) => candidate.key === key);
    if (set === undefined) return;

    draft.value = draftFrom(set.scales);
    selected.value = key;
}

function onEdit(next: ScaleDraft) {
    draft.value = next;
    selected.value = CUSTOM;
}

function save() {
    saving.value = true;

    // Cast at the boundary rather than giving EditableBand an index signature:
    // the payload's shape is a contract with the request's validation rules,
    // which is where the guarantee actually lives.
    router.put(
        props.saveUrl,
        payloadFrom(draft.value) as unknown as Record<
            string,
            FormDataConvertible
        >,
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <div data-testid="assessment-scales">
        <div class="mb-4 max-w-sm">
            <Select
                :model-value="selected"
                :options="options"
                label="Scale"
                data-testid="scale-select"
                @update:model-value="choose"
            />
        </div>

        <p
            v-if="errors.bands"
            class="border-danger-border bg-danger-bg text-danger-text mb-6 rounded-lg border p-3 text-sm"
            data-testid="band-errors"
        >
            {{ errors.bands }}
        </p>

        <p
            v-if="dirty"
            class="text-text-secondary mb-4 text-xs"
            data-testid="unsaved"
        >
            Not saved yet.
        </p>

        <ScaleCards
            :model-value="draft"
            :scales="scales"
            :currency="currency"
            :top-band-multiplier="topBandMultiplier"
            :disabled="saving"
            @update:model-value="onEdit"
        />

        <div class="mt-6 flex justify-end">
            <Button
                variant="primary"
                :loading="saving"
                data-testid="save-scales"
                @click="save"
            >
                Save scales
            </Button>
        </div>
    </div>
</template>
