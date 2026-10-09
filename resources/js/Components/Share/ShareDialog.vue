<script setup lang="ts">
/**
 * The share dialog (PLAN.md Phase 3 item 5): search existing users and
 * add one with a role, change or revoke a role, transfer ownership, the
 * public flag, and a revocable read-only link. All of it self-fetches
 * from ShareController via axios rather than depending on Inertia shared
 * props. Mounted from resources/js/Pages/Assessment/Show.vue, which owns
 * the `show` flag (a "Share" button in the header toggles it, gated on
 * `store.abilities.manage`) and passes the route-bound `assessmentUuid`
 * straight from the page's own props — nothing else is needed here.
 *
 * Authorization is entirely server-side (every request 403s/401s
 * correctly on its own — see tests/Feature/Sharing). `canTransfer` here
 * is presentation only: it hides a control the server would reject
 * anyway, it does not grant anything.
 */
import Modal from '@/Components/UI/Modal.vue';
import { useToast } from '@/Components/UI/useToast';
import { onMounted, ref, watch } from 'vue';
import AddCollaboratorForm from './AddCollaboratorForm.vue';
import CollaboratorList from './CollaboratorList.vue';
import PublicToggle from './PublicToggle.vue';
import ShareLinkPanel from './ShareLinkPanel.vue';
import TemplatePanel from './TemplatePanel.vue';
import TransferOwnershipPanel from './TransferOwnershipPanel.vue';
import type {
    ShareCollaborator,
    SharePayload,
    ShareRole,
    TemplateStatus,
} from './types';

const props = withDefaults(
    defineProps<{
        show: boolean;
        assessmentUuid: string;
        /** Whether the current user may transfer ownership (owner or admin). */
        canTransfer?: boolean;
    }>(),
    {
        canTransfer: false,
    },
);

const emit = defineEmits<{ close: [] }>();

const { push } = useToast();

const payload = ref<SharePayload | null>(null);
const templateStatus = ref<TemplateStatus | null>(null);
const loading = ref(false);
const busy = ref(false);

async function load() {
    loading.value = true;
    try {
        // Two endpoints, one await: the template flags come from
        // TemplateController, since ShareController belongs to W8 and this
        // package does not own it. Requested together so the dialog never
        // renders half its state.
        const [share, template] = await Promise.all([
            window.axios.get<SharePayload>(
                route('share.index', props.assessmentUuid),
            ),
            window.axios.get<TemplateStatus>(
                route('assessments.template.show', props.assessmentUuid),
            ),
        ]);
        payload.value = share.data;
        templateStatus.value = template.data;
    } catch {
        push('Could not load sharing settings.', 'danger');
        emit('close');
    } finally {
        loading.value = false;
    }
}

/**
 * Wraps every mutation: single busy flag, one error path, always re-syncs
 * from the server after. Returns whether it succeeded, since a couple of
 * callers (transferOwnership) need to know before showing their own
 * follow-up toast.
 */
async function mutate(action: () => Promise<unknown>): Promise<boolean> {
    busy.value = true;
    try {
        await action();
        await load();
        return true;
    } catch (error) {
        const message =
            (error as { response?: { data?: { message?: string } } })?.response
                ?.data?.message ?? 'That change could not be saved.';
        push(message, 'danger');
        return false;
    } finally {
        busy.value = false;
    }
}

function addCollaborator(userId: number, role: ShareRole) {
    mutate(() =>
        window.axios.post(
            route('share.collaborators.store', props.assessmentUuid),
            {
                user_id: userId,
                role,
            },
        ),
    );
}

function updateRole(collaborator: ShareCollaborator, role: ShareRole) {
    mutate(() =>
        window.axios.patch(
            route('share.collaborators.update', [
                props.assessmentUuid,
                collaborator.id,
            ]),
            { role },
        ),
    );
}

function revokeCollaborator(collaborator: ShareCollaborator) {
    mutate(() =>
        window.axios.delete(
            route('share.collaborators.destroy', [
                props.assessmentUuid,
                collaborator.id,
            ]),
        ),
    );
}

function updatePublic(isPublic: boolean) {
    mutate(() =>
        window.axios.patch(route('share.public.update', props.assessmentUuid), {
            is_public: isPublic,
        }),
    );
}

function generateLink() {
    mutate(() =>
        window.axios.post(route('share.token.store', props.assessmentUuid)),
    );
}

function revokeLink() {
    mutate(() =>
        window.axios.delete(route('share.token.destroy', props.assessmentUuid)),
    );
}

async function setTemplate(isTemplate: boolean) {
    const succeeded = await mutate(() =>
        window.axios.patch(
            route('assessments.template.update', props.assessmentUuid),
            { is_template: isTemplate },
        ),
    );

    if (succeeded) {
        push(
            isTemplate
                ? 'Saved as a template. Anyone with an account can now read it.'
                : 'Unpublished. This assessment is private again.',
            'success',
        );
    }
}

async function transferOwnership(userId: number) {
    const succeeded = await mutate(() =>
        window.axios.post(route('share.transfer', props.assessmentUuid), {
            user_id: userId,
        }),
    );

    if (succeeded) {
        push('Ownership transferred.', 'success');
    }
}

watch(
    () => props.show,
    (show) => {
        if (show) load();
    },
);

onMounted(() => {
    if (props.show) load();
});
</script>

<template>
    <Modal :show="show" title="Share" size="lg" @close="emit('close')">
        <div v-if="loading && !payload" class="text-text-muted p-6 text-sm">
            Loading…
        </div>

        <div v-else-if="payload" class="space-y-6 p-4">
            <section>
                <h3 class="text-text-primary mb-2 text-sm font-semibold">
                    Collaborators
                </h3>
                <CollaboratorList
                    :owner="payload.owner"
                    :collaborators="payload.collaborators"
                    :busy="busy"
                    @update-role="updateRole"
                    @revoke="revokeCollaborator"
                />
                <div class="mt-3">
                    <AddCollaboratorForm
                        :assessment-uuid="assessmentUuid"
                        :busy="busy"
                        @add="addCollaborator"
                    />
                </div>
            </section>

            <section class="border-border-subtle border-t pt-4">
                <PublicToggle
                    :model-value="payload.is_public"
                    :busy="busy"
                    @update:model-value="updatePublic"
                />
            </section>

            <section
                v-if="templateStatus"
                class="border-border-subtle border-t pt-4"
            >
                <TemplatePanel
                    :status="templateStatus"
                    :busy="busy"
                    @publish="setTemplate(true)"
                    @unpublish="setTemplate(false)"
                />
            </section>

            <section class="border-border-subtle border-t pt-4">
                <ShareLinkPanel
                    :share-link="payload.share_link"
                    :busy="busy"
                    @generate="generateLink"
                    @revoke="revokeLink"
                />
            </section>

            <section
                v-if="canTransfer"
                class="border-border-subtle border-t pt-4"
            >
                <TransferOwnershipPanel
                    :collaborators="payload.collaborators"
                    :busy="busy"
                    @transfer="transferOwnership"
                />
            </section>
        </div>
    </Modal>
</template>
