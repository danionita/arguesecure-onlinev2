<script setup lang="ts">
/**
 * One row in the template list (schema.md §3.14, PLAN.md Phase 8 item 4).
 * "Use this template" creates a copy owned by the current user — editing
 * the template afterwards never reaches an assessment already created
 * from it (TemplateController::store()'s own docblock).
 */
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import type { TemplateSummary } from './types';

defineProps<{
    template: TemplateSummary;
    using: boolean;
}>();

defineEmits<{ use: [] }>();
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
                    <span v-if="template.owner">by {{ template.owner }} · </span
                    >{{ template.currency }}
                </p>
            </div>
        </div>

        <Button
            variant="primary"
            icon="copy"
            size="sm"
            :loading="using"
            @click="$emit('use')"
        >
            Use this template
        </Button>
    </div>
</template>
