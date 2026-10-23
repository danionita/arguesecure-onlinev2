/**
 * W137 — the shell knows nothing about what it holds. Its one job beyond
 * hosting a slot is the count, and a count shown for zero (or missing for a
 * non-zero) is the regression a filter bar would not notice.
 */
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import FilterCategory from '../../../resources/js/Components/Toolbar/FilterCategory.vue';
import FilterPopover from '../../../resources/js/Components/Toolbar/FilterPopover.vue';

function mountPopover(props: Record<string, unknown> = {}) {
    return mount(FilterPopover, {
        props,
        slots: {
            default: `<button type="button">a control</button>`,
        },
        global: { stubs: { teleport: true } },
    });
}

describe('FilterPopover', () => {
    it('shows the count on the trigger only when something is on', () => {
        expect(mountPopover({ count: 0 }).get('button').text()).toBe('Filters');
        const on = mountPopover({ count: 3 }).get('button');
        expect(on.text()).toContain('3');
        expect(on.text()).toContain('3 active');
    });

    it('takes its label from the caller', () => {
        expect(
            mountPopover({ label: 'Sections' }).get('button').text(),
        ).toContain('Sections');
    });

    it('keeps its slot closed until opened, then shows it', async () => {
        const wrapper = mountPopover();
        expect(wrapper.text()).not.toContain('a control');
        await wrapper.get('button[aria-haspopup="true"]').trigger('click');
        expect(wrapper.text()).toContain('a control');
    });
});

describe('FilterCategory', () => {
    it('names its group by its own heading, and ids differ between categories', () => {
        // One app, two categories: `useId` is unique per app, so mounting
        // them separately would hand both the same id.
        const wrapper = mount({
            components: { FilterCategory },
            template: `<div><FilterCategory title="One">x</FilterCategory><FilterCategory title="Two">y</FilterCategory></div>`,
        });
        const groups = wrapper.findAll('[role="group"]');
        const headings = wrapper.findAll('h3');
        expect(headings.map((h) => h.text())).toEqual(['One', 'Two']);
        groups.forEach((group, i) =>
            expect(group.attributes('aria-labelledby')).toBe(
                headings[i].attributes('id'),
            ),
        );
        expect(headings[0].attributes('id')).not.toBe(
            headings[1].attributes('id'),
        );
    });
});

describe('the shell', () => {
    it('holds no filter or server logic of its own', () => {
        // W137: the editor's filters are client state and the report's
        // options are server inputs; a shell that imported either would have
        // picked a side.
        for (const file of ['FilterPopover.vue', 'FilterCategory.vue']) {
            const source = readFileSync(
                resolve(
                    __dirname,
                    '../../../resources/js/Components/Toolbar',
                    file,
                ),
                'utf8',
            );
            expect(source).not.toMatch(/useFilters|@inertiajs|assessmentStore/);
        }
    });
});
