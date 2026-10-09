<script setup lang="ts">
/**
 * Shown exactly once. `created[].password` exists only in this response —
 * it is never stored in plaintext, never re-derivable, never emailed
 * (ui.md §10). The print view below is an HTML print stylesheet, not a
 * PDF library — dompdf is permanently banned (CLAUDE.md).
 */
import Button from '@/Components/UI/Button.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    created: { name: string; email: string; password: string }[];
}>();

function printSheet() {
    window.print();
}
</script>

<template>
    <Head title="Admin — Accounts created" />

    <AdminLayout title="Accounts created">
        <div class="mb-4 flex items-center justify-between print:hidden">
            <p class="text-text-secondary max-w-xl text-sm">
                {{ created.length }} account(s) created. These passwords are
                shown once, right now — leaving this page or reloading loses
                them for good; each user can still be reset individually later
                from the Users table.
            </p>
            <div class="flex gap-2">
                <Button icon="file-text" @click="printSheet"
                    >Print credential sheet</Button
                >
                <Button variant="primary" :href="route('admin.users.index')"
                    >Done</Button
                >
            </div>
        </div>

        <div
            class="border-border-subtle bg-surface-1 overflow-hidden rounded-lg border print:border-black"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-border-subtle bg-surface-2 border-b text-xs print:bg-white"
                >
                    <tr>
                        <th class="px-3 py-2">Name</th>
                        <th class="px-3 py-2">Email</th>
                        <th class="px-3 py-2">Temporary password</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="account in created"
                        :key="account.email"
                        class="border-border-subtle border-b last:border-0"
                    >
                        <td class="px-3 py-2">{{ account.name }}</td>
                        <td class="px-3 py-2">{{ account.email }}</td>
                        <td class="px-3 py-2 font-mono">
                            {{ account.password }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    table,
    table * {
        visibility: visible;
    }
    table {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
    }
}
</style>
