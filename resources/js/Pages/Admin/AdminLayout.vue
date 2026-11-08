<script setup lang="ts">
/**
 * Shared chrome for the /admin area — a sub-nav under the main AuthenticatedLayout
 * header. Lives under Pages/Admin/** (not Layouts/**, which holds the shared layouts) since
 * it is specific to this one section of the app.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';

defineProps<{
    title: string;
}>();

/*
 * 2.5.1: Bulk import left this strip — it is a dialog on the Users tab — and
 * the assistant's switches got a tab of their own instead of the foot of
 * Instance settings.
 */
const page = usePage();

// The tab whose section the current URL is in.
const isCurrent = (href: string) =>
    page.url.split('?')[0].startsWith(new URL(href, 'http://x').pathname);

const tabs = [
    { label: 'Users', href: () => route('admin.users.index') },
    { label: 'Instance settings', href: () => route('admin.settings.edit') },
    // How people sign in: SSO, SSO only, required two-factor.
    { label: 'Authentication', href: () => route('admin.authentication.edit') },
    // Outbound email: the SMTP server, the sender and a test message.
    { label: 'Email', href: () => route('admin.mail.edit') },
    // Separate from Instance settings because it answers a different
    // question: that tab is about the deployment, this one about the content a
    // new assessment starts with.
    { label: 'Defaults', href: () => route('admin.defaults.edit') },
    { label: 'AI assistant', href: () => route('admin.assistant.edit') },
    // The stored security event log and its download.
    { label: 'Security log', href: () => route('admin.security-log.index') },
    // The administrators' own help, with its own search.
    { label: 'Help', href: () => route('admin.help.index') },
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
                    class="border-b-2 pb-2"
                    :class="
                        isCurrent(tab.href())
                            ? 'text-text-primary border-current font-medium'
                            : 'text-text-secondary hover:text-text-primary border-transparent'
                    "
                    :aria-current="isCurrent(tab.href()) ? 'page' : undefined"
                >
                    {{ tab.label }}
                </Link>
            </nav>

            <slot />
        </div>
    </AuthenticatedLayout>
</template>
