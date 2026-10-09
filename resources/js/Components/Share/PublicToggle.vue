<script setup lang="ts">
/**
 * The public flag. Labelled explicitly as read-only — is_public grants
 * view only, never write (security.md §3.1: this is the fixed defect,
 * not a restored feature), so the copy here must not imply otherwise.
 */
import Toggle from '@/Components/UI/Toggle.vue';

defineProps<{
    modelValue: boolean;
    busy: boolean;
}>();

defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <div class="flex items-start justify-between gap-4">
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
        </div>
        <Toggle
            :model-value="modelValue"
            :disabled="busy"
            @update:model-value="(value) => $emit('update:modelValue', value)"
        />
    </div>
</template>
