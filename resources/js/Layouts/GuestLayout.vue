<script setup lang="ts">
import DeploymentNotice from '@/Components/Chrome/DeploymentNotice.vue';
import BrandMark from '@/Components/UI/BrandMark.vue';
import { useTheme } from '@/Components/UI/useTheme';
import { Link } from '@inertiajs/vue3';

useTheme();
</script>

<template>
    <div class="bg-surface-0 flex min-h-screen flex-col">
        <DeploymentNotice />
        <div
            class="flex flex-1 flex-col items-center pt-6 sm:justify-center sm:pt-0"
        >
            <Link
                href="/"
                data-test="guest-logo"
                class="text-text-primary font-heading flex flex-col items-center gap-2 text-2xl font-semibold tracking-tight"
            >
                <BrandMark :size="56" />
                ArgueSecure
            </Link>

            <div
                class="border-border-subtle bg-surface-1 mt-6 w-full overflow-hidden border px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg"
            >
                <slot />
            </div>
        </div>
    </div>
</template>
