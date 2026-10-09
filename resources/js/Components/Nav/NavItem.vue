<script setup lang="ts">
/**
 * One destination in the sidebar (W41).
 *
 * Icon-and-label in the panel, icon-only in the rail — and in the rail the
 * label does not disappear, it moves to `aria-label` and to a `title`. A rail
 * of unlabelled glyphs is unusable to a screen reader and merely a guessing
 * game for everyone else.
 *
 * `aria-current="page"` rather than colour alone, for the same reason every
 * chip in this codebase pairs colour with an icon (ui.md §2): the current
 * item has to be identifiable without colour vision.
 *
 * ## The rail's visible half (W82 item 17)
 *
 * `title` already gives the rail a *native* tooltip, but a native one is
 * whatever the browser's UA stylesheet decides — different chrome, a
 * different delay, no place for it to match the app's own surfaces. Sidebar
 * already puts the accessible half of this on the logo and the toggle
 * (`aria-label`); this is the same thing's visible half, using the
 * `Tooltip` component every other hint in the product already goes through
 * rather than a second one built for this file. It wraps only in `compact`
 * mode — the panel already shows the label in text, and wrapping there too
 * would tool-tip a control that is not missing anything.
 *
 * ## One inner element, wrapped or not (W82 review fix)
 *
 * A first version of this file duplicated the whole interactive element —
 * the `:is`, `:type`, `:href`, `:method`, `:as`, `:aria-current`, the class
 * list, the click guard, the icon and the badge markup — once inside a
 * `v-if="compact"` branch wrapped in `Tooltip` and once again in a `v-else`
 * that was not. That is the exact thing this package exists to remove:
 * `AssessmentActions.vue`'s own docblock argues D40 exists because five
 * actions in four arrangements "have to agree by inspection", and a second
 * copy of one control here is the same failure at a smaller scale — a future
 * `aria-*`, a disabled state, a different click guard, would only reliably
 * reach one branch. `wrapper` below picks between `Tooltip` and `Unwrapped`
 * — a one-line functional component that renders its slot and nothing
 * else — so the interactive element itself is written once and only its
 * wrapper changes. (A bare `Fragment` looks like the obvious choice here
 * and is not one: `<component :is="Fragment">`'s children compile to a
 * *slot function*, and `Fragment` is a VNode-type marker with no render
 * logic to call that function — nothing appears. A component's `setup()`
 * is what actually invokes `slots.default()`.)
 */
import Icon from '@/Components/UI/Icon.vue';
import type { IconName } from '@/Components/UI/icons.generated';
import Tooltip from '@/Components/UI/Tooltip.vue';
import { Link } from '@inertiajs/vue3';
import { computed, type FunctionalComponent } from 'vue';

const Unwrapped: FunctionalComponent = (_props, { slots }) => slots.default?.();

const props = withDefaults(
    defineProps<{
        icon: IconName;
        label: string;
        /**
         * Omitted for an item that opens something instead of going somewhere
         * (W47's Notifications). It then renders a `<button>` and emits
         * `select` — same styling, same accessible name, same rail dot, so a
         * panel-opening item cannot drift from the destinations around it.
         */
        href?: string;
        /** Collapsed to the icon rail: the label stays, as an accessible name. */
        compact?: boolean;
        current?: boolean;
        /** Rendered to the right of the label; hidden in the rail, where there is no room. */
        badge?: number;
        method?: 'get' | 'post';
    }>(),
    {
        href: undefined,
        compact: false,
        current: false,
        badge: 0,
        method: 'get',
    },
);

const emit = defineEmits<{ select: [] }>();

/**
 * In the rail the count cannot be rendered, so it goes into the name instead
 * — "Notifications, 3 unread" rather than a bare dot nobody can hear.
 */
const accessibleName = computed(() =>
    props.badge > 0 ? `${props.label}, ${props.badge} unread` : props.label,
);

/**
 * `Tooltip` in the rail, `Unwrapped` in the panel — see the "One inner
 * element" note above. `wrapperProps` is `{}` in the `Unwrapped` case, so
 * the `<component :is="wrapper">` below never needs to know which one it
 * got.
 */
const wrapper = computed(() => (props.compact ? Tooltip : Unwrapped));
const wrapperProps = computed(() =>
    props.compact
        ? {
              text: accessibleName.value,
              // To the side: above or below lands on the next icon in the
              // rail, which is how it was reported (2.5.1).
              placement: 'right' as const,
              class: 'w-full',
          }
        : {},
);
</script>

<template>
    <component :is="wrapper" v-bind="wrapperProps">
        <component
            :is="href === undefined ? 'button' : Link"
            :type="href === undefined ? 'button' : undefined"
            :href="href"
            :method="href === undefined ? undefined : method"
            :as="href !== undefined && method === 'post' ? 'button' : undefined"
            :aria-label="compact ? accessibleName : undefined"
            :aria-current="current ? 'page' : undefined"
            class="focus-visible:outline-focus-ring relative flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm outline-none focus-visible:outline-2 focus-visible:-outline-offset-2"
            :class="[
                current
                    ? 'bg-surface-2 text-text-primary font-medium'
                    : 'text-text-secondary hover:bg-surface-2 hover:text-text-primary',
                compact && 'justify-center',
            ]"
            @click="href === undefined && emit('select')"
        >
            <Icon :name="icon" :size="16" class="shrink-0" />
            <template v-if="!compact">
                <span class="min-w-0 flex-1 truncate text-left">{{
                    label
                }}</span>
                <span
                    v-if="badge > 0"
                    class="bg-node-attack-bg text-node-attack-text rounded-full px-1.5 text-xs font-medium"
                    >{{ badge }}</span
                >
            </template>
            <!--
                In the rail there is no room for the number, so it becomes a
                dot. Purely decorative: the count is already in
                `accessibleName` above, and announcing it twice is worse than
                not drawing it.
            -->
            <span
                v-else-if="badge > 0"
                aria-hidden="true"
                class="bg-node-attack-text absolute top-1 right-1 h-1.5 w-1.5 rounded-full"
            />
        </component>
    </component>
</template>
