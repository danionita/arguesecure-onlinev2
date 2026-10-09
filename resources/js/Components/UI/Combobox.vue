<script setup lang="ts">
/**
 * Searchable single-select. Backs the assessment switcher (docs/specs/
 * ui.md §2) and the "search existing users" step of the sharing dialog
 * (§10). Filtering is left to the caller (via `options`, already
 * filtered) so it can be backed by a real search endpoint later without
 * this component changing.
 *
 * ## The listbox is teleported (W71 item 6, PLAN-2.4.md D29)
 *
 * It used to be `absolute` inside this component's own root. Two of the
 * comboboxes in the node modal are the *last* element of their section, and
 * `Modal`'s body is `overflow-y-auto`: an absolutely-positioned child of a
 * scrolling ancestor is clipped by it, so those two opened their list into
 * space the user could not see. No amount of `z-index` fixes that — clipping
 * is not stacking.
 *
 * So the popup goes to `body` as `position: fixed`, anchored to the input by
 * measurement, and flips above the input when there is not room below.
 * Teleporting costs two things that have to be paid back explicitly:
 *
 * - **The ARIA relationships are no longer structural.** `aria-controls` and
 *   `aria-activedescendant` are id references and survive the move, but only
 *   because they are now written down; before the teleport the containment
 *   did that job implicitly. They are asserted in tests/js/ui/combobox.spec.ts.
 * - **Outside-click-to-close was containment too.** A `blur` handler alone is
 *   not enough once the list is elsewhere in the DOM, so closing is decided by
 *   an explicit "is the target inside either the root or the popup?" test.
 */
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import Icon from './Icon.vue';
import type { ComboboxOption } from './types';

const props = withDefaults(
    defineProps<{
        modelValue: string | null;
        options: ComboboxOption[];
        placeholder?: string;
        label?: string;
    }>(),
    {
        placeholder: 'Search…',
        label: undefined,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    'update:query': [query: string];
}>();

const query = ref('');
const open = ref(false);
const activeIndex = ref(-1);

const uid = useId();
const listboxId = `${uid}-listbox`;
const optionId = (index: number) => `${uid}-option-${index}`;

const root = ref<HTMLElement | null>(null);
const anchor = ref<HTMLElement | null>(null);
const popup = ref<HTMLElement | null>(null);

const selectedLabel = computed(
    () => props.options.find((o) => o.value === props.modelValue)?.label ?? '',
);

/** Matches the old `max-h-60`; kept as a number because the flip has to measure against it. */
const MAX_POPUP_HEIGHT = 240;
/** Matches the old `mt-1`. */
const GAP = 4;

const placement = ref<'below' | 'above'>('below');
const popupStyle = ref<Record<string, string>>({});

/**
 * Anchors the fixed popup to the input. Recomputed rather than cached because
 * the input can move under it without ever re-rendering — the modal body it
 * sits in scrolls, and a fixed element does not scroll with its anchor.
 */
function updatePosition(): void {
    const rect = anchor.value?.getBoundingClientRect();
    if (!rect) return;

    const below = window.innerHeight - rect.bottom - GAP;
    const above = rect.top - GAP;

    // Flip only when below genuinely cannot hold the list *and* above is the
    // better of the two. Flipping toward an equally cramped space would just
    // move the problem while making the list jump.
    const flip = below < MAX_POPUP_HEIGHT && above > below;
    placement.value = flip ? 'above' : 'below';

    popupStyle.value = {
        position: 'fixed',
        left: `${rect.left}px`,
        width: `${rect.width}px`,
        maxHeight: `${Math.max(Math.min(MAX_POPUP_HEIGHT, flip ? above : below), 0)}px`,
        ...(flip
            ? { bottom: `${window.innerHeight - rect.top + GAP}px` }
            : { top: `${rect.bottom + GAP}px` }),
    };
}

/**
 * `capture: true` on scroll: the modal body that clips this is an ancestor,
 * not `window`, and a scroll event on an element only reaches `window` during
 * the capture phase.
 */
function bindReposition(): void {
    window.addEventListener('scroll', updatePosition, true);
    window.addEventListener('resize', updatePosition);
    document.addEventListener('mousedown', onDocumentMousedown, true);
}

function unbindReposition(): void {
    window.removeEventListener('scroll', updatePosition, true);
    window.removeEventListener('resize', updatePosition);
    document.removeEventListener('mousedown', onDocumentMousedown, true);
}

/**
 * Closing on an outside click used to be containment — the listbox was inside
 * `root`, so a `blur` was enough. It is not any more: the popup is a sibling
 * of the app in `body`, and a click in it would blur the input. So "outside"
 * is spelled out as neither root nor popup.
 */
function onDocumentMousedown(event: MouseEvent): void {
    const target = event.target as Node | null;
    if (!target) return;
    if (root.value?.contains(target) || popup.value?.contains(target)) return;
    open.value = false;
}

watch(open, async (isOpen) => {
    if (!isOpen) {
        unbindReposition();
        return;
    }
    bindReposition();
    await nextTick();
    updatePosition();
});

// The list grows and shrinks as the caller re-filters, which changes how much
// room it needs and therefore whether it still fits below.
watch(
    () => props.options.length,
    async () => {
        if (!open.value) return;
        await nextTick();
        updatePosition();
    },
);

onBeforeUnmount(unbindReposition);

function onInput(event: Event) {
    query.value = (event.target as HTMLInputElement).value;
    open.value = true;
    activeIndex.value = -1;
    emit('update:query', query.value);
}

function choose(option: ComboboxOption) {
    emit('update:modelValue', option.value);
    query.value = '';
    open.value = false;
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        open.value = true;
        activeIndex.value = Math.min(
            activeIndex.value + 1,
            props.options.length - 1,
        );
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter' && activeIndex.value >= 0) {
        event.preventDefault();
        choose(props.options[activeIndex.value]);
    } else if (event.key === 'Escape') {
        open.value = false;
    }
}
</script>

