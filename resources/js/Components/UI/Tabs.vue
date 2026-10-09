<script setup lang="ts">
/**
 * Drives the node modal's Details/Values/Links/References/Comments/History
 * tabs (docs/specs/ui.md §7). Each tab's content is a named slot keyed by
 * `tab.key`, so the modal stays in control of what each panel renders.
 *
 * ## Panels are lazy, and stay mounted once opened (W71 item 1)
 *
 * They used to be `v-show` only, which mounted all eight the moment any node
 * was opened. Two things followed, and both were bugs:
 *
 * - Four of those panels fetch on mount (History, Comments, References,
 *   Statistics), so opening *any* node cost four requests nobody had asked
 *   for — the same class of waste W68 removed on the write path.
 * - A throw in any one of them during render tears down this whole subtree,
 *   and Details, the panel the user is actually looking at, is the visible
 *   casualty. That is the "Details tab sometimes does not load" report.
 *
 * `v-if` on first activation fixes both. `v-show` afterwards is the other
 * half and is not optional: a panel that unmounted on every tab switch would
 * lose an unsent comment draft and refetch its history each time back.
 */
import { computed, ref, useId, watch } from 'vue';
import Badge from './Badge.vue';
import Icon from './Icon.vue';
import type { TabItem } from './types';

const props = defineProps<{
    tabs: TabItem[];
    modelValue: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [key: string] }>();

const uid = useId();
const tabId = (key: string) => `${uid}-tab-${key}`;
const panelId = (key: string) => `${uid}-panel-${key}`;

/**
 * Insertion-ordered and never pruned: "has this key ever been the selected
 * tab?" An array rather than a Set only because it reads the same and needs
 * no reactivity caveat.
 */
const activated = ref<string[]>([props.modelValue]);

watch(
    () => props.modelValue,
    (key) => {
        if (!activated.value.includes(key)) activated.value.push(key);
    },
);

const strip = ref<HTMLElement | null>(null);

const keys = computed(() => props.tabs.map((tab) => tab.key));

/**
 * Roving focus: exactly one tab in the strip is tabbable, and the arrow keys
 * move between them (WAI-ARIA's tabs pattern; docs/specs/ui.md §11 requires
 * every action be reachable by keyboard). Order here is the *strip's* order,
 * not the visual row order, which is why wrapping the strip onto two rows
 * (item 16) changes nothing about it: Right from the last tab of row one goes
 * to the first tab of row two, which is the next tab.
 */
function move(index: number) {
    emit('update:modelValue', keys.value[index]);
    // The newly selected tab is the only one that is tabbable, so focus has
    // to follow the selection or it lands on the body at the next Tab press.
    // Indexed off the rendered buttons rather than matched by key: a tab key
    // is caller-supplied and would need escaping to be safe in a selector.
    strip.value?.querySelectorAll<HTMLElement>('[role="tab"]')[index]?.focus();
}

function onKeydown(event: KeyboardEvent) {
    const index = keys.value.indexOf(props.modelValue);
    if (index === -1) return;
    const last = keys.value.length - 1;

    if (event.key === 'ArrowRight') {
        move(index === last ? 0 : index + 1);
    } else if (event.key === 'ArrowLeft') {
        move(index === 0 ? last : index - 1);
    } else if (event.key === 'Home') {
        move(0);
    } else if (event.key === 'End') {
        move(last);
    } else {
        return;
    }

    // Only reached when a key was handled: Home/End otherwise scroll the
    // modal body out from under the strip.
    event.preventDefault();
}
</script>

<template>
    <div>
        <!--
            `flex-wrap`, not `overflow-x-auto` (W71 item 16, D30). A horizontal
            scroller hides tabs behind an edge with no affordance saying so;
            a defence has eight of them and a narrow modal cut the last two.
            The single bottom edge under the whole strip is this container's
            `border-b`, so it stays one edge however many rows the buttons
            take — the selected tab's own `border-b-2` sits on its row.
        -->
        <div
            ref="strip"
            role="tablist"
            class="border-border-subtle flex flex-wrap gap-1 border-b px-2"
            @keydown="onKeydown"
        >
            <button
                v-for="tab in tabs"
                :id="tabId(tab.key)"
                :key="tab.key"
                role="tab"
                type="button"
                :aria-selected="modelValue === tab.key"
                :aria-controls="
                    activated.includes(tab.key) ? panelId(tab.key) : undefined
                "
                :tabindex="modelValue === tab.key ? 0 : -1"
                class="focus-visible:outline-focus-ring flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap outline-none focus-visible:outline-2 focus-visible:outline-offset-2"
                :class="
                    modelValue === tab.key
                        ? 'border-node-root-text text-node-root-text'
                        : 'text-text-muted hover:text-text-primary border-transparent'
                "
                @click="$emit('update:modelValue', tab.key)"
            >
                <Icon v-if="tab.icon" :name="tab.icon" :size="14" />
                {{ tab.label }}
                <Badge v-if="tab.badge" tone="neutral">{{ tab.badge }}</Badge>
            </button>
        </div>

        <!--
            The `v-if` is on the panel element, not on the slot inside it: an
            empty `<div role="tabpanel">` for a tab nobody has opened is still
            a panel to a screen reader, and still an element `aria-controls`
            can point at, which is the confusion laziness is meant to remove.
        -->
        <template v-for="tab in tabs" :key="tab.key">
            <div
                v-if="activated.includes(tab.key)"
                v-show="modelValue === tab.key"
                :id="panelId(tab.key)"
                role="tabpanel"
                :aria-labelledby="tabId(tab.key)"
            >
                <slot :name="tab.key" />
            </div>
        </template>
    </div>
</template>
