<script setup lang="ts">
/**
 * Help index (ui.md §2, Navigation). Reachable without an account
 * (see the public help routes in routes/web.php), so the chrome depends on who is reading:
 * the sidebar for someone signed in, a small standalone header for a guest —
 * `HelpShell.vue` makes that choice for both help pages.
 */
import AskAssistant from '@/Components/Help/AskAssistant.vue';
import HelpShell from '@/Components/Help/HelpShell.vue';
import { Head, Link } from '@inertiajs/vue3';

interface HelpPageSummary {
    slug: string;
    title: string;
}

defineProps<{
    pages: HelpPageSummary[];
    /**
     * Always unavailable for a guest — this page is reachable without an
     * account, the generating endpoint behind the control is not.
     */
    assistant: { available: boolean; reason: string | null };
    /** Administrators get a link to their own help. */
    adminHelp?: boolean;
}>();
</script>

<template>
    <Head title="Help" />

    <HelpShell>
        <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6">
            <h1 class="text-text-primary text-2xl font-semibold">Help</h1>
            <p class="text-text-muted mt-2 text-sm">
                How assessments are built and quantified, working together,
                templates, versions, reports, and the keyboard map.
            </p>

            <!--
                One action, at the top of the page where someone is already
                looking for an answer. Not a floating button and not a chat
                bubble: docs/ASSISTANT.md §3 is explicit that the assistant appears
                where it helps and nowhere else.
            -->
            <AskAssistant
                :available="assistant.available"
                :unavailable-reason="assistant.reason"
            />

            <ul class="mt-8 grid gap-3 sm:grid-cols-2">
                <li v-for="item in pages" :key="item.slug">
                    <Link
                        :href="route('help.show', item.slug)"
                        class="border-border-default bg-surface-1 hover:border-border-strong text-text-primary block rounded-lg border px-4 py-3 text-sm font-medium transition-colors"
                    >
                        {{ item.title }}
                    </Link>
                </li>
            </ul>

            <p v-if="adminHelp" class="mt-6 text-sm">
                <Link
                    :href="route('admin.help.index')"
                    class="text-text-secondary hover:text-text-primary underline"
                    >Administrator help</Link
                >
            </p>
        </div>
    </HelpShell>
</template>
