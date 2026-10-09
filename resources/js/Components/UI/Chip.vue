<script setup lang="ts">
/**
 * The node card's value-chip line is built entirely from these
 * (docs/specs/ui.md §3.2 "Value chips"). `tone="missing"` is the
 * grey `?` chip a missing value must render as — CLAUDE.md is explicit
 * that missing is never coerced to zero and never left blank.
 *
 * Colour is never the only channel: every non-neutral tone pairs with an
 * icon by default (see `defaultIcon`) so status/type also reads without
 * colour vision (docs/specs/ui.md §2).
 */
import { computed } from 'vue';
import Icon from './Icon.vue';
import type { IconName } from './icons.generated';
import type { ChipTone } from './types';

const props = withDefaults(
    defineProps<{
        tone?: ChipTone;
        icon?: IconName;
        /** Renders a grey "?" chip regardless of `tone`/slot content. */
        missing?: boolean;
    }>(),
    {
        tone: 'neutral',
        icon: undefined,
        missing: false,
    },
);

const effectiveTone = computed<ChipTone>(() =>
    props.missing ? 'missing' : props.tone,
);

const toneClasses: Record<ChipTone, string> = {
    root: 'bg-node-root-bg border-node-root-border text-node-root-text',
    risk: 'bg-node-risk-bg border-node-risk-border text-node-risk-text',
    attack: 'bg-node-attack-bg border-node-attack-border text-node-attack-text',
    defence:
        'bg-node-defence-bg border-node-defence-border text-node-defence-text',
    transfer:
        'bg-node-transfer-bg border-node-transfer-border text-node-transfer-text',
    implemented:
        'bg-status-implemented-bg border-status-implemented-border text-status-implemented-text',
    todo: 'bg-status-todo-bg border-status-todo-border text-status-todo-text',
    shelved:
        'bg-status-shelved-bg border-status-shelved-border text-status-shelved-text',
    missing:
        'bg-missing-bg border-missing-border text-missing-text border-dashed',
    neutral: 'bg-surface-2 border-border-default text-text-secondary',
};

const defaultIcon: Partial<Record<ChipTone, IconName>> = {
    implemented: 'circle-check',
    todo: 'circle-dot',
    shelved: 'circle-x',
    missing: 'alert-circle',
};

const resolvedIcon = computed(
    () => props.icon ?? defaultIcon[effectiveTone.value],
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded border px-(--spacing-chip-x) py-(--spacing-chip-y) text-(length:--text-chip) leading-(--text-chip--line-height) font-medium whitespace-nowrap"
        :class="toneClasses[effectiveTone]"
    >
        <Icon v-if="resolvedIcon" :name="resolvedIcon" :size="11" />
        <span v-if="missing">?</span>
        <slot v-else />
    </span>
</template>
