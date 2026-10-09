<script setup lang="ts">
/**
 * "Search existing users and add one with a role" (PLAN.md Phase 3 item
 * 5). Searching hits ShareController::searchUsers, which is itself
 * restricted to callers who already hold `manage` on this assessment and
 * returns only id/name/email (security.md §7) — this component just
 * renders whatever comes back.
 */
import Button from '@/Components/UI/Button.vue';
import Combobox from '@/Components/UI/Combobox.vue';
import Select from '@/Components/UI/Select.vue';
import { computed, ref } from 'vue';
import type { ShareRole, ShareUserSummary } from './types';
import { SHARE_ROLE_LABELS, SHARE_ROLES } from './types';

const props = defineProps<{
    assessmentUuid: string;
    busy: boolean;
}>();

const emit = defineEmits<{
    add: [userId: number, role: ShareRole];
}>();

const results = ref<ShareUserSummary[]>([]);
const selectedUserId = ref<string | null>(null);
const role = ref<ShareRole>('viewer');
let searchToken = 0;

const roleOptions = SHARE_ROLES.map((r) => ({
    value: r,
    label: SHARE_ROLE_LABELS[r],
}));

const comboboxOptions = computed(() =>
    results.value.map((user) => ({
        value: String(user.id),
        label: user.name,
        description: user.email,
    })),
);

async function onQuery(query: string) {
    if (query.trim().length < 2) {
        results.value = [];
        return;
    }

    const token = ++searchToken;

    const response = await window.axios.get<{ users: ShareUserSummary[] }>(
        route('share.users.search', props.assessmentUuid),
        { params: { query } },
    );

    // Ignore a stale response that resolved after a newer search started.
    if (token === searchToken) {
        results.value = response.data.users;
    }
}

function submit() {
    if (!selectedUserId.value) return;

    emit('add', Number(selectedUserId.value), role.value);
    selectedUserId.value = null;
    results.value = [];
}
</script>

<template>
    <form class="flex items-end gap-2" @submit.prevent="submit">
        <div class="flex-1">
            <Combobox
                v-model="selectedUserId"
                :options="comboboxOptions"
                label="Add a collaborator"
                placeholder="Search by name or email…"
                @update:query="onQuery"
            />
        </div>
        <div class="w-44">
            <Select
                :model-value="role"
                :options="roleOptions"
                @update:model-value="(value) => (role = value as ShareRole)"
            />
        </div>
        <Button
            type="submit"
            variant="primary"
            icon="plus"
            :disabled="!selectedUserId || busy"
            >Add</Button
        >
    </form>
</template>
