<script setup lang="ts">
/**
 * Pick nodes from a template (2.5.1). Two steps: which template, then which of
 * its nodes of the right type.
 *
 * Two modes. `multi` is the card's "Add from template": one action per item,
 * as many as the person wants, the panel staying open — the same rhythm as the
 * assistant's suggestion list. `single` is the new-node form's "From
 * template": choosing one item hands it back and the form closes the picker,
 * because that form is making exactly one node.
 *
 * A template can hold 150 controls, so the list filters as you type, on title
 * and on description.
 */
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { computed, ref, watch } from 'vue';
import type {
    TemplateItemState,
    TemplateNodeItem,
    TemplateOption,
} from './useTemplateChildren';

const props = defineProps<{
    mode: 'multi' | 'single';
    childType: 'risk' | 'attack' | 'defence';
    templates: TemplateOption[] | null;
    templateUuid: string | null;
    items: TemplateNodeItem[] | null;
    loading: boolean;
    error: string | null;
    /** multi mode: which item is being written right now. */
    busy?: string | null;
    /** multi mode: what pressing the item's button would do. */
    stateOf?: (item: TemplateNodeItem) => TemplateItemState;
}>();

const emit = defineEmits<{
    choose: [templateUuid: string | null];
    take: [item: TemplateNodeItem];
    dismiss: [];
}>();

const PLURAL: Record<string, string> = {
    risk: 'risks',
    attack: 'attacks',
    defence: 'defences',
};

const plural = computed(() => PLURAL[props.childType]);

const filter = ref('');
watch(
    () => props.templateUuid,
    () => (filter.value = ''),
);

const chosenTemplate = computed(
    () => props.templates?.find((t) => t.uuid === props.templateUuid) ?? null,
);

const visibleItems = computed(() => {
    const needle = filter.value.trim().toLocaleLowerCase();
    const items = props.items ?? [];
    if (needle === '') return items;

    return items.filter(
        (item) =>
            item.title.toLocaleLowerCase().includes(needle) ||
            (item.description ?? '').toLocaleLowerCase().includes(needle),
    );
});

const ACTION_LABELS: Record<TemplateItemState, string> = {
    add: 'Add',
    link: 'Link',
    here: 'Already here',
    added: 'Added',
};

function itemState(item: TemplateNodeItem): TemplateItemState {
    return props.stateOf ? props.stateOf(item) : 'add';
}
</script>

<template>
    <div
        class="border-border-default bg-surface-1 rounded-lg border p-3"
        data-testid="template-picker"
    >
        <div class="flex items-start justify-between gap-2">
            <p class="text-text-muted text-xs">
                <template v-if="chosenTemplate">
                    <button
                        type="button"
                        class="text-text-link hover:underline"
                        data-testid="template-picker-back"
                        @click="emit('choose', null)"
                    >
                        Templates
                    </button>
                    <span aria-hidden="true"> / </span>
                    <span class="text-text-secondary font-medium">{{
                        chosenTemplate.title
                    }}</span>
                </template>
                <template v-else
                    >Pick a template to take {{ plural }} from.</template
                >
            </p>
            <button
                type="button"
                class="text-text-muted hover:text-text-primary text-xs underline"
                data-testid="template-picker-dismiss"
                @click="emit('dismiss')"
            >
                {{ mode === 'multi' ? 'Done' : 'Cancel' }}
            </button>
        </div>

        <p
            v-if="error"
            class="text-node-attack-text mt-2 text-xs"
            role="alert"
            data-testid="template-picker-error"
        >
            {{ error }}
        </p>

        <p
            v-if="loading"
            class="text-text-muted mt-3 flex items-center gap-1 text-xs"
        >
            <Icon name="loader-circle" :size="12" class="animate-spin" />
            Loading…
        </p>

        <!-- Step 1: which template. -->
        <ul
            v-else-if="!chosenTemplate && templates"
            class="mt-2 max-h-72 space-y-1 overflow-y-auto"
        >
            <li v-if="templates.length === 0" class="text-text-muted text-sm">
                There are no templates yet.
            </li>
            <li v-for="template in templates" :key="template.uuid">
                <button
                    type="button"
                    class="hover:bg-surface-2 flex w-full items-center justify-between gap-2 rounded px-2 py-1.5 text-left text-sm disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="template.counts[childType] === 0"
                    data-testid="template-option"
                    @click="emit('choose', template.uuid)"
                >
                    <span class="text-text-primary min-w-0 truncate">{{
                        template.title
                    }}</span>
                    <span class="text-text-muted shrink-0 text-xs tabular-nums"
                        >{{ template.counts[childType] }} {{ plural }}</span
                    >
                </button>
            </li>
        </ul>

        <!-- Step 2: which of its nodes. -->
        <template v-else-if="chosenTemplate && items">
            <input
                v-model="filter"
                type="search"
                :placeholder="`Filter ${items.length} ${plural}`"
                class="border-border-default bg-surface-0 text-text-primary focus:border-focus-ring focus:ring-focus-ring mt-2 block w-full rounded-md py-1 text-sm"
                data-testid="template-picker-filter"
            />
            <p
                v-if="visibleItems.length === 0"
                class="text-text-muted mt-3 text-sm"
            >
                Nothing matches.
            </p>
            <ul v-else class="mt-2 max-h-80 space-y-1.5 overflow-y-auto pr-1">
                <li
                    v-for="item in visibleItems"
                    :key="item.uuid"
                    class="border-border-subtle bg-surface-0 flex items-start gap-2 rounded-md border p-2"
                    data-testid="template-item"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-text-primary text-sm font-medium">
                            {{ item.title }}
                        </p>
                        <p
                            v-if="item.description"
                            class="text-text-secondary mt-0.5 line-clamp-2 text-xs"
                        >
                            {{ item.description }}
                        </p>
                        <p
                            v-if="item.parents.length > 0"
                            class="text-text-muted mt-0.5 truncate text-xs"
                        >
                            In the template under {{ item.parents.join(', ') }}
                        </p>
                    </div>
                    <Button
                        v-if="mode === 'single'"
                        size="sm"
                        variant="secondary"
                        data-testid="template-item-use"
                        @click="emit('take', item)"
                        >Use</Button
                    >
                    <span
                        v-else-if="
                            itemState(item) === 'here' ||
                            itemState(item) === 'added'
                        "
                        class="text-text-muted shrink-0 pt-1 text-xs"
                        data-testid="template-item-done"
                        >{{ ACTION_LABELS[itemState(item)] }}</span
                    >
                    <Button
                        v-else
                        size="sm"
                        variant="secondary"
                        :loading="busy === item.uuid"
                        :disabled="busy !== null && busy !== undefined"
                        :title="
                            itemState(item) === 'link'
                                ? `A ${childType} with this title is already in this assessment; link it here instead of copying it`
                                : undefined
                        "
                        data-testid="template-item-take"
                        @click="emit('take', item)"
                        >{{ ACTION_LABELS[itemState(item)] }}</Button
                    >
                </li>
            </ul>
        </template>
    </div>
</template>
