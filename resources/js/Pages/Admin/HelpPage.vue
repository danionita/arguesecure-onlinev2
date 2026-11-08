<script setup lang="ts">
/**
 * One admin help page. `v-html` is safe for the reason given in
 * Pages/Help/Show.vue: the HTML is rendered server-side by MarkdownRenderer,
 * which strips raw HTML and unsafe links, from a file in a fixed manifest.
 */
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

interface Page {
    slug: string;
    title: string;
}

defineProps<{ slug: string; title: string; html: string; pages: Page[] }>();
</script>

<template>
    <Head :title="`${title} · Admin help`" />

    <AdminLayout title="Admin help">
        <div class="grid max-w-5xl gap-8 lg:grid-cols-[200px_1fr]">
            <nav aria-label="Admin help topics">
                <Link
                    :href="route('admin.help.index')"
                    class="text-text-muted hover:text-text-primary mb-2 block px-2 text-xs font-medium tracking-wide uppercase"
                    >Admin help</Link
                >
                <ul class="space-y-1 text-sm">
                    <li v-for="item in pages" :key="item.slug">
                        <Link
                            :href="route('admin.help.show', item.slug)"
                            class="block rounded px-2 py-1"
                            :class="
                                item.slug === slug
                                    ? 'bg-surface-2 text-text-primary font-medium'
                                    : 'text-text-secondary hover:bg-surface-1'
                            "
                            :aria-current="
                                item.slug === slug ? 'page' : undefined
                            "
                            >{{ item.title }}</Link
                        >
                    </li>
                </ul>
            </nav>

            <article class="help-content min-w-0" v-html="html" />
        </div>
    </AdminLayout>
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

.help-content :deep(p),
.help-content :deep(ul),
.help-content :deep(ol) {
    color: var(--as-text-secondary);
    line-height: 1.7;
    margin-bottom: 1rem;
}

.help-content :deep(ul),
.help-content :deep(ol) {
    padding-left: 1.5rem;
}

.help-content :deep(ul) {
    list-style-type: disc;
}

.help-content :deep(ol) {
    list-style-type: decimal;
}

.help-content :deep(a) {
    text-decoration: underline;
}

.help-content :deep(code) {
    font-family: ui-monospace, monospace;
    font-size: 0.9em;
}
</style>
