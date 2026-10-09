<script setup lang="ts">
/**
 * One tabbed dashboard section (W73 item 21, D32).
 *
 * The dashboard was four stacked sections. The two that answer *what needs
 * me* — Assigned to me, Mentions — and the two that answer *where do I go* —
 * Mine, Shared with me — were half a screen apart, and neither pair fit above
 * the fold. D32 pairs them instead: two sections, two tabs each.
 *
 * ## The counts are on the labels, deliberately
 *
 * A tab hides its panel until it is clicked, so a count that lived inside the
 * panel would be a notification you cannot see until you have gone looking
 * for it — which is the opposite of what a notification is for. The number
 * therefore rides on the tab, as visible text inside the button so that it is
 * part of the tab's accessible name rather than a coloured pill a screen
 * reader walks past. `countLabel` says what the number counts when the label
 * alone does not ("3 unresolved" rather than "3").
 *
 * ## Why not Components/UI/Tabs.vue
 *
 * That one drives the node modal and is `v-model`-controlled with no
 * `aria-controls`, no `aria-labelledby` on its panels and no keyboard
 * handling — good enough inside a modal that manages its own focus, not good
 * enough for the arrival page, where the tablist is the first interactive
 * thing after the heading. It also belongs to another package. This is the
 * `DashboardTabs` wrapper W73 was given the choice of building.
 *
 * Roving tabindex and arrow keys per the APG tabs pattern: exactly one tab is
 * in the tab order, and Left/Right/Home/End move between them. Manual
 * activation would need an extra Enter per tab; automatic activation is
 * correct here because switching panels is free and reversible.
 */
import { computed, ref, useId } from 'vue';

export interface DashboardTab {
    key: string;
    label: string;
    count: number;
    /** What the count counts, when "3" alone would not say. */
    countLabel?: string;
}

const props = defineProps<{
    /** Names the tablist for a screen reader; matches the section heading. */
    label: string;
    tabs: DashboardTab[];
}>();

const active = ref(props.tabs[0]?.key ?? '');
const uid = useId();

const tabId = (key: string) => `${uid}-tab-${key}`;
const panelId = (key: string) => `${uid}-panel-${key}`;

const activeIndex = computed(() =>
    props.tabs.findIndex((tab) => tab.key === active.value),
);

/**
 * Focus follows selection, so the DOM node has to exist before it can be
 * focused — the panels are `v-show`, but the tab buttons are all rendered, so
 * this is a lookup rather than a `nextTick`.
 */
function move(delta: number | 'first' | 'last', event: Event): void {
    event.preventDefault();

    const last = props.tabs.length - 1;
    let next: number;

    if (delta === 'first') {
        next = 0;
    } else if (delta === 'last') {
        next = last;
    } else {
        next =
            (activeIndex.value + delta + props.tabs.length) % props.tabs.length;
    }

    const key = props.tabs[next]?.key;
    if (key === undefined) {
        return;
    }

    active.value = key;
    document.getElementById(tabId(key))?.focus();
}
</script>

<template>
    <div>
        <div
            role="tablist"
            :aria-label="label"
            class="border-border-subtle flex gap-1 border-b"
        >
            <button
                v-for="tab in tabs"
                :id="tabId(tab.key)"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="active === tab.key"
                :aria-controls="panelId(tab.key)"
                :tabindex="active === tab.key ? 0 : -1"
                class="focus-visible:outline-focus-ring flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap outline-none focus-visible:outline-2 focus-visible:-outline-offset-2"
                :class="
                    active === tab.key
                        ? 'border-node-root-text text-node-root-text'
                        : 'text-text-muted hover:text-text-primary border-transparent'
                "
                @click="active = tab.key"
                @keydown.left="move(-1, $event)"
                @keydown.right="move(1, $event)"
                @keydown.home="move('first', $event)"
                @keydown.end="move('last', $event)"
            >
                {{ tab.label }}
                <!--
                    Plain text inside the button, not an `aria-hidden` pill:
                    the number is the reason the tab is worth clicking, so it
                    has to survive into the accessible name.
                -->
                <span
                    v-if="tab.count > 0"
                    class="bg-surface-2 text-text-secondary rounded-full px-1.5 text-xs"
                >
                    {{ tab.count }}
                </span>
                <span v-if="tab.count > 0 && tab.countLabel" class="sr-only">
                    {{ tab.countLabel }}
                </span>
            </button>
        </div>

        <div
            v-for="tab in tabs"
            v-show="active === tab.key"
            :id="panelId(tab.key)"
            :key="tab.key"
            role="tabpanel"
            :aria-labelledby="tabId(tab.key)"
            class="pt-4"
        >
            <slot :name="tab.key" />
        </div>
    </div>
</template>
