<script setup lang="ts">
/**
 * Preview-and-confirm step (ui.md §10): every parsed row is shown, valid
 * or not, with the exact rejection reason — nothing is silently dropped,
 * unlike the legacy importer. Rows are re-parsed and re-validated
 * server-side on commit; the "valid"/"errors" flags here are for display
 * only (Admin/Users/ImportController::commit doc-comment).
 */
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Row {
    line: number;
    name: string;
    email: string;
    admin: boolean;
    valid: boolean;
    errors: string[];
}

const props = defineProps<{
    csvText: string;
    rows: Row[];
    validCount: number;
    rejectedCount: number;
}>();

const excluded = ref<Set<number>>(new Set());

function toggle(line: number) {
    if (excluded.value.has(line)) {
        excluded.value.delete(line);
    } else {
        excluded.value.add(line);
    }
}

const form = useForm({
    csv_text: props.csvText,
    excluded_lines: [] as number[],
});

function submit() {
    form.excluded_lines = Array.from(excluded.value);
    form.post(route('admin.users.import.commit'));
}
</script>

<template>
    <Head title="Admin — Import preview" />

    <AdminLayout title="Import preview">
        <div class="mb-4 flex gap-4 text-sm">
            <Badge tone="accent">{{ validCount }} will be created</Badge>
            <Badge tone="danger" v-if="rejectedCount > 0"
                >{{ rejectedCount }} rejected</Badge
            >
        </div>

        <div
            class="border-border-subtle bg-surface-1 overflow-hidden rounded-lg border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-border-subtle bg-surface-2 border-b text-xs"
                >
                    <tr>
                        <th class="px-3 py-2">Line</th>
                        <th class="px-3 py-2">Name</th>
                        <th class="px-3 py-2">Email</th>
                        <th class="px-3 py-2">Admin</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Include</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.line"
                        class="border-border-subtle text-text-primary border-b last:border-0"
                        :class="!row.valid && 'opacity-60'"
                    >
                        <td class="px-3 py-2">{{ row.line }}</td>
                        <td class="px-3 py-2">{{ row.name || '—' }}</td>
                        <td class="px-3 py-2">{{ row.email || '—' }}</td>
                        <td class="px-3 py-2">
                            {{ row.admin ? 'Yes' : 'No' }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge v-if="row.valid" tone="accent"
                                >Will be created</Badge
                            >
                            <div v-else class="text-node-attack-text text-xs">
                                <span
                                    v-for="(error, i) in row.errors"
                                    :key="i"
                                    class="block"
                                >
                                    {{ error }}
                                </span>
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <input
                                v-if="row.valid"
                                type="checkbox"
                                :checked="!excluded.has(row.line)"
                                @change="toggle(row.line)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <Button variant="ghost" :href="route('admin.users.import.create')"
                >Back</Button
            >
            <Button
                variant="primary"
                :disabled="validCount - excluded.size <= 0"
                :loading="form.processing"
                @click="submit"
            >
                Create {{ validCount - excluded.size }} account(s)
            </Button>
        </div>
    </AdminLayout>
</template>
