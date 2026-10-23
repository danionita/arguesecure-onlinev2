<script setup lang="ts">
import BulkImportDialog from '@/Components/Admin/BulkImportDialog.vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import Modal from '@/Components/UI/Modal.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface UserRow {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_active: boolean;
    last_login_at: string | null;
    created_at: string;
    owned_assessments_count: number;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

const props = defineProps<{
    users: Paginated<UserRow>;
    filters: { search: string; sort: string; direction: 'asc' | 'desc' };
}>();

// W115: Inertia flash data, delivered with one response and kept out of
// browser history, so Back to this table does not show a password again.
const page = usePage();
const generatedPassword = computed(() => {
    const value = (page.flash as Record<string, unknown> | undefined)
        ?.generatedPassword;

    return typeof value === 'string' && value !== '' ? value : null;
});

const search = ref(props.filters.search);

// 2.5.1: bulk import is a dialog on this tab rather than a tab of its own.
// `?import=1` opens it, which is where the old tab's URL now redirects.
const importing = ref(
    typeof window !== 'undefined' &&
        new URLSearchParams(window.location.search).has('import'),
);

function applySearch() {
    router.get(
        route('admin.users.index'),
        {
            search: search.value,
            sort: props.filters.sort,
            direction: props.filters.direction,
        },
        { preserveState: true, replace: true },
    );
}

function sortBy(column: string) {
    const direction =
        props.filters.sort === column && props.filters.direction === 'asc'
            ? 'desc'
            : 'asc';
    router.get(
        route('admin.users.index'),
        { search: search.value, sort: column, direction },
        { preserveState: true, replace: true },
    );
}

const deactivateTarget = ref<UserRow | null>(null);
const deleteTarget = ref<UserRow | null>(null);
const transferTo = ref<string>('');

const deactivateForm = useForm({
    is_admin: false,
    is_active: false,
    name: '',
    email: '',
});

function openDeactivate(user: UserRow) {
    deactivateTarget.value = user;
    deactivateForm.name = user.name;
    deactivateForm.email = user.email;
    deactivateForm.is_admin = user.is_admin;
    deactivateForm.is_active = !user.is_active; // toggled
}

function confirmDeactivate() {
    if (!deactivateTarget.value) return;
    deactivateForm.put(route('admin.users.update', deactivateTarget.value.id), {
        onFinish: () => {
            deactivateTarget.value = null;
        },
    });
}

const deleteForm = useForm({ transfer_to_user_id: '' });

function openDelete(user: UserRow) {
    deleteTarget.value = user;
    transferTo.value = '';
}

function confirmDelete() {
    if (!deleteTarget.value) return;
    deleteForm.transfer_to_user_id = transferTo.value;
    deleteForm.delete(route('admin.users.destroy', deleteTarget.value.id), {
        onFinish: () => {
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="Admin — Users" />

    <AdminLayout title="Users">
        <div
            v-if="generatedPassword"
            data-testid="generated-password"
            class="border-status-implemented-border bg-status-implemented-bg text-status-implemented-text mb-4 rounded-md border px-4 py-3 text-sm"
        >
            Generated password (shown once, not stored anywhere else; they must
            change it at first sign-in):
            <code class="font-mono font-semibold">{{ generatedPassword }}</code>
        </div>

        <div class="mb-4 flex items-center justify-between gap-4">
            <form class="w-72" @submit.prevent="applySearch">
                <Input
                    v-model="search"
                    placeholder="Search name or email"
                    @keyup.enter="applySearch"
                />
            </form>
            <div class="flex gap-2">
                <Button
                    icon="upload"
                    data-testid="bulk-import-open"
                    @click="importing = true"
                >
                    Bulk import
                </Button>
                <Button
                    variant="primary"
                    icon="plus"
                    :href="route('admin.users.create')"
                >
                    New user
                </Button>
            </div>
        </div>

        <div
            class="border-border-subtle bg-surface-1 overflow-hidden rounded-lg border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-border-subtle bg-surface-2 border-b text-xs"
                >
                    <tr>
                        <th
                            class="cursor-pointer px-4 py-2"
                            @click="sortBy('name')"
                        >
                            Name
                        </th>
                        <th
                            class="cursor-pointer px-4 py-2"
                            @click="sortBy('email')"
                        >
                            Email
                        </th>
                        <th class="px-4 py-2">Admin</th>
                        <th class="px-4 py-2">Active</th>
                        <th class="px-4 py-2">Assessments</th>
                        <th
                            class="cursor-pointer px-4 py-2"
                            @click="sortBy('last_login_at')"
                        >
                            Last login
                        </th>
                        <th
                            class="cursor-pointer px-4 py-2"
                            @click="sortBy('created_at')"
                        >
                            Created
                        </th>
                        <th class="px-4 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users.data"
                        :key="user.id"
                        class="border-border-subtle text-text-primary border-b last:border-0"
                    >
                        <td class="px-4 py-2 font-medium">{{ user.name }}</td>
                        <td class="text-text-secondary px-4 py-2">
                            {{ user.email }}
                        </td>
                        <td class="px-4 py-2">
                            <Badge v-if="user.is_admin" tone="accent"
                                >Admin</Badge
                            >
                        </td>
                        <td class="px-4 py-2">
                            <Badge
                                :tone="user.is_active ? 'neutral' : 'danger'"
                            >
                                {{ user.is_active ? 'Active' : 'Deactivated' }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2">
                            {{ user.owned_assessments_count }}
                        </td>
                        <td class="text-text-secondary px-4 py-2">
                            {{ user.last_login_at ?? 'Never' }}
                        </td>
                        <td class="text-text-secondary px-4 py-2">
                            {{ user.created_at }}
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-1">
                                <Link
                                    :href="route('admin.users.edit', user.id)"
                                    class="text-text-muted hover:text-text-primary inline-flex h-6 w-6 items-center justify-center"
                                    :title="`Edit ${user.name}`"
                                >
                                    <Icon name="pencil" :size="14" />
                                </Link>
                                <button
                                    type="button"
                                    class="text-text-muted hover:text-text-primary inline-flex h-6 w-6 items-center justify-center"
                                    :title="
                                        user.is_active
                                            ? 'Deactivate'
                                            : 'Reactivate'
                                    "
                                    @click="openDeactivate(user)"
                                >
                                    <Icon
                                        :name="
                                            user.is_active
                                                ? 'lock'
                                                : 'lock-open'
                                        "
                                        :size="14"
                                    />
                                </button>
                                <button
                                    type="button"
                                    class="text-node-attack-text inline-flex h-6 w-6 items-center justify-center hover:opacity-75"
                                    title="Delete"
                                    @click="openDelete(user)"
                                >
                                    <Icon name="trash-2" :size="14" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <EmptyState
                v-if="users.data.length === 0"
                icon="users"
                title="No users found"
                description="Try a different search, or create the first one."
            />
        </div>

        <div
            v-if="users.links.length > 3"
            class="mt-4 flex justify-center gap-1"
        >
            <Link
                v-for="(link, index) in users.links"
                :key="index"
                :href="link.url ?? '#'"
                :class="[
                    'rounded px-3 py-1 text-sm',
                    link.active
                        ? 'bg-node-root-text text-text-inverted'
                        : 'text-text-secondary hover:bg-surface-2',
                    !link.url && 'pointer-events-none opacity-40',
                ]"
            >
                <span v-html="link.label" />
            </Link>
        </div>

        <ConfirmDialog
            :show="deactivateTarget !== null"
            :title="
                deactivateTarget?.is_active
                    ? 'Deactivate user'
                    : 'Reactivate user'
            "
            :message="
                deactivateTarget?.is_active
                    ? `${deactivateTarget?.name} will be signed out of every session and unable to log in until reactivated.`
                    : `${deactivateTarget?.name} will be able to log in again.`
            "
            confirm-label="Confirm"
            :danger="deactivateTarget?.is_active"
            :loading="deactivateForm.processing"
            @confirm="confirmDeactivate"
            @cancel="deactivateTarget = null"
        />

        <Modal
            :show="deleteTarget !== null"
            title="Delete user"
            size="sm"
            @close="deleteTarget = null"
        >
            <div class="space-y-3 px-4 py-4 text-sm">
                <p class="text-text-secondary">
                    This permanently deletes
                    <strong>{{ deleteTarget?.name }}</strong
                    >'s account. This cannot be undone.
                </p>
                <p
                    v-if="
                        deleteTarget && deleteTarget.owned_assessments_count > 0
                    "
                    class="text-node-attack-text"
                >
                    They own {{ deleteTarget.owned_assessments_count }}
                    assessment(s). Deleting them without transferring ownership
                    would delete those assessments too — pick who should receive
                    them.
                </p>
                <Input
                    v-if="
                        deleteTarget && deleteTarget.owned_assessments_count > 0
                    "
                    v-model="transferTo"
                    label="Transfer owned assessments to (user id)"
                    placeholder="user id"
                    :error="deleteForm.errors.transfer_to_user_id"
                />
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="deleteTarget = null"
                        >Cancel</Button
                    >
                    <Button
                        variant="danger"
                        :loading="deleteForm.processing"
                        @click="confirmDelete"
                    >
                        Delete
                    </Button>
                </div>
            </template>
        </Modal>
        <BulkImportDialog :show="importing" @close="importing = false" />
    </AdminLayout>
</template>
