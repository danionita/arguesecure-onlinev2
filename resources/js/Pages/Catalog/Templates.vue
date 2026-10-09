<script setup lang="ts">
/**
 * The template list (schema.md §3.14, PLAN.md Phase 8 item 4).
 * TemplateController::index() already filters to templates the caller can
 * `view`, so everything rendered here is usable.
 */
import TemplateCard from '@/Components/Catalog/TemplateCard.vue';
import type { TemplateSummary } from '@/Components/Catalog/types';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { useToast } from '@/Components/UI/useToast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    templates: TemplateSummary[];
}>();

const toast = useToast();
const usingUuid = ref<string | null>(null);

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
            <h2 class="text-text-primary text-xl leading-tight font-semibold">
                Templates
            </h2>
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
                description="An administrator can publish any assessment as a template from its Share dialog; it then appears here for everyone."
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
                    @use="useTemplate(template)"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
