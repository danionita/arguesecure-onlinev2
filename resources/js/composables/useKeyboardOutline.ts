/**
 * Keyboard-first outlining (ui.md §3.1 "the keyboard is the primary
 * interface", §4's keyboard map, PLAN.md W14).
 *
 * ## Integration gap — read before wiring this in
 *
 * This package owns `resources/js/composables/**` and
 * `resources/js/Components/Shortcuts/**`, but not
 * `resources/js/Pages/Assessment/Show.vue`, `resources/js/Layouts/**` or
 * `resources/js/app.ts` (W12/W10's ownership). Nothing in this file can
 * therefore call itself into existence — some component in one of those
 * three places must call `useKeyboardOutline(...)` once, from its own
 * `<script setup>`. That one-line integration is intentionally left for
 * whoever owns those files; see the W14 completion report for the exact
 * change needed (`ref` on `<ul role="tree">` in Show.vue, plus one call in
 * its setup).
 *
 * ## Design
 *
 * The store (assessmentStore.ts) has no concept of "the currently focused
 * node" — occurrence state there is only `occurrenceExpanded`. Rather than
 * add that to a store this package does not own, focus is tracked here as
 * a local `focusedPath` ref, computed purely against the store's existing
 * read API (`rootUuid`, `risksOrder`, `attacksUnderRisk`,
 * `defencesUnderAttack`, `isExpanded`) — the exact same functions
 * TreeNode.vue itself uses to decide rendering order, so the flattened
 * list here always matches DOM order.
 *
 * Real DOM focus (for a visible focus ring and correct screen-reader
 * behaviour) is delegated to the focusable descendant of the matching
 * `[role="treeitem"]` element, found by *position* in that same flattened
 * list — TreeNode.vue already renders one `<li role="treeitem">` per
 * occurrence in this exact order (ui.md §11: "The tree is an ARIA `tree`
 * with `treeitem` roles"), so this never needs to touch NodeCard.vue or
 * TreeNode.vue internals, only the public ARIA contract those two files
 * already promise.
 */
import { onMounted, onUnmounted, ref, type Ref } from 'vue';
import type { AssessmentStore } from '../stores/assessmentStore';
import { occurrencePath } from '../stores/assessmentStore';

export interface UseKeyboardOutlineOptions {
    store: AssessmentStore;
    /** The element containing `<ul role="tree">` — scopes ArrowUp/Down/etc so they don't fire while e.g. a modal input has focus elsewhere. */
    container: Ref<HTMLElement | null>;
    onOpenModal?: (uuid: string, path: string) => void;
    /**
     * Opens the node modal on a draft child of this parent (2.5.1). Adding
     * never writes by itself any more — the node is created when the draft
     * is confirmed — so the keyboard hands the parent over rather than
     * calling `store.addChild` with an empty title.
     */
    onAddChild?: (
        parentType: 'root' | 'risk' | 'attack',
        parentUuid: string,
        parentPath: string,
    ) => void;
    onUndo?: () => void;
    onRedo?: () => void;
    onFocusSearch?: () => void;
    onCommandPalette?: () => void;
    onZoom?: (direction: 'in' | 'out' | 'reset') => void;
    onHelp?: () => void;
}

export interface UseKeyboardOutline {
    focusedPath: Ref<string | null>;
    /** Recomputed on demand rather than cached — cheap, and always exercises the exact same store calls TreeNode.vue's own render pass makes. */
    visiblePaths: () => string[];
    setFocus: (path: string | null) => void;
    handleKeydown: (event: KeyboardEvent) => void;
}

function pathUuid(path: string): string {
    const parts = path.split('/');
    return parts[parts.length - 1];
}

function isEditableTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false;
    const tag = target.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || target.isContentEditable;
}

