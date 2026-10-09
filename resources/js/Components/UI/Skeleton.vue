<script setup lang="ts">
/** Loading placeholder. Respects prefers-reduced-motion via the global rule in app.css. */
withDefaults(
    defineProps<{
        width?: string;
        height?: string;
        rounded?: 'sm' | 'md' | 'full';
    }>(),
    {
        width: '100%',
        height: '1rem',
        rounded: 'md',
    },
);
</script>

<template>
    <span
        class="bg-surface-2 block animate-pulse"
        :class="{
            'rounded-sm': rounded === 'sm',
            'rounded-md': rounded === 'md',
            'rounded-full': rounded === 'full',
        }"
        :style="{ width, height }"
    />
</template>
