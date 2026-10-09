<script setup lang="ts">
/**
 * Transfer ownership — deliberately its own section, shown only when the
 * caller passes `canTransfer` (the parent decides that from
 * AssessmentPolicy::transferOwnership, which only the owner or an admin
 * satisfies; a manager never sees this control at all, matching the
 * server-side rejection in ShareController::transferOwnership).
 *
 * The target list is exactly the existing collaborators, not a fresh
 * search — OwnershipTransferService requires the recipient to already be
 * a collaborator (see that class's docblock for why), so offering a
 * bigger picker here would just produce a validation error.
 */
import Button from '@/Components/UI/Button.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Select from '@/Components/UI/Select.vue';
import { computed, ref } from 'vue';
import type { ShareCollaborator } from './types';

const props = defineProps<{
    collaborators: ShareCollaborator[];
    busy: boolean;
}>();

const emit = defineEmits<{ transfer: [userId: number] }>();

const selectedUserId = ref('');
const confirming = ref(false);

const options = computed(() =>
    props.collaborators.map((c) => ({
        value: String(c.user.id),
        label: `${c.user.name} (${c.role})`,
    })),
);

const selectedName = computed(
    () =>
        props.collaborators.find(
            (c) => String(c.user.id) === selectedUserId.value,
        )?.user.name,
);

function confirmTransfer() {
    if (!selectedUserId.value) return;
    emit('transfer', Number(selectedUserId.value));
    confirming.value = false;
    selectedUserId.value = '';
}
</script>

<template>
    <div class="space-y-2">
        <p class="text-text-primary text-sm font-medium">Transfer ownership</p>
        <p class="text-text-muted text-xs">
            The new owner gains full control; you keep manager access afterwards
            rather than losing it.
        </p>

        <p v-if="collaborators.length === 0" class="text-text-muted text-xs">
            Add a collaborator first — ownership can only transfer to someone
            who already has access.
        </p>
        <div v-else class="flex items-center gap-2">
            <div class="w-64">
                <Select
                    :model-value="selectedUserId"
                    :options="options"
                    placeholder="Choose a collaborator…"
                    :disabled="busy"
                    @update:model-value="(value) => (selectedUserId = value)"
                />
            </div>
            <Button
                variant="danger"
                icon="arrow-left-right"
                :disabled="!selectedUserId || busy"
                @click="confirming = true"
                >Transfer</Button
            >
        </div>

        <ConfirmDialog
            :show="confirming"
            title="Transfer ownership"
            :message="`${selectedName} will become the owner of this assessment. You will keep manager access.`"
            confirm-label="Transfer ownership"
            danger
            :loading="busy"
            @confirm="confirmTransfer"
            @cancel="confirming = false"
        />
    </div>
</template>