export function useKeyboardOutline(
    options: UseKeyboardOutlineOptions,
): UseKeyboardOutline {
    const { store } = options;
    const focusedPath = ref<string | null>(null);

    /**
     * Walks the same three levels TreeNode.vue's own `childUuids` computed
     * does (root -> risks -> attacks -> defences), skipping the children of
     * a collapsed occurrence exactly as the renderer skips rendering them —
     * this is what keeps "visible" here meaning the same thing it means on
     * screen.
     */
    function visiblePaths(): string[] {
        const root = store.rootUuid();
        if (!root) return [];

        const out: string[] = [];

        function visit(uuid: string, ancestors: string[]): void {
            const path = occurrencePath(...ancestors, uuid);
            out.push(path);

            if (!store.isExpanded(path)) return;

            const node = store.nodes[uuid];
            if (!node) return;

            const childUuids =
                node.type === 'root'
                    ? store.risksOrder()
                    : node.type === 'risk'
                      ? store.attacksUnderRisk(uuid)
                      : node.type === 'attack'
                        ? store.defencesUnderAttack(uuid)
                        : [];

            for (const childUuid of childUuids) {
                visit(childUuid, [...ancestors, uuid]);
            }
        }

        visit(root, []);
        return out;
    }

    function treeItemElements(): HTMLElement[] {
        if (!options.container.value) return [];
        return Array.from(
            options.container.value.querySelectorAll<HTMLElement>(
                '[role="treeitem"]',
            ),
        );
    }

    /** The first focusable descendant of a treeitem `<li>` — its title button/input today, but deliberately not hardcoded to a class name that NodeCard.vue could rename. */
    function focusableWithin(el: HTMLElement): HTMLElement | null {
        return el.querySelector<HTMLElement>(
            'button, input, [tabindex]:not([tabindex="-1"])',
        );
    }

    function setFocus(path: string | null): void {
        focusedPath.value = path;
        if (path === null) return;

        const paths = visiblePaths();
        const index = paths.indexOf(path);
        if (index === -1) return;

        const el = treeItemElements()[index];
        const target = el && focusableWithin(el);
        target?.focus();
        el?.setAttribute('aria-selected', 'true');
        for (const other of treeItemElements()) {
            if (other !== el) other.removeAttribute('aria-selected');
        }
    }

    function moveFocus(delta: number): void {
        const paths = visiblePaths();
        if (paths.length === 0) return;
        const current = focusedPath.value
            ? paths.indexOf(focusedPath.value)
            : -1;
        const next = Math.min(Math.max(current + delta, 0), paths.length - 1);
        setFocus(paths[next]);
    }

    function collapseOrToParent(): void {
        const path = focusedPath.value;
        if (!path) return;
        if (store.isExpanded(path)) {
            store.toggleExpanded(path);
            return;
        }
        const parts = path.split('/');
        if (parts.length > 1) {
            setFocus(parts.slice(0, -1).join('/'));
        }
    }

    function expandOrToFirstChild(): void {
        const path = focusedPath.value;
        if (!path) return;
        if (!store.isExpanded(path)) {
            store.toggleExpanded(path);
            return;
        }
        const paths = visiblePaths();
        const index = paths.indexOf(path);
        const next = paths[index + 1];
        if (next && next.startsWith(`${path}/`)) setFocus(next);
    }

    /**
     * `Enter` on a focused node adds a sibling (ui.md §4) — i.e. a new
     * child of *this node's own parent*, which `store.addChild()` already
     * expresses given the parent's type/uuid/path (ui.md §3.3: `addChild`
     * is keyed on the parent, not on "insert after this sibling" — the new
     * node's position is simply appended, matching addChild's own
     * contract).
     */
    function addSibling(): void {
        const path = focusedPath.value;
        if (!path) return;
        const parts = path.split('/');
        if (parts.length < 2) return; // The root has no siblings.
        const parentUuid = parts[parts.length - 2];
        const parentPath = parts.slice(0, -1).join('/');
        const parentNode = store.nodes[parentUuid];
        if (
            !parentNode ||
            (parentNode.type !== 'root' &&
                parentNode.type !== 'risk' &&
                parentNode.type !== 'attack')
        ) {
            return;
        }
        options.onAddChild?.(parentNode.type, parentUuid, parentPath);
    }

    function addChild(): void {
        const path = focusedPath.value;
        if (!path) return;
        const uuid = pathUuid(path);
        const node = store.nodes[uuid];
        if (
            !node ||
            (node.type !== 'root' &&
                node.type !== 'risk' &&
                node.type !== 'attack')
        ) {
            return; // ui.md §3.2: a defence/transfer has no childType.
        }
        options.onAddChild?.(node.type, uuid, path);
    }

    async function deleteFocused(): Promise<void> {
        const path = focusedPath.value;
        if (!path) return;
        const uuid = pathUuid(path);
        // NodeCard.vue's own delete action opens a ConfirmDialog when the
        // node has children; this composable does not own that component
        // and cannot reach into it, so it deletes directly. A future pass
        // wiring this into Show.vue can gate this call behind the same
        // confirm affordance NodeCard.vue already renders.
        await store.deleteNode(uuid);
    }

    function editFocused(): void {
        // Inline editing itself lives in NodeCard.vue (its own `editing`
        // ref) — this composable cannot reach past the store to trigger it
        // directly. F2 is wired through here for completeness of the
        // keyboard map's dispatch table; NodeCard.vue already starts
        // editing on double-click, and ui.md §3.4 leaves F2 as the
        // keyboard equivalent for whichever component owns focus-triggered
        // editing once wired.
        const path = focusedPath.value;
        if (!path) return;
        options.onOpenModal?.(pathUuid(path), path);
    }

    function handleKeydown(event: KeyboardEvent): void {
        const editable = isEditableTarget(event.target);

        // These fire regardless of focus location (ui.md §4).
        if (
            (event.ctrlKey || event.metaKey) &&
            event.key.toLowerCase() === 'z'
        ) {
            event.preventDefault();
            if (event.shiftKey) options.onRedo?.();
            else options.onUndo?.();
            return;
        }
        if (
            (event.ctrlKey || event.metaKey) &&
            event.key.toLowerCase() === 'k'
        ) {
            event.preventDefault();
            options.onCommandPalette?.();
            return;
        }
        if (
            (event.ctrlKey || event.metaKey) &&
            (event.key === '+' || event.key === '=')
        ) {
            event.preventDefault();
            options.onZoom?.('in');
            return;
        }
        if ((event.ctrlKey || event.metaKey) && event.key === '-') {
            event.preventDefault();
            options.onZoom?.('out');
            return;
        }
        if ((event.ctrlKey || event.metaKey) && event.key === '0') {
            event.preventDefault();
            options.onZoom?.('reset');
            return;
        }
        if (event.key === '/' && !editable) {
            event.preventDefault();
            options.onFocusSearch?.();
            return;
        }
        if (event.key === '?' && !editable) {
            event.preventDefault();
            options.onHelp?.();
            return;
        }

        // Everything below only makes sense with tree focus, not while
        // typing in a field (ui.md §4: "Tab is deliberately overloaded
        // away from browser focus traversal only while a tree node has
        // focus. Restore native Tab everywhere else").
        if (editable) return;

        // Nor while focus is inside a dialog (2.5.1). Enter on the node
        // modal's own "Add" button, or Tab moving between its controls, is
        // that dialog's business; this listener is on `document`, so without
        // this it would also add a sibling of whatever row was last focused
        // in the tree — replacing the modal the person was using.
        if (
            event.target instanceof Element &&
            event.target.closest('[role="dialog"]')
        ) {
            return;
        }

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                moveFocus(1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                moveFocus(-1);
                break;
            case 'ArrowLeft':
                event.preventDefault();
                collapseOrToParent();
                break;
            case 'ArrowRight':
                event.preventDefault();
                expandOrToFirstChild();
                break;
            case 'Enter':
                event.preventDefault();
                addSibling();
                break;
            case 'Tab':
                event.preventDefault();
                if (event.shiftKey) {
                    // Deliberately nothing, and not a gap. In an outliner
                    // Shift+Tab promotes a node one level, but a node's
                    // level here *is* its type: risks hang off the root,
                    // attacks off risks, defences off attacks (schema.md
                    // §3.4), and nothing may change type. Promoting an
                    // attack would have to turn it into a risk, which no
                    // endpoint offers and which would silently discard its
                    // likelihood.
                    //
                    // The operation that does exist is re-parenting *within*
                    // a level — moving an attack from one risk to another —
                    // and that needs a target the keyboard has no way to
                    // name. useDragDrop.ts, wired into the tree in W27, is
                    // the affordance for it.
                    break;
                }
                addChild();
                break;
            case 'F2':
                event.preventDefault();
                editFocused();
                break;
            case ' ':
                event.preventDefault();
                if (focusedPath.value) {
                    options.onOpenModal?.(
                        pathUuid(focusedPath.value),
                        focusedPath.value,
                    );
                }
                break;
            case 'Delete':
            case 'Backspace':
                event.preventDefault();
                void deleteFocused();
                break;
            case 'Escape':
                setFocus(null);
                break;
            default:
                break;
        }
    }

    onMounted(() => {
        document.addEventListener('keydown', handleKeydown);
    });
    onUnmounted(() => {
        document.removeEventListener('keydown', handleKeydown);
    });

    return { focusedPath, visiblePaths, setFocus, handleKeydown };
}
