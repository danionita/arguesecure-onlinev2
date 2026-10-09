<script setup lang="ts">
/**
 * A button that is only an icon. Used for the node card's row actions
 * (docs/specs/ui.md §3.2) — "small and low-contrast until row hover or
 * focus, then full contrast" is the `subtle` variant below; `solid` is for
 * standalone toolbar buttons (e.g. the top nav).
 */
import { computed } from 'vue';
import Icon from './Icon.vue';
import type { IconName } from './icons.generated';

const props = withDefaults(
    defineProps<{
        icon: IconName;
        label: string;
        size?: 'sm' | 'md';
        variant?: 'subtle' | 'solid' | 'danger';
        disabled?: boolean;
        pressed?: boolean;
    }>(),
    {
        size: 'sm',
        variant: 'subtle',
        disabled: false,
        pressed: undefined,
    },
);

const boxClasses = computed(
    () =>
        ({
            sm: 'h-6 w-6',
            md: 'h-8 w-8',
        })[props.size],
);

const iconSize = computed(() => (props.size === 'sm' ? 14 : 18));

const variantClasses = computed(
    () =>
        ({
            subtle: 'text-text-muted hover:bg-surface-2 hover:text-text-primary focus-visible:text-text-primary',
            solid: 'text-text-secondary bg-surface-1 border border-border-default hover:bg-surface-2',
            danger: 'text-node-attack-text hover:bg-node-attack-bg',
        })[props.variant],
);
</script>

<template>
    <button
        type="button"
        :aria-label="label"
        :title="label"
        :disabled="disabled"
        :aria-pressed="pressed"
        class="focus-visible:outline-focus-ring relative inline-flex shrink-0 items-center justify-center rounded transition-colors outline-none focus-visible:outline-2 focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-40"
        :class="[boxClasses, variantClasses]"
    >
        <Icon :name="icon" :size="iconSize" />
        <!-- e.g. an unread-notification dot -->
        <slot />
    </button>
</template>
