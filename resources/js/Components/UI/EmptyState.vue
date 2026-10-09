<script setup lang="ts">
/** Used for e.g. no search results, no assessments yet, an empty Links tab. */
import Icon from './Icon.vue';
import type { IconName } from './icons.generated';

withDefaults(
    defineProps<{
        icon?: IconName;
        title: string;
        description?: string;
    }>(),
    {
        icon: 'info',
        description: undefined,
    },
);
</script>

<template>
    <div class="flex flex-col items-center gap-2 px-6 py-10 text-center">
        <Icon :name="icon" :size="28" class="text-text-muted" />
        <p class="text-text-primary text-sm font-medium">{{ title }}</p>
        <p v-if="description" class="text-text-muted max-w-sm text-xs">
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-2">
            <slot />
        </div>
    </div>
</template>
