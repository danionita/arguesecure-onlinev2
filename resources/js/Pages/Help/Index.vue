<script setup lang="ts">
/**
 * Help index (PLAN.md Phase 9 item 2, W23). Reachable without an account
 * (see routes/web.php's W23 block), so the chrome depends on who is reading:
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
     * W51. Always unavailable for a guest — this page is reachable without an
     * account, the generating endpoint behind the control is not.
     */
    assistant: { available: boolean; reason: string | null };
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
                bubble: PLAN-2.3-ai.md is explicit that the assistant appears
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
        </div>
    </HelpShell>
</template>
