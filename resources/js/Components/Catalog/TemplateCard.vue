<script setup lang="ts">
/**
 * One row in the template list (schema.md §3.14, PLAN.md Phase 8 item 4).
 * "Use this template" creates a copy owned by the current user — editing
 * the template afterwards never reaches an assessment already created
 * from it (TemplateController::store()'s own docblock).
 *
 * Administrators also get Edit and Delete (W141). Presentation only: the
 * routes behind them refuse everyone else on their own.
 */
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import type { TemplateSummary } from './types';

withDefaults(
    defineProps<{
        template: TemplateSummary;
        using: boolean;
        canManage?: boolean;
    }>(),
    { canManage: false },
);

defineEmits<{ use: []; delete: [] }>();
</script>

<template>
    <div
        class="border-border-subtle flex items-start justify-between gap-4 border-b px-4 py-3 last:border-b-0"
    >
        <div class="flex min-w-0 items-start gap-3">
            <Icon
                name="layout-grid"
                :size="18"
                class="text-text-muted mt-0.5 shrink-0"
            />
            <div class="min-w-0">
                <p class="text-text-primary text-sm font-medium">
                    {{ template.title ?? 'Untitled assessment' }}
                </p>
                <p
                    v-if="template.description"
                    class="text-text-muted mt-0.5 truncate text-xs"
                >
                    {{ template.description }}
                </p>
                <p class="text-text-muted mt-0.5 text-xs">
                    {{ template.currency }}
                </p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <template v-if="canManage">
                <Button
                    variant="ghost"
                    icon="pencil"
                    size="sm"
                    :href="route('assessments.show', template.uuid)"
                    :aria-label="`Edit template ${template.title ?? ''}`"
                >
                    Edit
                </Button>
                <Button
                    variant="ghost"
                    icon="trash-2"
                    size="sm"
                    :aria-label="`Delete template ${template.title ?? ''}`"
                    @click="$emit('delete')"
                >
                    Delete
                </Button>
            </template>
            <Button
                variant="primary"
                icon="copy"
                size="sm"
                class="shrink-0 whitespace-nowrap"
                :loading="using"
                @click="$emit('use')"
            >
                Use this template
            </Button>
        </div>
    </div>
</template>
