/**
 * The toolbar row's wrapping (2.5 integration review, Finding 1).
 *
 * `ChromeToolbarBand.vue` is shared by the editor's `Toolbar.vue` and the
 * report's `ReportOptionsRow.vue` (W81). The report puts far more into the
 * row — a search box, two radio groups and three toggles, on top of zoom and
 * the switcher — and until this fix the outer row had no `flex-wrap`, so a
 * narrow report window overflowed rather than wrapping. `Toolbar.spec.ts`
 * already asserts the editor's row stays a single non-wrapping row; this
 * file asserts the row *can* wrap, and only below the breakpoint the one-row
 * requirement is scoped to (`lg`, 1024px — the editor's one-row test runs at
 * 1280px).
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ChromeToolbarBand from '../../../resources/js/Components/Chrome/ChromeToolbarBand.vue';

describe('ChromeToolbarBand wrapping', () => {
    it('wraps the row below lg, so a narrow window does not overflow it', () => {
        const wrapper = mount(ChromeToolbarBand, {
            slots: { default: '<span>content</span>' },
        });

        const row = wrapper.get('[data-testid="toolbar-row"]');
        expect(row.classes()).toContain('max-lg:flex-wrap');
    });

    it('keeps the literal flex-wrap class off the row, so the editor stays a single row at lg and above', () => {
        const wrapper = mount(ChromeToolbarBand, {
            slots: { default: '<span>content</span>' },
        });

        const row = wrapper.get('[data-testid="toolbar-row"]');
        expect(row.classes()).toContain('flex');
        expect(row.classes()).not.toContain('flex-wrap');
    });
});
