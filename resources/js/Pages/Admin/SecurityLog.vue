<script setup lang="ts">
/**
 * The admin **Security log** tab (W128, D96–D100): the last 50 stored security
 * events matching a date range and event, and a CSV or JSON download of all
 * of them (capped). It shows what this instance recorded in the retention
 * window; the stderr stream is the copy an operator ships off the host.
 * Application logs are not here (D96).
 */
import Button from '@/Components/UI/Button.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface EventRow {
    id: number;
    time: string;
    level: string;
    event: string;
    actor: { id: number | null; email: string | null } | null;
    target: {
        type?: string;
        id?: string | number | null;
        email?: string;
    } | null;
    ip: string | null;
}

const props = defineProps<{
    filters: { from: string; to: string; event: string };
    events: { families: string[]; names: string[] };
    rows: EventRow[];
    retentionDays: number;
    maxRows: number;
}>();

const form = reactive({ ...props.filters });

function query(extra: Record<string, string> = {}): Record<string, string> {
    const params: Record<string, string> = { ...extra };
    if (form.from) params.from = form.from;
    if (form.to) params.to = form.to;
    if (form.event) params.event = form.event;

    return params;
}

function apply() {
    router.get(route('admin.security-log.index'), query(), {
        preserveScroll: true,
    });
}

function downloadHref(format: 'csv' | 'json'): string {
    return route('admin.security-log.download', query({ format }));
}

function targetLabel(row: EventRow): string {
    if (!row.target) return '—';
    return (
        row.target.email ??
        `${row.target.type ?? ''} ${row.target.id ?? ''}`.trim()
    );
}
</script>

<template>
    <Head title="Admin — Security log" />

    <AdminLayout title="Security log">
        <div class="space-y-4">
            <p class="text-text-secondary max-w-2xl text-sm">
                Sign-ins, refusals, account and settings changes, sharing
                changes and exports, as this instance recorded them in the last
                {{ retentionDays }} days. The same events go to the app
                container's standard error, which is the copy to ship to a log
                collector: rows here can be altered by anyone with database
                access.
            </p>
            <p
                class="text-text-muted max-w-2xl text-xs"
                data-testid="app-log-note"
            >
                Application logs (errors and warnings) are not stored here. Read
                them on the host with
                <code>docker compose logs app</code>.
            </p>

            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="apply"
            >
                <label class="text-text-secondary text-xs">
                    From (UTC)
                    <input
                        v-model="form.from"
                        type="date"
                        class="border-border-default bg-surface-1 text-text-primary mt-1 block rounded-md text-sm"
                        data-testid="filter-from"
                    />
                </label>
                <label class="text-text-secondary text-xs">
                    To (UTC)
                    <input
                        v-model="form.to"
                        type="date"
                        class="border-border-default bg-surface-1 text-text-primary mt-1 block rounded-md text-sm"
                        data-testid="filter-to"
                    />
                </label>
                <label class="text-text-secondary text-xs">
                    Event
                    <select
                        v-model="form.event"
                        class="border-border-default bg-surface-1 text-text-primary mt-1 block rounded-md text-sm"
                        data-testid="filter-event"
                    >
                        <option value="">All events</option>
                        <optgroup label="Family">
                            <option
                                v-for="f in events.families"
                                :key="f"
                                :value="f"
                            >
                                {{ f }}.*
                            </option>
                        </optgroup>
                        <optgroup label="Event">
                            <option
                                v-for="n in events.names"
                                :key="n"
                                :value="n"
                            >
                                {{ n }}
                            </option>
                        </optgroup>
                    </select>
                </label>
                <Button type="submit" variant="secondary">Filter</Button>
            </form>

            <div class="flex flex-wrap items-center gap-3">
                <a
                    :href="downloadHref('csv')"
                    class="text-text-primary border-border-default rounded-md border px-3 py-1.5 text-sm"
                    data-testid="download-csv"
                    >Download CSV</a
                >
                <a
                    :href="downloadHref('json')"
                    class="text-text-primary border-border-default rounded-md border px-3 py-1.5 text-sm"
                    data-testid="download-json"
                    >Download JSON</a
                >
                <span class="text-text-muted text-xs">
                    Up to {{ maxRows.toLocaleString() }} rows. Each download is
                    itself logged.
                </span>
            </div>

            <p
                v-if="rows.length === 0"
                class="text-text-muted text-sm"
                data-testid="log-empty"
            >
                No events match.
            </p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-xs" data-testid="log-table">
                    <caption class="text-text-muted pb-2 text-left">
                        Latest
                        {{
                            rows.length
                        }}
                        matching events, newest first
                    </caption>
                    <thead class="text-text-secondary">
                        <tr>
                            <th class="pr-3 pb-1">Time (UTC)</th>
                            <th class="pr-3 pb-1">Level</th>
                            <th class="pr-3 pb-1">Event</th>
                            <th class="pr-3 pb-1">Actor</th>
                            <th class="pr-3 pb-1">Target</th>
                            <th class="pb-1">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            class="border-border-subtle border-t"
                        >
                            <td class="py-1 pr-3 whitespace-nowrap">
                                {{ row.time }}
                            </td>
                            <td class="py-1 pr-3">{{ row.level }}</td>
                            <td class="py-1 pr-3">{{ row.event }}</td>
                            <td class="py-1 pr-3">
                                {{ row.actor?.email ?? '—' }}
                            </td>
                            <td class="py-1 pr-3">{{ targetLabel(row) }}</td>
                            <td class="py-1">{{ row.ip ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
