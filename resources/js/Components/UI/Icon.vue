<script setup lang="ts">
/**
 * Self-hosted inline icon. No icon font, no CDN request — the strict CSP in
 * docs/specs/security.md §6 has no `font-src`/`img-src` allowance for one.
 * Paths come from icons.generated.ts, ported from Lucide (ISC licence, see
 * that file's header). `v-html` is safe here: the markup is bundled,
 * build-time, first-party content, never user input.
 */
import { computed } from 'vue';
import { ICON_PATHS, type IconName } from './icons.generated';

const props = withDefaults(
    defineProps<{
        name: IconName;
        size?: number | string;
        strokeWidth?: number;
        /** Accessible label. Omit for a purely decorative icon (the default). */
        label?: string;
    }>(),
    {
        size: 16,
        strokeWidth: 2,
        label: undefined,
    },
);

const markup = computed(() => ICON_PATHS[props.name] ?? '');
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        :stroke-width="strokeWidth"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="shrink-0"
        :aria-hidden="label ? undefined : 'true'"
        :role="label ? 'img' : undefined"
        :aria-label="label"
        v-html="markup"
    />
</template>
