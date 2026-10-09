/**
 * W71 item 1: "the Details tab sometimes does not load".
 *
 * Two candidates were named, both real, and each gets its own reproduction
 * here because the fix for one does nothing for the other:
 *
 *  1. **Every panel mounted at once.** `Tabs` toggled with `v-show`, so
 *     opening any node mounted all eight panels; four of them fetch on
 *     mount. A throw anywhere in that set unmounts the whole `Tabs`
 *     subtree, and Details — the panel the user is looking at — is the
 *     visible casualty. `stubbedTabThrows` is that reproduction.
 *  2. **No node, no panel and no explanation.** `Tabs` rendered behind
 *     `v-if="node"` with no else, so a modal opened on a uuid the store
 *     does not hold — a `?node=` deep link that outlived the node, or a
 *     realtime delete arriving while the modal is open — rendered an empty
 *     body. Empty is exactly what "does not load" looks like.
 *
 * Both are fixed: panels are lazy (`v-if` until first activation, `v-show`
 * afterwards so an opened tab keeps its state), and the shell renders a
 * loading or a gone state rather than nothing.
 */
import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';
import NodeModal from '../../../resources/js/Components/NodeModal/NodeModal.vue';
import { useNodeModal } from '../../../resources/js/Components/NodeModal/useNodeModal';
import {
    ASSESSMENT_STORE_KEY,
    createAssessmentStore,
    type AssessmentStore,
} from '../../../resources/js/stores/assessmentStore';
import { makeComputedStore, makeInit, SCALE_LEVELS, SCALES } from './fixture';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { scaleLevels: SCALE_LEVELS, scales: SCALES } }),
}));

/**
 * A panel that dies on render, standing in for the four tabs that fetch on
 * mount. Which one throws is not the point — the point is that one of them
 * throwing must not take Details with it.
 */
const Exploding = defineComponent({
    name: 'Exploding',
    setup() {
        throw new Error('this panel fetches on mount and the fetch threw');
    },
    render: () => null,
});

let wrapper: VueWrapper | null = null;

function render(store: AssessmentStore, stubs: Record<string, unknown>): void {
    wrapper = mount(NodeModal, {
        global: { provide: { [ASSESSMENT_STORE_KEY as symbol]: store }, stubs },
    });
}

/** The modal teleports to `body`, so nothing here can be read off the wrapper. */
function body(): string {
    return document.body.textContent ?? '';
}

/** The modal body's stand-in for a node it cannot show — loading or gone. */
function missing(): HTMLElement | null {
    return document.querySelector('[data-testid="node-missing"]');
}

function panels(): HTMLElement[] {
    return Array.from(document.querySelectorAll('[role="tabpanel"]'));
}

function tabNamed(label: string): HTMLElement | undefined {
    return Array.from(
        document.querySelectorAll<HTMLElement>('[role="tab"]'),
    ).find((tab) => (tab.textContent ?? '').trim().startsWith(label));
}

beforeEach(() => {
    // W88: the Details tab now builds the assistant's ask-and-accept flow in
    // setup, and useGenerationProgress resolves its two URLs there — so
    // mounting the modal at all needs Ziggy's global. Stubbed rather than
    // routed: nothing in this file presses the button.
    (globalThis as unknown as { route: (...args: unknown[]) => string }).route =
        vi.fn((name: string) => '/' + name);
    useNodeModal().close();
    window.history.replaceState(null, '', '/assessments/abc');
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('candidate 1 — a throwing panel must not take Details with it', () => {
    it('renders Details even though History would throw on mount', () => {
        // Vue logs the error it recovers from; the test is about the render,
        // not the log, and an unstubbed console makes the run unreadable.
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        vi.spyOn(console, 'error').mockImplementation(() => {});

        useNodeModal().open('risk1');
        render(makeComputedStore(), {
            CommentThread: true,
            ReferencesTab: true,
            HistoryTab: Exploding,
        });

        // "Data breach" is the risk's title, rendered by the Details panel.
        expect(body()).toContain('Data breach');
    });

    it('mounts only the active panel, so three of the four fetching tabs never run', () => {
        useNodeModal().open('risk1');
        render(makeComputedStore(), {
            CommentThread: true,
            RevisionList: true,
            ReferencesTab: true,
        });

        // Seven tabs on a risk, exactly one panel in the DOM.
        expect(panels()).toHaveLength(1);
    });

    it('keeps a panel mounted once visited, so a tab does not lose its state on return', async () => {
        useNodeModal().open('risk1');
        render(makeComputedStore(), {
            CommentThread: true,
            RevisionList: true,
            ReferencesTab: true,
        });

        tabNamed('Statistics')?.click();
        await nextTick();
        tabNamed('Details')?.click();
        await nextTick();

        // Details and Statistics, both still in the DOM; only one visible.
        expect(panels()).toHaveLength(2);
        expect(
            panels().filter((panel) => panel.style.display !== 'none'),
        ).toHaveLength(1);
    });
});

describe('the type is an icon beside the title, not a chip by Delete (item 24)', () => {
    it('names the type on an icon left of the heading', () => {
        useNodeModal().open('mfa');
        render(makeComputedStore(), {});

        const icon = document.querySelector(
            '[role="img"][aria-label="Defence"]',
        )!;
        const heading = document.querySelector('h2')!;

        expect(icon).not.toBeNull();
        expect(heading.parentElement!.contains(icon)).toBe(true);
        expect(
            icon.compareDocumentPosition(heading) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('tints the icon with the type’s own colour token', () => {
        useNodeModal().open('mfa');
        render(makeComputedStore(), {});

        // The specified token, not "it has a class": `text-node-defence-text`
        // is the same token the tree card uses, which is the point — the modal
        // and the card have to be recognisably the same node.
        expect(
            document
                .querySelector('[role="img"][aria-label="Defence"]')
                ?.classList.contains('text-node-defence-text'),
        ).toBe(true);
    });

    it('leaves Delete in the actions group W37 put it in', () => {
        useNodeModal().open('mfa');
        render(makeComputedStore(), {});

        const del = document.querySelector('[aria-label="Delete"]')!;
        expect(del).not.toBeNull();
        expect(document.querySelector('h2')!.parentElement!.contains(del)).toBe(
            false,
        );
    });
});

describe('candidate 2 — a modal opened on a node the store does not hold', () => {
    it('says the node is gone rather than rendering an empty body', () => {
        useNodeModal().open('deleted-while-you-were-looking');
        render(makeComputedStore(), {});

        // Read off the body region, not off the whole document: the dialog's
        // heading also changes in these two states, and asserting on
        // `document.body.textContent` would pass on the heading alone — which
        // is not the thing that was empty.
        expect(missing()?.textContent).toContain(
            'This node is no longer in the assessment',
        );
    });

    it('says it is still loading when the store has not been hydrated yet', () => {
        useNodeModal().open('risk1');
        render(
            createAssessmentStore({
                ...makeInit(),
                nodes: [],
            }) as unknown as AssessmentStore,
            {},
        );

        expect(missing()?.textContent).toContain('Loading this node');
    });

    it('shows neither state for a node the store does hold', () => {
        useNodeModal().open('risk1');
        render(makeComputedStore(), {});

        expect(missing()).toBeNull();
    });
});
