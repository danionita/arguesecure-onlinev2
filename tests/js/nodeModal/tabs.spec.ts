/**
 * W39 item 9: the tab strip says what each type is quantified by, and the
 * Links tab is gone from everything except defences.
 *
 * These are whole-set assertions, not absence checks. "Links is not present"
 * would pass just as well if the whole strip failed to render — which is
 * exactly what happened on the first run of this file, because the modal
 * teleports to `body` and the wrapper was empty. The exact list, in order, is
 * the claim, and it is the only assertion shape that fails when the modal
 * renders nothing at all.
 */
import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import NodeModal from '../../../resources/js/Components/NodeModal/NodeModal.vue';
import AppearsUnderPopover from '../../../resources/js/Components/NodeModal/tabs/AppearsUnderPopover.vue';
import {
    useNodeModal,
    type NodeModalTab,
} from '../../../resources/js/Components/NodeModal/useNodeModal';
import {
    ASSESSMENT_STORE_KEY,
    type AssessmentStore,
} from '../../../resources/js/stores/assessmentStore';
import { makeComputedStore, SCALE_LEVELS, SCALES } from './fixture';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { scaleLevels: SCALE_LEVELS, scales: SCALES } }),
}));

// W71 made panels lazy, so the tabs that fetch on mount no longer mount at
// all unless a test opens them — these stubs are belt and braces now rather
// than the only thing keeping the file off the network. Kept because a test
// that clicks a tab should not become a network test by accident.
const stubs = {
    CommentThread: true,
    RevisionList: true,
    ReferencesTab: true,
};

let wrapper: VueWrapper | null = null;

function render(store: AssessmentStore): void {
    wrapper = mount(NodeModal, {
        global: { provide: { [ASSESSMENT_STORE_KEY as symbol]: store }, stubs },
    });
}

function open(uuid: string, tab?: NodeModalTab): AssessmentStore {
    useNodeModal().open(uuid, tab);
    const store = makeComputedStore();
    render(store);

    return store;
}

/** The modal teleports to `body`, so nothing here can be read off the wrapper. */
function tabs(): HTMLElement[] {
    return Array.from(document.querySelectorAll('[role="tab"]'));
}

/** Strips the trailing badge — the count and the `?` are asserted separately. */
function tabLabels(): string[] {
    return tabs().map((tab) =>
        (tab.textContent ?? '').replace(/\s*(\?|\d+)\s*$/, '').trim(),
    );
}

function tabNamed(label: string): HTMLElement | undefined {
    return tabs().find((tab) =>
        (tab.textContent ?? '').trim().startsWith(label),
    );
}

function selected(): string | undefined {
    return tabs()
        .find((tab) => tab.getAttribute('aria-selected') === 'true')
        ?.textContent?.trim();
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
});

describe('the tab strip, per node type', () => {
    it('gives the root no value tab — it is quantified by its children', () => {
        open('root');

        expect(tabLabels()).toEqual([
            'Details',
            'Statistics',
            'References',
            'Assignees',
            'Comments',
            'History',
        ]);
    });

    it('calls a risk’s value tab Impact', () => {
        open('risk1');

        expect(tabLabels()).toEqual([
            'Details',
            'Impact',
            'Statistics',
            'References',
            'Assignees',
            'Comments',
            'History',
        ]);
    });

    it('calls an attack’s value tab Likelihood', () => {
        open('attack1');

        expect(tabLabels()).toEqual([
            'Details',
            'Likelihood',
            'Statistics',
            'References',
            'Assignees',
            'Comments',
            'History',
        ]);
    });

    it('gives a defence both Cost and Strength, and no other type either', () => {
        open('mfa');

        expect(tabLabels()).toEqual([
            'Details',
            'Cost',
            'Strength',
            'Statistics',
            'References',
            'Assignees',
            'Comments',
            'History',
        ]);
    });
});

describe('deep links survive the rename', () => {
    it('opens the Strength tab for a defence link shared as ?tab=links', () => {
        // The label changed and the key did not, on purpose — see the
        // docblock on NodeModalTab. This is the test that makes "do not tidy
        // the key into agreement with the label" enforceable.
        open('mfa', 'links');

        expect(selected()).toContain('Strength');
    });

    it('falls back to Details when the link names a tab this type no longer has', () => {
        // A pre-W39 `?tab=links` on an attack. Without the fallback, Tabs
        // selects nothing and renders an empty modal body, which reads as a
        // broken node rather than as a stale link.
        open('attack1', 'links');

        expect(selected()).toBe('Details');
    });
});

