<script setup lang="ts">
/**
 * One titled group inside `FilterPopover.vue` (W132). The heading says what
 * the controls under it filter, which a chip's colour and position do not —
 * "Defence status" in particular says the set leaves risks and attacks alone
 * — and is also the group's accessible name.
 */
import { useId } from 'vue';

defineProps<{ title: string }>();

const headingId = useId();
</script>

<template>
    <div role="group" :aria-labelledby="headingId" class="space-y-1.5">
        <h3
            :id="headingId"
            class="text-text-secondary text-[11px] font-medium tracking-wide uppercase"
        >
            {{ title }}
        </h3>
        <slot />
    </div>
</template>
