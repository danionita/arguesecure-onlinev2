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
        <div>
            <Link
                href="/"
                class="text-text-primary flex items-center gap-2 text-lg font-semibold"
            >
                <Icon name="shield" :size="32" class="text-node-root-text" />
                ArgueSecure
            </Link>
        </div>

        <div
            class="border-border-subtle bg-surface-1 mt-6 w-full overflow-hidden border px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg"
        >
            <slot />
        </div>
    </div>
</template>
