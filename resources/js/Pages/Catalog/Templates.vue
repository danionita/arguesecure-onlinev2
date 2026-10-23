<script setup lang="ts">
/**
 * The template list (schema.md §3.14, PLAN.md Phase 8 item 4).
 * TemplateController::index() lists every template for every signed-in
 * account, so everything rendered here is usable. Administrators also create,
 * edit and delete templates here, and only here (W141).
 */
import TemplateCard from '@/Components/Catalog/TemplateCard.vue';
import type { TemplateSummary } from '@/Components/Catalog/types';
import Button from '@/Components/UI/Button.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { useToast } from '@/Components/UI/useToast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    templates: TemplateSummary[];
    canManage: boolean;
}>();

const toast = useToast();
const usingUuid = ref<string | null>(null);
const deleting = ref<TemplateSummary | null>(null);
const deleteBusy = ref(false);

function createTemplate() {
    router.post(route('templates.create'));
}

function confirmDelete() {
    if (deleting.value === null) {
        return;
    }

    deleteBusy.value = true;
    router.delete(route('templates.destroy', deleting.value.uuid), {
        preserveScroll: true,
        onFinish: () => {
            deleteBusy.value = false;
            deleting.value = null;
        },
    });
}

async function useTemplate(template: TemplateSummary) {
    usingUuid.value = template.uuid;

    try {
        const response = await window.axios.post<{
            assessment: { uuid: string };
        }>(route('templates.use', template.uuid));

        router.visit(route('assessments.show', response.data.assessment.uuid));
    } catch {
        toast.push(
            'Could not create an assessment from that template.',
            'danger',
        );
        usingUuid.value = null;
    }
}
</script>

<template>
    <Head title="Templates" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <h2
                    class="text-text-primary text-xl leading-tight font-semibold"
                >
                    Templates
                </h2>
                <Button
                    v-if="canManage"
                    icon="plus"
                    size="sm"
                    variant="primary"
                    @click="createTemplate"
                >
                    New template
                </Button>
            </div>
        </template>

        <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
            <!--
                Item 15. `EmptyState.vue` carries its own padding
                (`px-6 py-10`) and is never otherwise nested inside a second,
                unpadded container anywhere it is used —
                `Pages/Assessment/Index.vue` renders it bare, directly in the
                page's own padded shell, with no shared group border around
                its populated `<ul>` either (each row carries its own border
                instead). This page was the one place that put EmptyState
                inside the bordered panel `TemplateCard` rows share, leaving
                it flush against that panel's border with no breathing room
                of its own — "a wrapper was assumed and never applied"
                (PLAN-2.5.md), just applied to a panel rather than a dialog.

                Fixed the way the sibling page is built: the bordered panel
                now wraps populated rows only, and the empty state stands
                alone. There is no longer a shared container to double-pad
                against the cards' own `px-4 py-3`.
            -->
            <EmptyState
                v-if="templates.length === 0"
                icon="layout-grid"
                title="No templates yet"
                :description="
                    canManage
                        ? 'Create one here, or publish an assessment as a template from its Share dialog.'
                        : 'An administrator can publish a template; it then appears here for everyone.'
                "
            />
            <div
                v-else
                data-testid="templates-panel"
                class="border-border-subtle bg-surface-1 rounded-lg border"
            >
                <TemplateCard
                    v-for="template in templates"
                    :key="template.uuid"
                    :template="template"
                    :using="usingUuid === template.uuid"
                    :can-manage="canManage"
                    @use="useTemplate(template)"
                    @delete="deleting = template"
                />
            </div>

            <ConfirmDialog
                :show="deleting !== null"
                :title="`Delete ${deleting?.title ?? 'this template'}?`"
                message="The template is deleted for good. Assessments already created from it are copies and are not affected."
                confirm-label="Delete template"
                danger
                :loading="deleteBusy"
                @confirm="confirmDelete"
                @cancel="deleting = null"
            />
        </div>
    </AuthenticatedLayout>
</template>
