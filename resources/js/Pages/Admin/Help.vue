<script setup lang="ts">
/**
 * The administrators' help: the list of its pages and a search over them. Admin
 * only (the route sits in the `admin` group), and separate from the public
 * help so it does not count against the assistant's corpus.
 *
 * Search is a plain GET with `?q=`, so a result page can be linked and the
 * back button works; snippets are plain text and rendered as text.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface Page {
    slug: string;
    title: string;
}

interface Result extends Page {
    snippet: string;
}

const props = defineProps<{
    pages: Page[];
    query: string;
    results: Result[];
    minQuery: number;
}>();

const q = ref(props.query);

// The page is reused between visits (preserveState), so follow the URL.
watch(
    () => props.query,
    (value) => {
        q.value = value;
    },
);

function search() {
    router.get(
        route('admin.help.index'),
        q.value.trim() === '' ? {} : { q: q.value.trim() },
        { preserveState: true },
    );
}
</script>

<template>
    <Head title="Admin help" />

    <AdminLayout title="Admin help">
        <div class="max-w-3xl">
            <p class="text-text-muted text-sm">
                How to run this instance. Only administrators see these pages.
            </p>

            <form
                class="mt-4 flex items-end gap-2"
                role="search"
                @submit.prevent="search"
            >
                <Input
                    v-model="q"
                    type="search"
                    label="Search admin help"
                    class="flex-1"
                />
                <Button type="submit" variant="primary">Search</Button>
            </form>

            <section v-if="query !== ''" class="mt-6" data-testid="results">
                <p
                    v-if="query.length < minQuery"
                    class="text-text-muted text-sm"
                >
                    Type at least {{ minQuery }} characters.
                </p>
                <p
                    v-else-if="results.length === 0"
                    class="text-text-muted text-sm"
                    data-testid="no-results"
                >
                    Nothing in the admin help matches “{{ query }}”.
                </p>
                <ul v-else class="space-y-3">
                    <li v-for="hit in results" :key="hit.slug">
                        <Link
                            :href="route('admin.help.show', hit.slug)"
                            class="text-text-primary text-sm font-medium underline"
                            >{{ hit.title }}</Link
                        >
                        <p class="text-text-secondary mt-1 text-sm">
                            {{ hit.snippet }}
                        </p>
                    </li>
                </ul>
            </section>

            <ul v-else class="mt-6 grid gap-3 sm:grid-cols-2">
                <li v-for="item in pages" :key="item.slug">
                    <Link
                        :href="route('admin.help.show', item.slug)"
                        class="border-border-default bg-surface-1 hover:border-border-strong text-text-primary block rounded-lg border px-4 py-3 text-sm font-medium transition-colors"
                    >
                        {{ item.title }}
                    </Link>
                </li>
            </ul>
        </div>
    </AdminLayout>
</template>
