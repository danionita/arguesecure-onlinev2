<script setup lang="ts">
/**
 * One admin-defined scale on the Defaults tab (W126, D92-D94): its name, the
 * three band editors and **one Save for the whole scale**. A custom scale can
 * also be renamed and deleted; a built-in one can only be edited.
 */
import ScaleCards from '@/Components/Scales/ScaleCards.vue';
import {
    draftFrom,
    draftsEqual,
    payloadFrom,
    type ScaleDraft,
    type ScaleSetDetail,
} from '@/Components/Scales/bands';
import Button from '@/Components/UI/Button.vue';
import Chip from '@/Components/UI/Chip.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Input from '@/Components/UI/Input.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    set: ScaleSetDetail;
    currency: string;
    topBandMultiplier: number;
    errors: Record<string, string>;
}>();

const stored = computed(() => draftFrom(props.set.scales));
const draft = ref<ScaleDraft>(draftFrom(props.set.scales));
const saving = ref(false);
const renaming = ref(false);
const name = ref(props.set.name);
const confirmingDelete = ref(false);

watch(
    () => props.set,
    () => {
        draft.value = draftFrom(props.set.scales);
        name.value = props.set.name;
    },
);

const dirty = computed(() => !draftsEqual(draft.value, stored.value));
useUnsavedChanges(dirty);
const bandsError = computed(() => props.errors[`bands_${props.set.key}`]);
const nameError = computed(
    () => props.errors[`name_${props.set.key}`] ?? props.errors.name,
);

function save() {
    saving.value = true;

    router.put(
        route('admin.scales.update', { scaleSet: props.set.key }),
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

function rename() {
    router.put(
        route('admin.scales.rename', { scaleSet: props.set.key }),
        { name: name.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                renaming.value = false;
            },
        },
    );
}

function destroy() {
    confirmingDelete.value = false;
    router.delete(route('admin.scales.destroy', { scaleSet: props.set.key }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <section
        class="border-border-subtle mb-8 rounded-lg border p-4"
        :data-scale-set="set.key"
    >
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h3 class="text-text-primary text-sm font-semibold">
                    {{ set.name }}
                </h3>
                <Chip v-if="set.is_builtin">Built in</Chip>
            </div>
            <div class="flex items-center gap-2">
                <template v-if="!set.is_builtin">
                    <Button
                        variant="ghost"
                        :data-testid="`rename-${set.key}`"
                        @click="renaming = !renaming"
                    >
                        Rename
                    </Button>
                    <Button
                        variant="ghost"
                        :data-testid="`delete-${set.key}`"
                        @click="confirmingDelete = true"
                    >
                        Delete
                    </Button>
                </template>
                <Button
                    variant="primary"
                    :loading="saving"
                    :data-testid="`save-${set.key}`"
                    @click="save"
                >
                    Save {{ set.name }}
                </Button>
            </div>
        </div>

        <form
            v-if="renaming"
            class="mb-4 flex max-w-sm items-end gap-2"
            @submit.prevent="rename"
        >
            <Input v-model="name" label="Name" :error="nameError" required />
            <Button type="submit" variant="secondary">Rename</Button>
        </form>

        <p v-if="dirty" class="text-text-secondary mb-3 text-xs">
            Not saved yet.
        </p>

        <p
            v-if="bandsError"
            class="border-danger-border bg-danger-bg text-danger-text mb-4 rounded-lg border p-3 text-sm"
            data-testid="band-errors"
        >
            {{ bandsError }}
        </p>

        <ScaleCards
            v-model="draft"
            :scales="set.scales"
            :currency="currency"
            :top-band-multiplier="topBandMultiplier"
            :disabled="saving"
        >
            <template #footnote>
                Assessments created from this scale from now on start with these
                bands. Assessments that already exist keep the bands they have,
                and snapshots keep the scale they were taken with.
            </template>
        </ScaleCards>

        <ConfirmDialog
            :show="confirmingDelete"
            :title="`Delete ${set.name}?`"
            message="Assessments that use it keep their own copy of its bands. This cannot be undone."
            confirm-label="Delete scale"
            danger
            @confirm="destroy"
            @cancel="confirmingDelete = false"
        />
    </section>
</template>
