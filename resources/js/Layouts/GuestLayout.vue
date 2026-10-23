<script setup lang="ts">
import Icon from '@/Components/UI/Icon.vue';
import { useTheme } from '@/Components/UI/useTheme';
import { Link } from '@inertiajs/vue3';

useTheme();
</script>

<template>
    <div
        class="bg-surface-0 flex min-h-screen flex-col items-center pt-6 sm:justify-center sm:pt-0"
    >
        <Link
            href="/"
            data-test="guest-logo"
            class="text-text-primary flex flex-col items-center gap-2 text-2xl font-semibold tracking-tight"
        >
            <span
                class="bg-surface-1 border-border-subtle flex size-16 items-center justify-center rounded-2xl border shadow-sm"
            >
                <Icon name="shield" :size="40" class="text-node-root-text" />
            </span>
            ArgueSecure
        </Link>

        <div
            class="border-border-subtle bg-surface-1 mt-6 w-full overflow-hidden border px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg"
        >
            <slot />
        </div>
    </div>
</template>
