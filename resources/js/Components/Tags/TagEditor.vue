<script setup lang="ts">
/**
 * The label row on the Details tab (W43, item 18; ui.md §7).
 *
 * ## Type it, do not manage it
 *
 * There is no separate screen for defining labels, so this field does both
 * jobs: it offers the ones this assessment already uses, and it accepts one
 * it has never seen. Enter commits whatever is in the box — the highlighted
 * suggestion if the user arrowed to one, the typed text otherwise. That
 * ordering matters: someone typing "PCI" who has not looked at the list
 * expects "PCI", not the first thing that happens to be highlighted, which
 * is why nothing is highlighted until an arrow key says so.
 *
 * Duplicates resolve on the server by slug (TagService), so "pci dss" and
 * "PCI DSS" are one label and the store renders whichever spelling the
 * assessment settled on first. Nothing here tries to second-guess that
 * locally: two implementations of one rule is how they drift.
 *
 * ## Why not a Combobox
 *
 * `UI/Combobox.vue` selects one of a fixed set and cannot express "or
 * something new", which is the whole point here. Extending it to accept
 * free text would change the behaviour of the assessment switcher and the
 * sharing dialog, where accepting an unknown value would be a bug.
 */
import Icon from '@/Components/UI/Icon.vue';
import { computed, ref, useId } from 'vue';
import TagChip from './TagChip.vue';

const props = withDefaults(
    defineProps<{
        /** The labels this node carries, in the order the server sent them. */
        names: string[];
        /** Every label used anywhere in this assessment. */
        available: string[];
        canEdit?: boolean;
    }>(),
    { canEdit: false },
);

const emit = defineEmits<{ add: [name: string]; remove: [name: string] }>();

const draft = ref('');
const open = ref(false);
const activeIndex = ref(-1);
const listboxId = useId();

/**
 * Everything not already on this node that matches what is typed. Filtered
 * on the client because the whole vocabulary is already here — it arrives
 * with the graph — so a request per keystroke would fetch data the page is
 * holding.
 */
const suggestions = computed(() => {
    const term = draft.value.trim().toLowerCase();
    const taken = new Set(props.names.map((name) => name.toLowerCase()));

    return props.available
        .filter((name) => !taken.has(name.toLowerCase()))
        .filter((name) => term === '' || name.toLowerCase().includes(term))
        .slice(0, 8);
});

/** An exact match is already offered by the suggestion list; adding it again is not a new label. */
const alreadyOnNode = computed(() =>
    props.names.some(
        (name) => name.toLowerCase() === draft.value.trim().toLowerCase(),
    ),
);

function commit(name: string): void {
    const value = name.trim();
    if (value === '') return;

    // Closed before the emit, not after: the list is a list of things to
    // pick, and leaving it open over a field that is mid-save invites a
    // second pick that would race the first. The save itself is the parent's
    // — this field does not know or wait for it, which is why there is no
    // in-flight flag here pretending to guard something it cannot see.
    close();
    emit('add', value);
}

function onEnter(): void {
    const highlighted =
        activeIndex.value >= 0 ? suggestions.value[activeIndex.value] : null;

    commit(highlighted ?? draft.value);
    draft.value = '';
}

function move(delta: number): void {
    if (suggestions.value.length === 0) return;
    open.value = true;

    const next = activeIndex.value + delta;
    activeIndex.value =
        next < 0
            ? suggestions.value.length - 1
            : next >= suggestions.value.length
              ? 0
              : next;
}

function choose(name: string): void {
    commit(name);
    draft.value = '';
}

/**
 * Backspace on an empty box removes the last label — the convention every
 * chip input has, and the only way to undo a mistyped label without
 * reaching for the mouse.
 */
function onBackspace(): void {
    if (draft.value !== '' || props.names.length === 0) return;
    emit('remove', props.names[props.names.length - 1]);
}

function close(): void {
    open.value = false;
    activeIndex.value = -1;
}
</script>

<template>
    <section data-testid="labels">
        <h3 class="text-text-primary mb-1 text-sm font-medium">Labels</h3>

        <ul v-if="names.length" class="mb-2 flex flex-wrap gap-1">
            <li v-for="name in names" :key="name" :data-label="name">
                <TagChip
                    :name="name"
                    :removable="canEdit"
                    @remove="emit('remove', name)"
                />
            </li>
        </ul>
        <p v-else-if="!canEdit" class="text-text-muted mb-2 text-xs">
            No labels.
        </p>

        <div v-if="canEdit" class="relative">
            <label class="sr-only" :for="`${listboxId}-input`"
                >Add a label</label
            >
            <div
                class="border-border-default bg-surface-1 focus-within:border-focus-ring flex items-center gap-1 rounded-md border px-2"
            >
                <Icon name="tag" :size="12" class="text-text-muted shrink-0" />
                <input
                    :id="`${listboxId}-input`"
                    v-model="draft"
                    type="text"
                    role="combobox"
                    autocomplete="off"
                    maxlength="60"
                    aria-autocomplete="list"
                    :aria-expanded="open && suggestions.length > 0"
                    :aria-controls="listboxId"
                    placeholder="Add a label and press Enter"
                    class="text-text-primary w-full border-0 bg-transparent py-1.5 text-sm focus:ring-0"
                    @focus="open = true"
                    @input="
                        open = true;
                        activeIndex = -1;
                    "
                    @keydown.enter.prevent="onEnter"
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    @keydown.esc.stop="close"
                    @keydown.backspace="onBackspace"
                    @blur="close"
                />
            </div>

            <ul
                v-show="open && suggestions.length > 0"
                :id="listboxId"
                role="listbox"
                aria-label="Labels already used here"
                class="border-border-default bg-surface-1 absolute z-10 mt-1 w-full overflow-hidden rounded-md border shadow-lg"
            >
                <li
                    v-for="(name, index) in suggestions"
                    :key="name"
                    role="option"
                    :aria-selected="index === activeIndex"
                    class="hover:bg-surface-2 cursor-pointer px-2 py-1.5 text-sm"
                    :class="index === activeIndex && 'bg-surface-2'"
                    @mousedown.prevent="choose(name)"
                >
                    {{ name }}
                </li>
            </ul>

            <p v-if="alreadyOnNode" class="text-text-muted mt-1 text-xs">
                Already on this node.
            </p>
            <p v-else class="text-text-muted mt-1 text-xs">
                Used to filter and to group. Labels are shared across this
                assessment.
            </p>
        </div>
    </section>
</template>
