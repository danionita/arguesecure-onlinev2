<script setup lang="ts">
/**
 * *My assessments* and the "new assessment" entry point (W26, W42; 2.5.1).
 *
 * Until 2.5.1 this page listed the caller's own assessments *and* the ones
 * shared with them, under the heading "Assessments", while the sidebar linked
 * to it as "My assessments" — and "Shared with me" had a page of its own that
 * listed the shared ones again. It now lists the caller's own and nothing
 * else, and the list itself (filter, rows, icon actions) is the same
 * component the shared page renders: `Components/Dashboard/AssessmentList.vue`.
 *
 * ## Deleted (W113)
 *
 * Below the list, every deleted assessment the caller could restore — their
 * own, those they manage, every one for an admin — with **Restore** and, for
 * the owner or an admin, **Erase permanently**. A `<details>` rather than a
 * tab: it is the exception on this page, not a second list of equal weight,
 * and it opens itself when the URL asks for it (`?show=deleted`, where
 * erasing lands) so the result of an erase is on screen. Each row says when
 * the daily purge will erase it, because "kept for 30 days" is only useful
 * as a date.
 */
import AssessmentList, {
    type AssessmentSummary,
} from '@/Components/Dashboard/AssessmentList.vue';
import Button from '@/Components/UI/Button.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Input from '@/Components/UI/Input.vue';
import Modal from '@/Components/UI/Modal.vue';
import Select from '@/Components/UI/Select.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

export interface DeletedAssessment {
    uuid: string;
    title: string;
    is_owner: boolean;
    owner_name: string | null;
    deleted_at: string | null;
    /** When the daily purge erases it (deleted_at + the retention period). */
    erase_after: string | null;
    /** `forceDelete`: the owner or an admin. Restore needs no flag — every row is restorable. */
    can_erase: boolean;
}

const props = withDefaults(
    defineProps<{
        assessments: AssessmentSummary[];
        deleted?: DeletedAssessment[];
        retentionDays?: number;
        show?: 'active' | 'deleted';
        /** The admin-defined scales, Standard first (W126). */
        scaleOptions?: { value: string; label: string }[];
    }>(),
    {
        deleted: () => [],
        retentionDays: 30,
        show: 'active',
        scaleOptions: () => [],
    },
);

const restoring = ref<string | null>(null);
const pendingErase = ref<DeletedAssessment | null>(null);

function restore(assessment: DeletedAssessment) {
    restoring.value = assessment.uuid;
    router.post(
        route('assessments.restore', assessment.uuid),
        {},
        { onFinish: () => (restoring.value = null) },
    );
}

function confirmErase() {
    const target = pendingErase.value;
    if (!target) return;

    router.delete(route('assessments.erase', target.uuid), {
        onFinish: () => {
            pendingErase.value = null;
        },
    });
}

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString() : '';
}

/**
 * Opened by this page's own button, and by the sidebar's "New assessment"
 * (2.4 item 19), which links here with `?new=1` rather than carrying a second
 * creation form of its own — one write path to `assessments.store`.
 */
const creating = ref(new URLSearchParams(window.location.search).has('new'));

const form = useForm({
    title: '',
    description: '',
    // Standard unless another is picked (W126); the first option is Standard.
    scale_set_key: props.scaleOptions[0]?.value ?? 'default',
});

function submit() {
    form.post(route('assessments.store'), {
        onSuccess: () => {
            creating.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="My assessments" />

        <div class="mx-auto max-w-5xl px-4 py-8">
            <div class="mb-6 flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-text-primary text-xl font-semibold">
                        My assessments
                    </h1>
                    <p class="text-text-muted text-sm">
                        Assessments you own. Those shared with you are under
                        Shared with me.
                    </p>
                </div>

                <Button variant="primary" @click="creating = true">
                    New assessment
                </Button>
            </div>

            <AssessmentList
                :assessments="assessments"
                :retention-days="retentionDays"
                empty-icon="shield"
                empty-title="No assessments yet"
                empty-description="Create one to start mapping risks, attacks and defences."
            />

            <details
                v-if="props.deleted.length > 0 || props.show === 'deleted'"
                id="deleted"
                class="mt-8"
                :open="props.show === 'deleted'"
                data-testid="deleted-assessments"
            >
                <summary
                    class="text-text-primary cursor-pointer text-sm font-semibold"
                >
                    Deleted ({{ props.deleted.length }})
                </summary>
                <p class="text-text-muted mt-1 text-sm">
                    Deleted assessments are kept for {{ retentionDays }}
                    {{ retentionDays === 1 ? 'day' : 'days' }}, then erased for
                    good.
                </p>

                <p
                    v-if="props.deleted.length === 0"
                    class="text-text-muted mt-4 text-sm"
                >
                    Nothing is waiting to be erased.
                </p>

                <ul v-else class="mt-4 flex flex-col gap-2">
                    <li
                        v-for="assessment in props.deleted"
                        :key="assessment.uuid"
                        :data-deleted-assessment="assessment.uuid"
                        class="border-border bg-surface flex items-center justify-between gap-4 rounded-lg border p-4"
                    >
                        <div class="min-w-0">
                            <p class="text-text-primary font-medium">
                                {{ assessment.title }}
                            </p>
                            <p class="text-text-muted text-xs">
                                Deleted {{ formatDate(assessment.deleted_at) }}
                                · erased after
                                {{ formatDate(assessment.erase_after) }}
                                <template
                                    v-if="
                                        !assessment.is_owner &&
                                        assessment.owner_name
                                    "
                                >
                                    · owned by {{ assessment.owner_name }}
                                </template>
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <Button
                                size="sm"
                                :loading="restoring === assessment.uuid"
                                @click="restore(assessment)"
                            >
                                Restore
                            </Button>
                            <Button
                                v-if="assessment.can_erase"
                                size="sm"
                                variant="danger"
                                @click="pendingErase = assessment"
                            >
                                Erase permanently
                            </Button>
                        </div>
                    </li>
                </ul>
            </details>
        </div>

        <ConfirmDialog
            :show="pendingErase !== null"
            title="Erase this assessment permanently?"
            :message="`“${pendingErase?.title}” and everything in it — its history, versions and comments — will be erased. This cannot be undone.`"
            confirm-label="Erase permanently"
            danger
            @confirm="confirmErase"
            @cancel="pendingErase = null"
        />

        <Modal
            :show="creating"
            title="New assessment"
            @close="creating = false"
        >
            <!--
                `Modal.vue`'s own body slot carries no padding — every
                sibling caller supplies its own (`ConfirmDialog.vue`,
                `ShareDialog.vue`, the catalogue's insert dialog in
                `Pages/Catalog/Index.vue`, all `px-4 py-4`/`p-4`). This form
                was missing that wrapper entirely, which is what an unstyled
                container looks like (item 8, PLAN-2.5.md).
            -->
            <form class="space-y-4 px-4 py-4" @submit.prevent="submit">
                <Input
                    v-model="form.title"
                    label="Title"
                    help-text="Names the target of assessment — what you are assessing."
                    required
                    autofocus
                    :error="form.errors.title"
                />

                <Input
                    v-model="form.description"
                    label="Description"
                    :error="form.errors.description"
                />

                <Select
                    v-if="scaleOptions.length > 0"
                    v-model="form.scale_set_key"
                    :options="scaleOptions"
                    label="Scale"
                    help-text="The bands your impact, likelihood and strength values are chosen from. You can change them later in Settings."
                />

                <div class="flex justify-end gap-2 pt-2">
                    <Button
                        variant="ghost"
                        type="button"
                        @click="creating = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                    >
                        Create assessment
                    </Button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
