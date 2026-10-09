<script setup lang="ts">
/**
 * General-purpose button, folding what Breeze shipped as
 * Primary/Secondary/DangerButton into a single typed component with a
 * `variant`. W10 replaced every use of that trio; W55 deleted the three
 * files, which had sat unreferenced ever since.
 */
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Icon from './Icon.vue';
import type { IconName } from './icons.generated';

const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
        size?: 'sm' | 'md';
        icon?: IconName;
        iconPosition?: 'left' | 'right';
        loading?: boolean;
        disabled?: boolean;
        /** Renders as an Inertia <Link> instead of <button>. */
        href?: string;
        type?: 'button' | 'submit' | 'reset';
    }>(),
    {
        variant: 'secondary',
        size: 'md',
        icon: undefined,
        iconPosition: 'left',
        loading: false,
        disabled: false,
        href: undefined,
        type: 'button',
    },
);

const isDisabled = computed(() => props.disabled || props.loading);

const variantClasses = computed(
    () =>
        ({
            primary:
                'bg-node-root-text text-text-inverted border-transparent hover:opacity-90 focus-visible:outline-focus-ring',
            secondary:
                'bg-surface-1 text-text-primary border-border-default hover:bg-surface-2 focus-visible:outline-focus-ring',
            danger: 'bg-node-attack-text text-text-inverted border-transparent hover:opacity-90 focus-visible:outline-focus-ring',
            ghost: 'bg-transparent text-text-secondary border-transparent hover:bg-surface-2 focus-visible:outline-focus-ring',
        })[props.variant],
);

const sizeClasses = computed(
    () =>
        ({
            sm: 'h-7 px-2.5 text-xs gap-1',
            md: 'h-9 px-3.5 text-sm gap-1.5',
        })[props.size],
);
</script>

<template>
    <component
        :is="href ? Link : 'button'"
        :href="href"
        :type="href ? undefined : type"
        :disabled="href ? undefined : isDisabled"
        :aria-disabled="isDisabled || undefined"
        class="inline-flex items-center justify-center rounded-md border font-medium transition-colors outline-none focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        :class="[variantClasses, sizeClasses]"
    >
        <Icon
            v-if="loading"
            name="loader-circle"
            :size="size === 'sm' ? 14 : 16"
            class="animate-spin"
        />
        <Icon
            v-else-if="icon && iconPosition === 'left'"
            :name="icon"
            :size="size === 'sm' ? 14 : 16"
        />
        <slot />
        <Icon
            v-if="!loading && icon && iconPosition === 'right'"
            :name="icon"
            :size="size === 'sm' ? 14 : 16"
        />
    </component>
</template>
