<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    canLogin?: boolean;
}>();
</script>

<template>
    <Head title="ArgueSecure" />
    <div
        class="flex min-h-screen flex-col items-center justify-center bg-gray-50 dark:bg-gray-900"
    >
        <h1 class="text-2xl font-semibold text-gray-800 dark:text-gray-100">
            ArgueSecure
        </h1>
        <Link
            v-if="canLogin"
            :href="route('login')"
            class="mt-6 rounded-md bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-600 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white"
        >
            Log in
        </Link>
    </div>
</template>