<template>
    <div ref="root" class="relative">
        <label
            v-if="label"
            class="text-text-primary mb-1 block text-sm font-medium"
            >{{ label }}</label
        >
        <div ref="anchor" class="relative">
            <Icon
                name="search"
                :size="14"
                class="text-text-muted pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2"
            />
            <input
                type="text"
                role="combobox"
                aria-autocomplete="list"
                :aria-expanded="open"
                :aria-controls="open ? listboxId : undefined"
                :aria-activedescendant="
                    open && activeIndex >= 0 ? optionId(activeIndex) : undefined
                "
                :value="open ? query : selectedLabel"
                :placeholder="placeholder"
                class="border-border-default bg-surface-1 text-text-primary placeholder:text-text-muted focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md pl-8 text-sm shadow-sm"
                @focus="open = true"
                @input="onInput"
                @keydown="onKeydown"
                @blur="open = false"
            />
        </div>

        <!--
            `to="body"` is what takes the list out of the scrolling, clipping
            ancestor. `:disabled` on the Teleport is deliberately absent: there
            is no case where staying put is right, and a conditional teleport
            would mean two DOM shapes for every test of this component to cover.
        -->
        <Teleport to="body">
            <div
                v-if="open"
                ref="popup"
                :data-combobox-placement="placement"
                class="z-50"
                :style="popupStyle"
                @mousedown.prevent
            >
                <ul
                    v-if="options.length > 0"
                    :id="listboxId"
                    role="listbox"
                    class="border-border-default bg-surface-1 max-h-full overflow-y-auto rounded-md border py-1 shadow-lg"
                >
                    <li
                        v-for="(option, index) in options"
                        :id="optionId(index)"
                        :key="option.value"
                        role="option"
                        :aria-selected="option.value === modelValue"
                        class="cursor-pointer px-3 py-1.5 text-sm"
                        :class="
                            index === activeIndex
                                ? 'bg-surface-2'
                                : 'hover:bg-surface-2'
                        "
                        @mousedown.prevent="choose(option)"
                    >
                        <div class="text-text-primary">{{ option.label }}</div>
                        <div
                            v-if="option.description"
                            class="text-text-muted text-xs"
                        >
                            {{ option.description }}
                        </div>
                    </li>
                </ul>
                <!--
                    Still carries the listbox id and role when empty: the input's
                    `aria-controls` points at it either way, and a dangling id
                    reference is worse than an empty list.
                -->
                <p
                    v-else
                    :id="listboxId"
                    role="listbox"
                    class="border-border-default bg-surface-1 text-text-muted rounded-md border px-3 py-2 text-sm shadow-lg"
                >
                    No matches.
                </p>
            </div>
        </Teleport>
    </div>
</template>
