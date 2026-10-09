/**
 * Module-level singleton controlling which node's modal is open — mirrors
 * the convention Components/UI/useToast.ts already sets for shared UI
 * state. "Opened by Space, the edit action, or clicking an indicator icon"
 * (ui.md §7): every one of those triggers lives on a node-card occurrence
 * this package does not own (Components/Tree/**), so open()/close() are
 * exported here for that package to wire the pencil IconButton and the
 * Space/Enter key handlers to — see the W13 report for the exact
 * integration points still needed in NodeCard.vue/TreeNode.vue.
 *
 * "It never becomes a route; deep-linking to a node uses a query parameter
 * so back/forward behave" (ui.md §7) is handled entirely inside this
 * module via the History API (replaceState, not a full Inertia
 * navigation), so it works the moment <NodeModal /> is mounted anywhere in
 * the tree, without help from a page component this package does not own.
 */
import { ref } from 'vue';
import type { LockHolder } from '../../composables/useLocks';

/**
 * Tab *keys*, not tab labels. W39 renamed almost every label — Values became
 * Impact, Likelihood or Cost depending on the node's type, and Links became
 * Strength on the only type that still has it — while deliberately leaving
 * these identifiers alone.
 *
 * They are in the URL. `?node=…&tab=links` is pasted into chat, mailed, and
 * bookmarked, and a key rename would break every one of those links to buy
 * nothing a reader ever sees. The label is product copy and changes freely;
 * the key is an address and does not. Do not "tidy" this into agreement.
 */
export type NodeModalTab =
    | 'details'
    | 'values'
    | 'links'
    | 'statistics'
    | 'references'
    | 'assignees'
    | 'comments'
    | 'history'
    // W72 item 10. New key, so nothing above it had to move: the root's
    // settings had no tab at all before, only a page at a different URL.
    | 'scales';

const openUuid = ref<string | null>(null);
const activeTab = ref<NodeModalTab>('details');

/**
 * A node that does not exist yet (the add-node fix, 2.5.1).
 *
 * Pressing "Add child" used to POST an empty node and then open this modal on
 * it. That persisted an untitled node the moment the button was pressed, told
 * every collaborator "someone added a node" before anyone had named it, and
 * — because `NodeCreated` echoed back to the author — replaced the record the
 * open Details tab was bound to about a second later with a payload that had
 * no `tags`, which is the "Details tab goes blank" report.
 *
 * Now the button opens the modal on a *draft*: the parent it will go under and
 * the type it will be, nothing more. Nothing is written until the person
 * presses Add, and closing the modal abandons the draft with nothing to clean
 * up. `parentPath` is kept because suggestions ("link, don't duplicate") are
 * scoped to one parent, and a node with several parents cannot say which one
 * it was being added under.
 */
export interface NodeDraft {
    parentType: 'root' | 'risk' | 'attack';
    parentUuid: string;
    /** The parent occurrence's path, `root/risk/…`. */
    parentPath: string;
    childType: 'risk' | 'attack' | 'defence';
}

const draft = ref<NodeDraft | null>(null);

/**
 * Who else has the open node open, or null (2.5.1).
 *
 * The modal takes a whole-node lock (`node` field in LockController) while it
 * is open. When someone else already holds it, every tab renders read-only
 * and the header says who — NodeModal.vue sets this, the tabs read it through
 * `canEdit`, and NodeController refuses the PATCH as a backstop.
 */
const lockedBy = ref<LockHolder | null>(null);

export function childTypeOf(
    parentType: NodeDraft['parentType'],
): NodeDraft['childType'] {
    return parentType === 'root'
        ? 'risk'
        : parentType === 'risk'
          ? 'attack'
          : 'defence';
}

function syncQueryParam(): void {
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    if (openUuid.value) {
        url.searchParams.set('node', openUuid.value);
        url.searchParams.set('tab', activeTab.value);
    } else {
        url.searchParams.delete('node');
        url.searchParams.delete('tab');
    }
    window.history.replaceState(window.history.state, '', url);
}

/** Opening an existing node always abandons a draft that was on screen. */
function open(uuid: string, tab: NodeModalTab = 'details'): void {
    draft.value = null;
    openUuid.value = uuid;
    activeTab.value = tab;
    syncQueryParam();
}

/**
 * Opens the modal on a node that does not exist yet. Nothing is sent to the
 * server here; see `NodeDraft`.
 */
function openDraft(
    parentType: NodeDraft['parentType'],
    parentUuid: string,
    parentPath: string,
): void {
    openUuid.value = null;
    activeTab.value = 'details';
    draft.value = {
        parentType,
        parentUuid,
        parentPath,
        childType: childTypeOf(parentType),
    };
    syncQueryParam();
}

function close(): void {
    openUuid.value = null;
    draft.value = null;
    syncQueryParam();
}

function setTab(tab: NodeModalTab): void {
    activeTab.value = tab;
    syncQueryParam();
}

/** Reads `?node=&tab=` on first mount, e.g. after following a deep link. */
function restoreFromQuery(): void {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);
    const uuid = params.get('node');
    if (!uuid) return;
    const tab = params.get('tab') as NodeModalTab | null;
    openUuid.value = uuid;
    activeTab.value = tab ?? 'details';
    draft.value = null;
}

export function useNodeModal() {
    return {
        openUuid,
        activeTab,
        draft,
        lockedBy,
        open,
        openDraft,
        close,
        setTab,
        restoreFromQuery,
    };
}