describe('the Strength tab badge', () => {
    it('counts the attacks the defence applies to', () => {
        open('mfa');

        expect(tabNamed('Strength')?.textContent).toContain('1');
    });

    it('warns instead of counting when an edge has no strength entered', () => {
        useNodeModal().open('mfa');
        const store = makeComputedStore();
        const link = store.attackDefenceLinks.find(
            (l) => l.defence_uuid === 'mfa',
        )!;
        link.strength_mode = null;
        link.strength_fraction = null;
        link.strength_level_id = null;
        render(store);

        expect(tabNamed('Strength')?.textContent).toContain('?');
    });
});

describe('the risk_attack edge, now that the tab is gone', () => {
    /**
     * W72 mounted the popover from `DetailsTab` as a temporary home, because
     * both node cards were outside its ownership. Integration moved it onto
     * the parent counter on the chart card and the list card and took it off
     * the Details tab, which is what item 12 actually asks for — so rendering
     * `NodeModal` no longer puts this component on the page at all.
     *
     * These assertions are about the popover's own rules (the "not the last
     * one" detach guard, which risks it offers, what a reader may not do), not
     * about any mount point, so they mount the component directly. That also
     * stops them breaking the next time it moves. Where it is mounted is
     * asserted on the cards instead: `tests/js/chart/ChartView.spec.ts` and
     * `tests/js/tree/NodeCard.spec.ts`.
     */
    function render(store: AssessmentStore, uuid = 'attack1'): void {
        // `attachTo: document.body` because these assertions read the document
        // rather than the wrapper: the panel teleports, and so did the modal
        // this component used to be rendered inside. Mounting detached would
        // leave every `document.querySelector` below returning null and the
        // tests failing for a reason that has nothing to do with the rules
        // they check.
        wrapper = mount(AppearsUnderPopover, {
            attachTo: document.body,
            props: { store, uuid },
            global: { stubs },
        });
    }

    /**
     * The panel is a popover, so nothing below it exists in the DOM until the
     * counter is pressed. Opening it is spelled out in each test rather than
     * hidden in a helper — "the panel was never rendered" and "the panel says
     * no" are the two outcomes this file exists to keep apart.
     */
    async function openAppearsUnder(): Promise<void> {
        const trigger = document.querySelector<HTMLElement>(
            '[data-testid="appears-under-trigger"]',
        );
        expect(trigger).not.toBeNull();
        trigger!.click();
        await nextTick();
    }

    it('offers detach on the popover when the attack appears under more than one risk', async () => {
        useNodeModal().open('attack1');
        const store = makeComputedStore();
        store.nodes.risk2 = {
            ...store.nodes.risk1,
            uuid: 'risk2',
            title: 'Fraud',
        };
        store.riskAttackLinks.push({
            risk_uuid: 'risk2',
            attack_uuid: 'attack1',
            position: 0,
        });
        render(store);
        await openAppearsUnder();

        const section = document.querySelector('[data-testid="appears-under"]');
        expect(section?.textContent).toContain('Fraud');
        expect(
            document.querySelector('[aria-label="Remove from Fraud"]'),
        ).not.toBeNull();
    });

    it('offers the risks it is not yet under, and only those', async () => {
        useNodeModal().open('attack1');
        const store = makeComputedStore();
        store.nodes.risk2 = {
            ...store.nodes.risk1,
            uuid: 'risk2',
            title: 'Fraud',
        };
        render(store);
        await openAppearsUnder();

        const input = document.querySelector<HTMLInputElement>(
            '[data-testid="appears-under"] [role="combobox"]',
        )!;
        input.dispatchEvent(new FocusEvent('focus'));
        await nextTick();

        // Read off the listbox the input names, not off the section: W71
        // teleported the popup to `body` so a scrolling modal stops clipping
        // it, which means it is no longer a descendant of the section that
        // owns the combobox. `aria-controls` is how the two are related now.
        const listbox = document.getElementById(
            input.getAttribute('aria-controls')!,
        );
        const options = Array.from(
            listbox!.querySelectorAll('[role="option"]'),
        ).map((option) => option.textContent?.trim());

        // "Data breach" is missing because it is already a parent; linking to
        // it again would write a duplicate edge against a unique index.
        expect(options).toContain('Fraud');
        expect(options).not.toContain('Data breach');
    });

    it('withholds detach on the last risk, because that would hide the attack rather than delete it', async () => {
        // The default fixture puts attack1 under exactly one risk, which is
        // the case under test — no extra link is added here on purpose.
        render(makeComputedStore());
        await openAppearsUnder();

        const section = document.querySelector('[data-testid="appears-under"]');
        // All three halves matter. The panel is open, it does list the risk,
        // and the button is absent — so this is a refusal rather than a panel
        // that failed to render, which is how this assertion could otherwise
        // pass for the wrong reason.
        expect(section?.textContent).toContain('Data breach');
        expect(
            document.querySelector('[aria-label="Remove from Data breach"]'),
        ).toBeNull();
        expect(section?.textContent).toContain(
            'the only risk it appears under',
        );
    });

    it('counts the parents on the trigger, and keeps the panel shut until asked', () => {
        useNodeModal().open('attack1');
        const store = makeComputedStore();
        store.nodes.risk2 = {
            ...store.nodes.risk1,
            uuid: 'risk2',
            title: 'Fraud',
        };
        store.riskAttackLinks.push({
            risk_uuid: 'risk2',
            attack_uuid: 'attack1',
            position: 0,
        });
        render(store);

        const trigger = document.querySelector(
            '[data-testid="appears-under-trigger"]',
        );
        expect(trigger?.getAttribute('aria-label')).toBe(
            'Appears under 2 risks',
        );
        expect(
            document.querySelector('[data-testid="appears-under"]'),
        ).toBeNull();
    });

    it('offers neither detach nor link to a reader who cannot edit', async () => {
        // The other half of the same guard. `is_public` grants read only
        // (security.md defect #4), and a structural mutation offered to a
        // reader would 403 at the controller — which is a worse way to learn
        // it than not being offered.
        useNodeModal().open('attack1');
        const store = makeComputedStore();
        store.abilities.update = false;
        store.nodes.risk2 = {
            ...store.nodes.risk1,
            uuid: 'risk2',
            title: 'Fraud',
        };
        store.riskAttackLinks.push({
            risk_uuid: 'risk2',
            attack_uuid: 'attack1',
            position: 0,
        });
        render(store);
        await openAppearsUnder();

        const section = document.querySelector('[data-testid="appears-under"]');
        // Still readable — "where does this appear" is not an edit.
        expect(section?.textContent).toContain('Fraud');
        expect(
            document.querySelector('[aria-label="Remove from Fraud"]'),
        ).toBeNull();
        expect(section?.querySelector('[role="combobox"]')).toBeNull();
    });

    it('gives no parent counter to anything that is not an attack', () => {
        // Only `risk_attack` has the several-parents-one-child shape this
        // panel edits; a defence's edges are the Strength tab's subject.
        open('risk1');

        expect(
            document.querySelector('[data-testid="appears-under-trigger"]'),
        ).toBeNull();
    });
});

