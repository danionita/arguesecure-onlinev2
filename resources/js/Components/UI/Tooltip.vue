<script setup lang="ts">
/**
 * Hover/focus tooltip. Used for things like the likelihood chip's
 * `≈ p%` hint (docs/specs/ui.md §3.2, §7). Shows on focus as well as
 * hover so it is reachable by keyboard, per the accessibility rules in
 * docs/specs/ui.md §11.
 *
 * ## Two slots, and which one you want (W69)
 *
 * The default slot is the **trigger** — the element being hovered. That is
 * the original, and every existing caller uses only that.
 *
 * `#content` is the **bubble**, and it exists because a tooltip that has to
 * list things cannot do it in a `text` string: the bubble is one inline
 * element, so newlines in a prop collapse to spaces and the list reads as a
 * run-on sentence. The chart card's "appears in N places" counter is the
 * first caller that needs it (PLAN-2.4.md item 11).
 *
 * Deliberately a slot rather than a `white-space: pre-line` variant. Pre-line
 * would carry the line breaks and nothing else — no emphasis on the heading,
 * no styling for the "and N more" tail — and would make every caller assemble
 * a string with `\n` in it. The slot is the smaller change and it costs the
 * existing callers nothing, because `text` is its fallback content.
 *
 * `text` is optional as of that change: a caller supplies one or the other.
 * A caller using `#content` must still put the same information on the
 * **trigger's** own accessible name, because the bubble is
 * `pointer-events-none` decoration that a screen reader never reaches.
 *
 * ## `right` (2.5.1)
 *
 * For the collapsed sidebar rail. Its items sit one above the other, so a
 * bubble above or below lands on the neighbouring icon — which is how it was
 * reported. To the right is the only side with room, and the rail is a
 * scrolling container that would clip an absolutely-positioned child there,
 * so this placement is teleported to `body` and positioned from the
 * trigger's measured rect instead.
 */
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        /** The bubble's text. Optional only because `#content` can replace it. */
        text?: string;
        placement?: 'top' | 'bottom' | 'right';
    }>(),
    {
        text: undefined,
        placement: 'top',
    },
);

const visible = ref(false);
const trigger = ref<HTMLElement | null>(null);
const fixedStyle = ref<Record<string, string>>({});

const teleported = computed(() => props.placement === 'right');

function show() {
    if (teleported.value && trigger.value) {
        const rect = trigger.value.getBoundingClientRect();
        fixedStyle.value = {
            position: 'fixed',
            left: `${rect.right + 8}px`,
            top: `${rect.top + rect.height / 2}px`,
            transform: 'translateY(-50%)',
        };
    }
    visible.value = true;
}
</script>

<template>
    <span
        ref="trigger"
        class="relative inline-flex"
        @mouseenter="show"
        @mouseleave="visible = false"
        @focusin="show"
        @focusout="visible = false"
    >
        <slot />
        <Teleport v-if="teleported" to="body">
            <span
                v-if="visible"
                role="tooltip"
                class="border-border-strong bg-surface-2 text-text-primary pointer-events-none z-50 w-max max-w-64 rounded border px-2 py-1 text-xs shadow-md"
                :style="fixedStyle"
                data-placement="right"
            >
                <slot name="content">{{ text }}</slot>
            </span>
        </Teleport>
        <span
            v-else-if="visible"
            role="tooltip"
            class="border-border-strong bg-surface-2 text-text-primary pointer-events-none absolute left-1/2 z-50 w-max max-w-64 -translate-x-1/2 rounded border px-2 py-1 text-xs shadow-md"
            :class="
                placement === 'top' ? 'bottom-full mb-1.5' : 'top-full mt-1.5'
            "
        >
            <slot name="content">{{ text }}</slot>
        </span>
    </span>
</template>
