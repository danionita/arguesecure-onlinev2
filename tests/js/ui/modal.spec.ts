/**
 * `UI/Modal.vue`'s header slots and where padding lives (W71 items 24 and 3).
 *
 * The padding assertion is the awkward one and the reason it is written down:
 * "add padding" is a one-character change that can be made in either of two
 * places, and only one of them is right. `Modal`'s body must stay unpadded —
 * four consumers already pad their own content and `NodeModal` deliberately
 * runs its tab strip flush to the dialog's edges — so the fix belongs in
 * `ReparentDialog`. A future tidy-up that "simplifies" this by moving the
 * padding up into `Modal` turns these two tests red together, which is the
 * whole point of asserting both halves.
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { h } from 'vue';
import Modal from '../../../resources/js/Components/UI/Modal.vue';
import ReparentDialog from '../../../resources/js/Components/UI/ReparentDialog.vue';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the header has a slot to the left of the title (item 24)', () => {
    it('renders #title-prefix before the heading, inside the header', () => {
        mount(Modal, {
            props: { show: true, title: 'Risk: Data breach' },
            slots: { 'title-prefix': () => h('i', { 'data-testid': 'mark' }) },
            attachTo: document.body,
        });

        const mark = document.querySelector('[data-testid="mark"]')!;
        const heading = document.querySelector('h2')!;

        expect(mark).not.toBeNull();
        // Order, not mere presence: the point of the item was that the type
        // stopped being a chip in the right-hand actions group.
        expect(
            mark.compareDocumentPosition(heading) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(heading.parentElement!.contains(mark)).toBe(true);
    });

    it('keeps #actions in the right-hand group, away from the title', () => {
        mount(Modal, {
            props: { show: true, title: 'Risk: Data breach' },
            slots: { actions: () => h('button', { 'data-testid': 'act' }) },
            attachTo: document.body,
        });

        const act = document.querySelector('[data-testid="act"]')!;
        expect(document.querySelector('h2')!.parentElement!.contains(act)).toBe(
            false,
        );
    });
});

describe('padding lives on the consumer, not on the modal body (item 3)', () => {
    it('leaves the scrolling body unpadded', () => {
        mount(Modal, {
            props: { show: true, title: 'x' },
            slots: { default: () => h('p', 'content') },
            attachTo: document.body,
        });

        // The scrolling body, identified by the height cap that only it has —
        // the overlay behind the dialog is also `overflow-y-auto`.
        const body = Array.from(document.querySelectorAll('div')).find((div) =>
            div.className.includes('max-h-[75vh]'),
        )!;

        expect(body.className).toContain('overflow-y-auto');
        for (const padded of ['p-4', 'px-4', 'py-3', 'p-6']) {
            expect(body.className.split(/\s+/)).not.toContain(padded);
        }
    });

    it('pads ReparentDialog’s own content instead', () => {
        mount(ReparentDialog, {
            props: {
                show: true,
                childTitle: 'Phishing',
                toParentTitle: 'Fraud',
                canLink: true,
            },
            attachTo: document.body,
        });

        // The section holding the two choices, which is what sat flush against
        // the dialog's edges before.
        const content = Array.from(document.querySelectorAll('div')).find(
            (div) => div.className.includes('space-y-4'),
        )!;

        expect(content.className.split(/\s+/)).toContain('p-4');
    });
});
