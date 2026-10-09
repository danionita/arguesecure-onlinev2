/**
 * The band above every view's title row (ui.md §2, §9; W82's fix for D42).
 *
 * Through W81 this band was rendered outside `AuthenticatedLayout`'s
 * `.editor-print`-scoped `<main>`, so nothing in `print.css` reached it on
 * the editor: printing the chart or list printed the title row's actions —
 * Share, and now Settings/History/Export/Print too — right along with them.
 * The report never showed the bug because it independently wraps its own
 * copy of this band in a page-level `no-print` div, which is exactly the
 * kind of two-files-agreeing-by-inspection this file's own docblock already
 * warns about for its *other* padding.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { h } from 'vue';
import ChromeHeaderBand from '../../../resources/js/Components/Chrome/ChromeHeaderBand.vue';
import ChromeTitleRow from '../../../resources/js/Components/Chrome/ChromeTitleRow.vue';
import AssessmentActions from '../../../resources/js/Components/Toolbar/AssessmentActions.vue';

const route = (name: string, uuid?: string) =>
    uuid ? `/${name}/${uuid}` : `/${name}`;

describe('ChromeHeaderBand print behaviour (D42)', () => {
    it('carries the specific class print.css hides, on the band itself', () => {
        const wrapper = mount(ChromeHeaderBand, {
            slots: { default: '<h2>Title</h2>' },
        });

        const header = wrapper.get('header');
        expect(header.classes()).toContain('no-print');
    });

    it('print.css defines what that class does, so the class above means something', async () => {
        const fs = await import('node:fs');
        const path = await import('node:path');
        const css = fs.readFileSync(
            path.resolve(__dirname, '../../../resources/css/print.css'),
            'utf-8',
        );

        expect(css).toMatch(
            /\.no-print\s*\{\s*display:\s*none\s*!important;?\s*\}/,
        );
    });

    /**
     * W82 fix round: the bar is mounted now (`Pages/Assessment/Show.vue`),
     * so this has something real to print rather than an empty title row.
     * Mounts the actual composition — the band, the title row and
     * `AssessmentActions` together, exactly as `Show.vue` nests them inside
     * its `#header` slot — rather than asserting on any one of the three in
     * isolation, which is what would let a future refactor place the bar
     * somewhere that silently falls outside the band's `no-print` again.
     */
    it('the mounted action bar sits inside the band that hides it', () => {
        const wrapper = mount(ChromeHeaderBand, {
            global: { config: { globalProperties: { route } } },
            slots: {
                default: () =>
                    h(ChromeTitleRow, { title: 'Payment platform' }, () =>
                        h(AssessmentActions, {
                            assessment: { uuid: 'u1' },
                            abilities: { manage: true },
                        }),
                    ),
            },
        });

        const header = wrapper.get('header');
        const bar = header.find('[data-testid="assessment-actions"]');

        expect(bar.exists()).toBe(true);
        expect(bar.text()).toContain('Print');
        // The bar is a *descendant* of the band carrying `no-print`, not a
        // sibling that happens to carry its own copy of the class — that is
        // the property `display: none !important` on an ancestor actually
        // relies on.
        expect(header.classes()).toContain('no-print');
    });
});
