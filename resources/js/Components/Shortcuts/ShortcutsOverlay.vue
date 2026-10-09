<script setup lang="ts">
/**
 * The keyboard-help overlay (ui.md §4: "Document all of it on a `?`
 * overlay, and make that overlay the first thing the help page links
 * to."). Self-contained: listens for `?` itself (ignoring it while the
 * user is typing in a field, and while any other key modifier is held so
 * `Shift+/` on a non-US layout doesn't fight normal typing), so the only
 * integration needed once this package's file-ownership gap is closed
 * (see useKeyboardOutline.ts's top-of-file note) is rendering
 * `<ShortcutsOverlay />` once, anywhere in the page — nothing needs to
 * pass it a v-model or wire a trigger.
 */
import Modal from '@/Components/UI/Modal.vue';
import { onMounted, onUnmounted, ref } from 'vue';

const show = ref(false);

interface Row {
    keys: string;
    action: string;
}

const rows: Row[] = [
    { keys: '↑ ↓', action: 'Move focus between visible nodes' },
    {
        keys: '← →',
        action: 'Collapse / expand, or move to parent / first child',
    },
    {
        keys: 'Enter',
        action: 'Add sibling below (on a node); commit (in a field)',
    },
    { keys: 'Tab', action: 'Add child' },
    // Shift+Tab (promote) and Ctrl/Cmd+K (command palette) are deliberately
    // absent: useKeyboardOutline.ts breaks out of Shift+Tab without acting,
    // and nothing supplies its onCommandPalette callback, so both do
    // nothing today. Listing a shortcut here is a promise that pressing it
    // works — restore each entry when its handler is actually wired.
    { keys: 'F2', action: 'Edit title inline' },
    { keys: 'Space', action: 'Open the node modal' },
    { keys: 'Escape', action: 'Cancel edit, close modal, or clear focus mode' },
    {
        keys: 'Delete',
        action: 'Delete focused node (with confirm if it has children)',
    },
    { keys: 'Ctrl/Cmd+Z', action: 'Undo' },
    { keys: 'Shift+Ctrl/Cmd+Z', action: 'Redo' },
    { keys: '/', action: 'Focus search' },
    { keys: 'Ctrl/Cmd + +/−/0', action: 'Zoom in / out / reset' },
    { keys: '?', action: 'This overlay' },
];

function isEditableTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false;
    const tag = target.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || target.isContentEditable;
}

function onKeydown(event: KeyboardEvent): void {
    if (
        event.key === '?' &&
        !event.ctrlKey &&
        !event.metaKey &&
        !isEditableTarget(event.target)
    ) {
        event.preventDefault();
        show.value = true;
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));

defineExpose({ show });
</script>

<template>
    <Modal
        :show="show"
        title="Keyboard shortcuts"
        size="md"
        @close="show = false"
    >
        <dl class="divide-border-subtle divide-y px-4 py-2">
            <div
                v-for="row in rows"
                :key="row.keys"
                class="flex items-center justify-between gap-4 py-2 text-sm"
            >
                <dt class="shrink-0">
                    <kbd
                        class="border-border-default bg-surface-2 text-text-primary rounded border px-1.5 py-0.5 font-mono text-xs"
                    >
                        {{ row.keys }}
                    </kbd>
                </dt>
                <dd class="text-text-muted text-right">{{ row.action }}</dd>
            </div>
        </dl>
    </Modal>
</template>
