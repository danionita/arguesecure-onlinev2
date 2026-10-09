/**
 * The risk tolerance where a reader meets it (W44, item 12).
 *
 * Two surfaces, one rule: it is a comparison, never a verdict. The badge and
 * the chart line both state where a threshold someone chose sits relative to
 * a figure the engine computed, and neither recolours, re-ranks or re-labels
 * anything on the strength of it — `calculation-engine.md` forbids
 * band-mapping a computed figure into low/medium/high, and doing it with the
 * user's own number as cover would be the same rule broken.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import BaselineBarChart from '../../../resources/js/Components/Report/BaselineBarChart.vue';

function block(baseline: 'inherent' | 'current' | 'target', total: number) {
    return { baseline, total, isPartial: false };
}

const BLOCKS = [
    block('inherent', 200000),
    block('current', 90000),
    block('target', 67500),
];

function chart(riskTolerance: number | null) {
    return mount(BaselineBarChart, {
        props: { blocks: BLOCKS, currency: 'EUR', riskTolerance },
    });
}

describe('the threshold line on the baseline chart', () => {
    it('is absent when no tolerance is set', () => {
        expect(
            chart(null).find('[data-testid="tolerance-marker"]').exists(),
        ).toBe(false);
    });

    it('is drawn at zero when the tolerance is zero, which is a real setting', () => {
        const marker = chart(0).find('[data-testid="tolerance-marker"]');

        // Zero says "every euro of residual loss is worth arguing about".
        // Treating it as "unset" would silently discard that statement.
        expect(marker.exists()).toBe(true);
        expect(marker.find('line').attributes('x1')).toBe('0');
    });

    it('widens the scale so a tolerance above every bar is still on the chart', () => {
        // 400 is the viewBox width. With the tolerance above the largest bar,
        // a scale computed from the bars alone would put the line off the
        // right edge — and an invisible line reads as "no tolerance set",
        // the opposite of "comfortably under it".
        const marker = chart(500000).find('[data-testid="tolerance-marker"]');

        expect(marker.exists()).toBe(true);
        expect(
            Number(marker.find('line').attributes('x1')),
        ).toBeLessThanOrEqual(400);
    });

    it('places the line proportionally between the bars', () => {
        // Half of the 200,000 maximum, so halfway across the 400-unit width.
        const marker = chart(100000).find('[data-testid="tolerance-marker"]');

        expect(Number(marker.find('line').attributes('x1'))).toBeCloseTo(
            200,
            0,
        );
    });

    it('does not recolour the bars it crosses', () => {
        const withTolerance = chart(50000);
        const without = chart(null);

        const fills = (w: typeof withTolerance) =>
            w.findAll('rect').map((r) => r.attributes('fill'));

        // Current and target are both above 50,000 here. Turning them red
        // would be band-mapping a computed figure — the thing this tool
        // deliberately does not do.
        expect(fills(withTolerance)).toEqual(fills(without));
    });
});