describe('the Cost tab is about cost (W72 item 9)', () => {
    it('carries capex, opex and the annualised total, and nothing about strength', () => {
        open('mfa', 'values');

        // Exactly one panel exists: W71 mounts a panel on first activation
        // and this modal was opened straight onto Cost.
        const text =
            document.querySelector('[role="tabpanel"]')?.textContent ?? '';

        expect(text).toContain('One-off cost (capex)');
        expect(text).toContain('Annual cost (opex)');
        expect(text).toContain('Annualized total');

        // The read-only strength summary and its jump to the Strength tab are
        // gone, not hidden: two places showing one number with only one of
        // them editable is the legacy indirection this rewrite removes. The
        // status control and the transfer flag are gone too — they are on
        // Details now, and neither was ever a cost.
        expect(text).not.toContain('Strength');
        expect(text).not.toContain('Status');
        expect(text).not.toContain('transfer');
    });

    it('puts status and the transfer flag on Details, with the rest of what a node is', () => {
        open('mfa');

        const panel = document.querySelector('[role="tabpanel"]');
        expect(
            panel?.querySelector('[data-testid="defence-status"]'),
        ).not.toBeNull();
        expect(panel?.textContent).toContain('This is a transfer');
    });

    it('gives a risk no status control on Details, because status is a defence field', () => {
        open('risk1');

        expect(
            document.querySelector('[data-testid="defence-status"]'),
        ).toBeNull();
    });
});
