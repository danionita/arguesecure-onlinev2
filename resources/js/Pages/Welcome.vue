<script setup lang="ts">
import DeploymentNotice from '@/Components/Chrome/DeploymentNotice.vue';
import BrandMark from '@/Components/UI/BrandMark.vue';
import { useTheme } from '@/Components/UI/useTheme';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    canLogin?: boolean;
}>();

useTheme();
</script>

<template>
    <Head title="ArgueSecure" />
    <div class="bg-surface-0 flex min-h-screen flex-col">
        <DeploymentNotice />
        <div class="flex flex-1 flex-col items-center justify-center">
            <BrandMark :size="56" />
            <h1 class="text-text-primary mt-2 text-2xl font-semibold">
                ArgueSecure
            </h1>
            <Link
                v-if="canLogin"
                :href="route('login')"
                class="bg-text-primary text-text-inverted focus-visible:ring-focus-ring mt-6 rounded-md px-4 py-2 text-sm hover:opacity-90 focus:outline-none focus-visible:ring-2"
            >
                Log in
            </Link>
        </div>
    </div>
</template>
