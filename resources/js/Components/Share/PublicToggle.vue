<script setup lang="ts">
/**
 * The public flag. Labelled explicitly as read-only — is_public grants
 * view only, never write (security.md §3.1: this is the fixed defect,
 * not a restored feature), so the copy here must not imply otherwise.
 */
import Toggle from '@/Components/UI/Toggle.vue';

// Shown as the hover text of the whole row and as a line under the copy, so it
// is read at the moment of deciding, not only found by hovering. The live
// channel is for the people an assessment is shared with (security.md §8).
const LIVE_UPDATES_NOTE =
    'Readers of a public assessment do not get live updates or see who else is here. They reload the page to see changes.';

defineProps<{
    modelValue: boolean;
    busy: boolean;
}>();

defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <div
        class="flex items-start justify-between gap-4"
        :title="LIVE_UPDATES_NOTE"
        data-testid="public-toggle"
    >
        <div>
            <p class="text-text-primary text-sm font-medium">
                Public (view only)
            </p>
            <!--
                AssessmentPolicy::view() takes a nullable user and returns
                true on is_public before any user check, so this grants read
                access to anyone who has the link, signed in or not. The
                copy said "anyone signed in", which understated the exposure
                at the one moment the user is deciding whether to accept it.
            -->
            <p class="text-text-muted text-xs">
                Anyone with the link can view this assessment, including people
                who are not signed in. Only listed collaborators can comment or
                edit.
            </p>
            <p class="text-text-muted text-xs" data-testid="public-live-note">
                {{ LIVE_UPDATES_NOTE }}
            </p>
        </div>
        <Toggle
            :model-value="modelValue"
            :disabled="busy"
            @update:model-value="(value) => $emit('update:modelValue', value)"
        />
    </div>
</template>
