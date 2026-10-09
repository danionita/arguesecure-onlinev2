<script setup lang="ts">
/**
 * One row in the catalogue browser (schema.md §3.14, PLAN.md Phase 8 item
 * 4). Shows enough to decide whether to insert an entry without opening
 * it: kind, title, description, and whether it is a bundle (ships with
 * pre-linked children, e.g. a "Phishing" attack shipping with MFA,
 * awareness training and mail filtering).
 */
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import type { IconName } from '@/Components/UI/icons.generated';
import type { CatalogEntryKind, CatalogEntrySummary } from './types';

defineProps<{
    entry: CatalogEntrySummary;
    /** Only true when this page was opened with an assessment in context
     *  (CatalogController::index()'s `assessment` prop) and the caller
     *  has `update` on it — otherwise the entry is browse-only. */
    canInsert: boolean;
    inserting: boolean;
}>();

defineEmits<{ insert: [] }>();

const kindIcon: Record<CatalogEntryKind, IconName> = {
    risk: 'triangle-alert',
    attack: 'swords',
    defence: 'shield',
};

const kindLabel: Record<CatalogEntryKind, string> = {
    risk: 'Risk',
    attack: 'Attack',
    defence: 'Defence',
};
</script>

<template>
    <div
        class="border-border-subtle flex items-start justify-between gap-4 border-b px-4 py-3 last:border-b-0"
    >
        <div class="flex min-w-0 items-start gap-3">
            <Icon
                :name="kindIcon[entry.kind]"
                :size="18"
                class="text-text-muted mt-0.5 shrink-0"
            />
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-text-primary text-sm font-medium">
                        {{ entry.title }}
                    </p>
                    <span class="text-text-muted text-xs">{{
                        kindLabel[entry.kind]
                    }}</span>
                    <Badge v-if="entry.isBundle" tone="accent">bundle</Badge>
                    <Badge v-if="entry.isSystem" tone="neutral">system</Badge>
                </div>
                <p
                    v-if="entry.description"
                    class="text-text-muted mt-0.5 truncate text-xs"
                >
                    {{ entry.description }}
                </p>
            </div>
        </div>

        <Button
            v-if="canInsert"
            variant="secondary"
            icon="square-plus"
            size="sm"
            :loading="inserting"
            @click="$emit('insert')"
        >
            Insert
        </Button>
    </div>
</template>
