<script setup lang="ts">
/**
 * A single rendered help page (PLAN.md Phase 9 item 2, W23). The chrome is
 * `HelpShell.vue`'s: the sidebar for someone signed in, a standalone header
 * for a guest.
 *
 * `v-html="html"` is safe here because `html` is never the raw markdown
 * file: HelpController renders it server-side through
 * App\Domain\Help\MarkdownRenderer, which explicitly sets CommonMark's
 * `html_input => strip` and `allow_unsafe_links => false` — see that
 * class's docblock. That is what a script tag or a `javascript:` URL in
 * the source markdown does not survive, not anything on this page.
 */
import HelpShell from '@/Components/Help/HelpShell.vue';
import { Head, Link } from '@inertiajs/vue3';

interface HelpPageSummary {
    slug: string;
    title: string;
}

defineProps<{
    slug: string;
    title: string;
    html: string;
    pages: HelpPageSummary[];
}>();
</script>

<template>
    <Head :title="title" />

    <HelpShell>
        <div
            class="mx-auto grid max-w-5xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[200px_1fr]"
        >
            <nav aria-label="Help topics">
                <Link
                    :href="route('help.index')"
                    class="text-text-muted hover:text-text-primary mb-2 block px-2 text-xs font-medium tracking-wide uppercase"
                >
                    Help
                </Link>
                <ul class="space-y-1 text-sm">
                    <li v-for="item in pages" :key="item.slug">
                        <Link
                            :href="route('help.show', item.slug)"
                            class="block rounded px-2 py-1"
                            :class="
                                item.slug === slug
                                    ? 'bg-surface-2 text-text-primary font-medium'
                                    : 'text-text-secondary hover:bg-surface-1'
                            "
                            :aria-current="
                                item.slug === slug ? 'page' : undefined
                            "
                        >
                            {{ item.title }}
                        </Link>
                    </li>
                </ul>
            </nav>

            <article class="help-content min-w-0" v-html="html" />
        </div>
    </HelpShell>
</template>

<style scoped>
.help-content :deep(h1) {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--as-text-primary);
    margin-bottom: 0.75rem;
}

.help-content :deep(h2) {
    font-size: 1.15rem;
    font-weight: 600;
    color: var(--as-text-primary);
    margin-top: 2rem;
    margin-bottom: 0.5rem;
}

.help-content :deep(p) {
    color: var(--as-text-secondary);
    line-height: 1.7;
    margin-bottom: 1rem;
}

.help-content :deep(ul),
.help-content :deep(ol) {
    color: var(--as-text-secondary);
    line-height: 1.7;
    margin-bottom: 1rem;
    padding-left: 1.5rem;
}

.help-content :deep(ul) {
    list-style-type: disc;
}

.help-content :deep(ol) {
    list-style-type: decimal;
}

.help-content :deep(li) {
    margin-bottom: 0.25rem;
}

.help-content :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1rem;
    font-size: 0.875rem;
}

.help-content :deep(th),
.help-content :deep(td) {
    border: 1px solid var(--as-border-default);
    padding: 0.4rem 0.6rem;
    text-align: left;
}

.help-content :deep(th) {
    background-color: var(--as-surface-2);
    color: var(--as-text-primary);
}

.help-content :deep(code) {
    background-color: var(--as-surface-2);
    border-radius: 0.25rem;
    padding: 0.1rem 0.35rem;
    font-size: 0.875em;
}

.help-content :deep(pre) {
    background-color: var(--as-surface-2);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    overflow-x: auto;
    margin-bottom: 1rem;
}

.help-content :deep(pre code) {
    background-color: transparent;
    padding: 0;
}

.help-content :deep(a) {
    color: var(--as-node-root-text, inherit);
    text-decoration: underline;
}

.help-content :deep(strong) {
    color: var(--as-text-primary);
}
</style>
