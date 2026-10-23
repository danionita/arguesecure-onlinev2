<script setup lang="ts">
/**
 * Shared chrome for the /admin area — a sub-nav under the main AuthenticatedLayout
 * header. Lives under Pages/Admin/** (not Layouts/**, which W8 does not own) since
 * it is specific to this one section of the app.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title: string;
}>();

/*
 * 2.5.1: Bulk import left this strip — it is a dialog on the Users tab — and
 * the assistant's switches got a tab of their own instead of the foot of
 * Instance settings.
 */
const tabs = [
    { label: 'Users', href: () => route('admin.users.index') },
    { label: 'Instance settings', href: () => route('admin.settings.edit') },
    // W133: how people sign in: SSO, SSO only, required two-factor.
    { label: 'Authentication', href: () => route('admin.authentication.edit') },
    // W87, D47. Separate from Instance settings because it answers a different
    // question: that tab is about the deployment, this one about the content a
    // new assessment starts with.
    { label: 'Defaults', href: () => route('admin.defaults.edit') },
    { label: 'AI assistant', href: () => route('admin.assistant.edit') },
    // W128: the stored security event log and its download.
    { label: 'Security log', href: () => route('admin.security-log.index') },
];
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-text-primary text-xl leading-tight font-semibold">
                {{ title }}
            </h2>
        </template>

        <div class="mx-auto max-w-7xl px-4 pt-6 sm:px-6 lg:px-8">
            <nav class="border-border-subtle mb-6 flex gap-4 border-b text-sm">
                <Link
                    v-for="tab in tabs"
                    :key="tab.label"
                    :href="tab.href()"
                    class="text-text-secondary hover:text-text-primary border-b-2 border-transparent pb-2"
                >
                    {{ tab.label }}
                </Link>
            </nav>

            <slot />
        </div>
    </AuthenticatedLayout>
</template>
