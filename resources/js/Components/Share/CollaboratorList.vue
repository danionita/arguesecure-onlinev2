<script setup lang="ts">
/**
 * Renders the current collaborator list with roles and who invited whom
 * (PLAN.md Phase 3 item 5). Role changes and revokes are emitted upward
 * rather than making their own requests — ShareDialog owns the single
 * fetch/refresh cycle so the whole dialog stays in sync after any change.
 */
import Avatar from '@/Components/UI/Avatar.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Select from '@/Components/UI/Select.vue';
import type { ShareCollaborator, ShareRole, ShareUserSummary } from './types';
import { SHARE_ROLE_LABELS, SHARE_ROLES } from './types';

defineProps<{
    owner: ShareUserSummary;
    collaborators: ShareCollaborator[];
    /** Only a manager/owner/admin can reach this dialog at all (server-enforced), but
     *  transfer-in-progress or a pending request disables row actions to avoid double-submits. */
    busy: boolean;
}>();

const emit = defineEmits<{
    'update-role': [collaborator: ShareCollaborator, role: ShareRole];
    revoke: [collaborator: ShareCollaborator];
}>();

const roleOptions = SHARE_ROLES.map((role) => ({
    value: role,
    label: SHARE_ROLE_LABELS[role],
}));
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center gap-2 rounded-md px-1 py-1.5">
            <Avatar :name="owner.name" size="sm" />
            <div class="min-w-0 flex-1">
                <p class="text-text-primary truncate text-sm font-medium">
                    {{ owner.name }}
                </p>
                <p class="text-text-muted truncate text-xs">
                    {{ owner.email }}
                </p>
            </div>
            <span
                class="text-text-muted shrink-0 text-xs font-medium tracking-wide uppercase"
                >Owner</span
            >
        </div>

        <p
            v-if="collaborators.length === 0"
            class="text-text-muted px-1 py-2 text-sm"
        >
            No one else has access yet.
        </p>

        <div
            v-for="collaborator in collaborators"
            :key="collaborator.id"
            class="flex items-center gap-2 rounded-md px-1 py-1.5"
        >
            <Avatar :name="collaborator.user.name" size="sm" />
            <div class="min-w-0 flex-1">
                <p class="text-text-primary truncate text-sm font-medium">
                    {{ collaborator.user.name }}
                </p>
                <p class="text-text-muted truncate text-xs">
                    {{ collaborator.user.email }}
                    <span v-if="collaborator.invited_by">
                        · invited by {{ collaborator.invited_by.name }}</span
                    >
                </p>
            </div>
            <div class="w-40 shrink-0">
                <Select
                    :model-value="collaborator.role"
                    :options="roleOptions"
                    :disabled="busy"
                    @update:model-value="
                        (value) =>
                            emit(
                                'update-role',
                                collaborator,
                                value as ShareRole,
                            )
                    "
                />
            </div>
            <IconButton
                icon="trash-2"
                label="Revoke access"
                variant="danger"
                :disabled="busy"
                @click="emit('revoke', collaborator)"
            />
        </div>
    </div>
</template>
