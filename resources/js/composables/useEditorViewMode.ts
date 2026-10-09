/**
 * Which shape the editor is drawn in (W32, ui.md §3): the chart, or the
 * indented list.
 *
 * The list is the better reading surface and it is what reports are built
 * from. The chart is the better *thinking* surface, which is what the tool is
 * actually for — a facilitator with a room full of people needs a picture on
 * a projector, not an outline. So the chart is the default and the list is one
 * click away.
 *
 * Persisted per user per assessment, the same shape as useCanvas.ts's zoom,
 * useCollapseState.ts's collapse map and useBaseline.ts's baseline: the user
 * id comes from the caller rather than from `usePage()` here, so this stays
 * unit-testable outside a mounted page.
 */
import { ref } from 'vue';

export type EditorViewMode = 'chart' | 'list';

export const EDITOR_VIEW_MODE_DEFAULT: EditorViewMode = 'chart';

const VALID: readonly EditorViewMode[] = ['chart', 'list'];

export interface UseEditorViewModeOptions {
    assessmentUuid: string;
    userId?: string | number | null;
    storage?: Pick<Storage, 'getItem' | 'setItem'>;
}

function resolveStorage(
    injected: UseEditorViewModeOptions['storage'],
): Pick<Storage, 'getItem' | 'setItem'> | undefined {
    if (injected) return injected;
    return typeof localStorage !== 'undefined' ? localStorage : undefined;
}

function storageKey(assessmentUuid: string, userId: unknown): string {
    return `arguesecure:viewmode:${userId ?? 'anon'}:${assessmentUuid}`;
}

function isMode(value: unknown): value is EditorViewMode {
    return VALID.includes(value as EditorViewMode);
}

/**
 * Write the persisted mode without instantiating the composable (W35).
 *
 * The report page is not the editor: it has no store, no editor view and no
 * `EDITOR_VIEW_KEY`, and it is reachable by anonymous share-link guests. But
 * its view switcher offers Chart and List, and picking one there has to land
 * the user in *that* view rather than in whatever they last used — so it
 * needs to set the persisted mode on the way out.
 *
 * Exported from here rather than open-coded on the report page so the key
 * format lives in exactly one file. A second copy of
 * `arguesecure:viewmode:{user}:{uuid}` is a silent no-op waiting to happen
 * the first time either is edited.
 */
export function persistEditorViewMode(
    mode: EditorViewMode,
    options: UseEditorViewModeOptions,
): void {
    if (!isMode(mode)) return;
    try {
        resolveStorage(options.storage)?.setItem(
            storageKey(options.assessmentUuid, options.userId),
            mode,
        );
    } catch {
        // As in the composable: storage throws in some private-browsing
        // modes, and a failed preference must not block the navigation.
    }
}

export function useEditorViewMode(options: UseEditorViewModeOptions) {
    const storage = resolveStorage(options.storage);
    const key = storageKey(options.assessmentUuid, options.userId);

    let initial: EditorViewMode = EDITOR_VIEW_MODE_DEFAULT;
    try {
        const stored = storage?.getItem(key);
        if (isMode(stored)) initial = stored;
    } catch {
        // Storage throws in some private-browsing modes; a non-persistent
        // switch beats a screen that fails to mount.
    }

    const mode = ref<EditorViewMode>(initial);

    function set(next: EditorViewMode): void {
        if (!isMode(next)) return;
        mode.value = next;
        try {
            storage?.setItem(key, next);
        } catch {
            // As above.
        }
    }

    return { mode, set };
}

export type EditorViewModeState = ReturnType<typeof useEditorViewMode>;
