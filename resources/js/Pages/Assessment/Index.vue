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
 */
import AssessmentList, {
    type AssessmentSummary,
} from '@/Components/Dashboard/AssessmentList.vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Modal from '@/Components/UI/Modal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    assessments: AssessmentSummary[];
}>();

/**
 * Opened by this page's own button, and by the sidebar's "New assessment"
 * (2.4 item 19), which links here with `?new=1` rather than carrying a second
 * creation form of its own — one write path to `assessments.store`.
 */
const creating = ref(new URLSearchParams(window.location.search).has('new'));

const form = useForm({
    title: '',
    description: '',
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
                empty-icon="shield"
                empty-title="No assessments yet"
                empty-description="Create one to start mapping risks, attacks and defences."
            />
        </div>

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
