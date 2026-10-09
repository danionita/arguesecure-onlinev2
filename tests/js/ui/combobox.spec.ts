/**
 * `UI/Combobox.vue` — the teleported listbox (W71 item 6, PLAN-2.4.md D29).
 *
 * The bug was that the popup was `absolute` inside `Modal`'s
 * `overflow-y-auto` body, so the two comboboxes that are the last element of
 * their section opened into clipped space. Teleporting it to `body` fixes
 * that and puts three things at risk, which is what this file is for:
 * `aria-controls`, `aria-activedescendant`, and outside-click-to-close — all
 * three were carried by DOM containment before and are explicit now.
 */
import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import Combobox from '../../../resources/js/Components/UI/Combobox.vue';
import type { ComboboxOption } from '../../../resources/js/Components/UI/types';

const OPTIONS: ComboboxOption[] = [
    { value: 'a', label: 'Alice' },
    { value: 'b', label: 'Bob' },
];

let wrapper: VueWrapper | null = null;

function render(options: ComboboxOption[] = OPTIONS) {
    wrapper = mount(Combobox, {
        props: { modelValue: null, options },
        attachTo: document.body,
    });

    return wrapper;
}

function input(): HTMLInputElement {
    return document.querySelector<HTMLInputElement>('[role="combobox"]')!;
}

/**
 * jsdom has no layout: every `getBoundingClientRect` is zeroes, so the flip
 * would never be exercised. Pin the anchor's rect and the viewport height
 * instead, which is the only part of the calculation that needs real numbers.
 */
function placeAnchor(top: number, height = 32): void {
    const anchor = wrapper!.get('input').element.parentElement!;
    vi.spyOn(anchor, 'getBoundingClientRect').mockReturnValue({
        top,
        bottom: top + height,
        left: 20,
        right: 220,
        width: 200,
        height,
        x: 20,
        y: top,
        toJSON: () => ({}),
    } as DOMRect);
}

async function openPopup(): Promise<void> {
    await wrapper!.get('input').trigger('focus');
    await nextTick();
    await nextTick();
}

beforeEach(() => {
    Object.defineProperty(window, 'innerHeight', {
        value: 800,
        configurable: true,
        writable: true,
    });
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('the listbox leaves the scrolling ancestor', () => {
    it('renders the popup as a child of body, not of the component root', async () => {
        const w = render();
        await openPopup();

        const listbox = document.querySelector('[role="listbox"]')!;
        expect(listbox).not.toBeNull();
        // The claim is specifically that it is *not* inside the component,
        // because being inside is what let an ancestor clip it.
        expect(w.element.contains(listbox)).toBe(false);
    });

    it('anchors the popup to the input with fixed positioning', async () => {
        render();
        placeAnchor(100);
        await openPopup();

        const popup = document.querySelector<HTMLElement>(
            '[data-combobox-placement]',
        )!;
        expect(popup.style.position).toBe('fixed');
        expect(popup.style.left).toBe('20px');
        expect(popup.style.width).toBe('200px');
        expect(popup.style.top).toBe('136px');
    });
});

describe('it flips upward when there is no room below', () => {
    it('opens downward with the input near the top of the viewport', async () => {
        render();
        placeAnchor(100);
        await openPopup();

        expect(
            document
                .querySelector('[data-combobox-placement]')
                ?.getAttribute('data-combobox-placement'),
        ).toBe('below');
    });

    it('opens upward with the input near the bottom, and anchors by its bottom edge', async () => {
        render();
        // 800px viewport, input bottom at 772: 24px below, 736 above.
        placeAnchor(740);
        await openPopup();

        const popup = document.querySelector<HTMLElement>(
            '[data-combobox-placement]',
        )!;
        expect(popup.getAttribute('data-combobox-placement')).toBe('above');
        expect(popup.style.bottom).toBe('64px');
        expect(popup.style.top).toBe('');
    });
});

describe('the ARIA relationships that containment used to carry', () => {
    it('names the listbox from the input with aria-controls', async () => {
        render();
        await openPopup();

        const controls = input().getAttribute('aria-controls');
        expect(controls).toBeTruthy();
        expect(document.getElementById(controls!)?.getAttribute('role')).toBe(
            'listbox',
        );
    });

    it('keeps aria-controls pointing somewhere real even with no matches', async () => {
        render([]);
        await openPopup();

        const controls = input().getAttribute('aria-controls');
        expect(document.getElementById(controls!)?.textContent).toContain(
            'No matches',
        );
    });

    it('drops aria-controls when the popup is not rendered', () => {
        render();

        expect(input().getAttribute('aria-controls')).toBeNull();
    });

    it('follows the arrow keys with aria-activedescendant, naming a real option', async () => {
        render();
        await openPopup();

        expect(input().getAttribute('aria-activedescendant')).toBeNull();

        await wrapper!.get('input').trigger('keydown', { key: 'ArrowDown' });
        const first = input().getAttribute('aria-activedescendant');
        expect(document.getElementById(first!)?.textContent).toContain('Alice');

        await wrapper!.get('input').trigger('keydown', { key: 'ArrowDown' });
        const second = input().getAttribute('aria-activedescendant');
        expect(document.getElementById(second!)?.textContent).toContain('Bob');
    });
});

describe('outside-click-to-close, now that "outside" is not "outside the root"', () => {
    it('closes on a mousedown elsewhere in the document', async () => {
        render();
        await openPopup();
        expect(document.querySelector('[role="listbox"]')).not.toBeNull();

        const elsewhere = document.createElement('button');
        document.body.append(elsewhere);
        elsewhere.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        await nextTick();

        expect(document.querySelector('[role="listbox"]')).toBeNull();
    });

    it('does not close on a mousedown inside the teleported popup', async () => {
        render();
        await openPopup();

        // A scrollbar drag inside the list is a mousedown outside the
        // component's root; before the teleport it was inside it.
        document
            .querySelector('[role="listbox"]')!
            .dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        await nextTick();

        expect(document.querySelector('[role="listbox"]')).not.toBeNull();
    });

    it('still selects an option clicked in the teleported popup', async () => {
        const w = render();
        await openPopup();

        document
            .querySelectorAll('[role="option"]')[1]
            .dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        await nextTick();

        expect(w.emitted('update:modelValue')).toEqual([['b']]);
    });
});
