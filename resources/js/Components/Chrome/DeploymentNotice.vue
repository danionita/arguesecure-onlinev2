<script setup lang="ts">
/**
 * The operator's deployment notice (APP_NOTICE): one line of plain text
 * above every page, including the ones a stranger sees first. Interpolated,
 * never `v-html`; not dismissible; `no-print` so a printed report omits it.
 * Renders nothing when the shared `notice` prop is null or empty.
 */
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const notice = computed(() => {
    const value = (page.props as { notice?: unknown }).notice;

    return typeof value === 'string' && value !== '' ? value : null;
});
</script>

<template>
    <div
        v-if="notice"
        role="status"
        data-test="deployment-notice"
        class="no-print bg-surface-2 text-text-primary border-border-subtle border-b px-4 py-1.5 text-center text-sm"
    >
        {{ notice }}
    </div>
</template>
