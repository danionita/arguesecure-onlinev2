/**
 * The chart's arithmetic, tested without a DOM (W32).
 *
 * `computeTreeLayout` is pure precisely so this file can exist: overlapping
 * siblings, a parent drifting off-centre, or a subtree that silently grows
 * the canvas are all bugs whose only other reproduction is "look at the
 * screenshot and squint", which is how layout bugs survive for years.
 */
import { describe, expect, it } from 'vitest';
import {
    computeTreeLayout,
    TREE_LAYOUT_DEFAULTS,
    type LayoutOccurrence,
} from '../../../resources/js/composables/useTreeLayout';

const { cardWidth, cardHeight, gapX, gapY, tierWidthFactors } =
    TREE_LAYOUT_DEFAULTS;

/** The width a card on `tier` occupies (W69). Root widest, defence narrowest. */
function tierWidth(tier: number): number {
    return cardWidth * tierWidthFactors[tier];
}

function occurrence(
    pathKey: string,
    parentPathKey: string | null,
    tier: number,
    childPathKeys: string[] = [],
): LayoutOccurrence {
    return { pathKey, parentPathKey, tier, childPathKeys };
}

/** root -> two risks -> the first risk has two attacks. */
function fixture(): LayoutOccurrence[] {
    return [
        occurrence('root', null, 0, ['root/riskA', 'root/riskB']),
        occurrence('root/riskA', 'root', 1, [
            'root/riskA/atk1',
            'root/riskA/atk2',
        ]),
        occurrence('root/riskA/atk1', 'root/riskA', 2),
        occurrence('root/riskA/atk2', 'root/riskA', 2),
        occurrence('root/riskB', 'root', 1),
    ];
}

function centre(box: { x: number; width: number }): number {
    return box.x + box.width / 2;
}

describe('tiers', () => {
    it('puts each tier on its own row, so depth is the vertical axis', () => {
        const { boxes } = computeTreeLayout(fixture());

        expect(boxes.get('root')!.y).toBe(0);
        expect(boxes.get('root/riskA')!.y).toBe(cardHeight + gapY);
        expect(boxes.get('root/riskA/atk1')!.y).toBe(2 * (cardHeight + gapY));
    });

    it('reports a height covering exactly the tiers in use', () => {
        const { height } = computeTreeLayout(fixture());

        // Three tiers present (root, risk, attack), not the schema's four.
        expect(height).toBe(3 * cardHeight + 2 * gapY);
    });

    /**
     * ui.md §3.7 promises two title lines and three description lines visible
     * at 1080p. That is a promise about a sum, and W37 spent most of the
     * package's slack on it, so the sum is pinned here rather than left to be
     * rediscovered by whoever next adds a toolbar row.
     *
     * 937px viewport at 1080p, minus ~193px of page chrome (the arithmetic is
     * in useTreeLayout.ts's docblock) leaves ~744px for four tiers.
     */
    it('fits four tiers inside the vertical budget a 1080p screen leaves', () => {
        const fourTiers =
            4 * TREE_LAYOUT_DEFAULTS.cardHeight + 3 * TREE_LAYOUT_DEFAULTS.gapY;

        expect(fourTiers).toBeLessThanOrEqual(744);
    });

    it('gives a card room for two title lines and three description lines', () => {
        // p-2 padding 16 + 2 title lines at 15px + gap 4 + 3 description
        // lines at ~15.3px + gap 4 + an 18px indicator row.
        const contentHeight = 16 + 2 * 15 + 4 + 3 * 15.3 + 4 + 18;

        expect(TREE_LAYOUT_DEFAULTS.cardHeight).toBeGreaterThanOrEqual(
            contentHeight,
        );
    });
});

describe('siblings', () => {
    it('never overlaps them, and separates them by exactly one gap', () => {
        const { boxes } = computeTreeLayout(fixture());

        const atk1 = boxes.get('root/riskA/atk1')!;
        const atk2 = boxes.get('root/riskA/atk2')!;

        expect(atk2.x - (atk1.x + atk1.width)).toBe(gapX);
    });

    it('lays leaves out left to right in the order given', () => {
        const { boxes } = computeTreeLayout(fixture());

        expect(boxes.get('root/riskA/atk1')!.x).toBeLessThan(
            boxes.get('root/riskA/atk2')!.x,
        );
        expect(boxes.get('root/riskA')!.x).toBeLessThan(
            boxes.get('root/riskB')!.x,
        );
    });
});

describe('parents', () => {
    it('centres a parent over its children', () => {
        const { boxes } = computeTreeLayout(fixture());

        const riskA = boxes.get('root/riskA')!;
        const atk1 = boxes.get('root/riskA/atk1')!;
        const atk2 = boxes.get('root/riskA/atk2')!;

        expect(centre(riskA)).toBeCloseTo((centre(atk1) + centre(atk2)) / 2, 6);
    });

    it('centres the root over the whole assessment', () => {
        const { boxes, width } = computeTreeLayout(fixture());

        expect(centre(boxes.get('root')!)).toBeCloseTo(width / 2, 6);
    });

    it('leaves a single child directly under its parent', () => {
        const { boxes } = computeTreeLayout([
            occurrence('root', null, 0, ['root/riskA']),
            occurrence('root/riskA', 'root', 1),
        ]);

        expect(centre(boxes.get('root')!)).toBeCloseTo(
            centre(boxes.get('root/riskA')!),
            6,
        );
    });
});

describe('width', () => {
    it('is the sum of the visible leaves and the gaps between them', () => {
        // Three leaves, and they are no longer the same size: two attacks
        // (tier 2) plus riskB (tier 1), which is a leaf because it has no
        // visible children of its own.
        const { width } = computeTreeLayout(fixture());

        expect(width).toBe(2 * tierWidth(2) + tierWidth(1) + 2 * gapX);
    });

    it('is one root card wide for an assessment with nothing in it yet', () => {
        const { width, height } = computeTreeLayout([
            occurrence('root', null, 0),
        ]);

        expect(width).toBe(tierWidth(0));
        expect(height).toBe(cardHeight);
    });
});

describe('collapsing', () => {
    it('narrows the chart by exactly the subtree that went away', () => {
        const expanded = computeTreeLayout(fixture());

        const collapsed = computeTreeLayout(
            fixture().map((o) =>
                o.pathKey === 'root/riskA' ? { ...o, childPathKeys: [] } : o,
            ),
        );

        // riskA's two attacks and the gap between them are replaced by riskA's
        // own card, which is narrower than the span it was covering.
        const subtree = 2 * tierWidth(2) + gapX;
        expect(collapsed.width).toBe(expanded.width - subtree + tierWidth(1));
        expect(collapsed.boxes.has('root/riskA')).toBe(true);
    });
});

/**
 * W69 / PLAN-2.4.md D27. The tiers do not carry comparable amounts of text, so
 * they stopped being the same width. The algorithm is unchanged — a parent
 * still occupies its children's span or its own card, whichever is greater —
 * and only the constant became a lookup.
 */
describe('tier widths', () => {
    it('widens each tier above the defence, in order', () => {
        const { boxes } = computeTreeLayout([
            occurrence('root', null, 0, ['root/r']),
            occurrence('root/r', 'root', 1, ['root/r/a']),
            occurrence('root/r/a', 'root/r', 2, ['root/r/a/d']),
            occurrence('root/r/a/d', 'root/r/a', 3),
        ]);

        const widths = ['root', 'root/r', 'root/r/a', 'root/r/a/d'].map(
            (key) => boxes.get(key)!.width,
        );

        // The starting numbers from D27: root 2, risk 1.5, attack 1.25,
        // defence 1 — asserted as the values, not merely as "descending", so
        // that flattening them back to a single constant turns this red.
        expect(widths).toEqual([2, 1.5, 1.25, 1].map((f) => cardWidth * f));
        expect(widths[3]).toBe(cardWidth);
    });

    it('keeps a narrow child centred under a wide parent', () => {
        // Impossible before W69: with one width a parent could never be wider
        // than its only child, so nothing exercised the clamp. Now it is the
        // ordinary case, and left-aligning would slant the connector.
        const { boxes } = computeTreeLayout([
            occurrence('root', null, 0, ['root/r']),
            occurrence('root/r', 'root', 1),
        ]);

        expect(boxes.get('root')!.x).toBe(0);
        // Actually shifted right, not merely "centred" by both sitting at 0.
        expect(boxes.get('root/r')!.x).toBe((tierWidth(0) - tierWidth(1)) / 2);
        expect(centre(boxes.get('root/r')!)).toBeCloseTo(
            centre(boxes.get('root')!),
            6,
        );
    });

    it('draws connectors from the real card edges, not from a shared constant', () => {
        const { boxes, connectors } = computeTreeLayout([
            occurrence('root', null, 0, ['root/r']),
            occurrence('root/r', 'root', 1, ['root/r/a1', 'root/r/a2']),
            occurrence('root/r/a1', 'root/r', 2),
            occurrence('root/r/a2', 'root/r', 2),
        ]);

        for (const connector of connectors) {
            expect(connector.x1).toBeCloseTo(
                centre(boxes.get(connector.fromPathKey)!),
                6,
            );
            expect(connector.x2).toBeCloseTo(
                centre(boxes.get(connector.toPathKey)!),
                6,
            );
        }
    });

    /**
     * The promise being protected is D3's: at fit-to-width the chart scrolls
     * horizontally and never vertically. `fitToWidth` divides the viewport by
     * `layout.width`, so anything that inflates that number inflates the zoom
     * the button picks. Widening the upper tiers does that **only** where a
     * tier's own card is wider than its children's span — which is a real
     * effect on a shallow assessment and none at all on a wide one. Both,
     * because asserting only the second would hide the cost and asserting only
     * the first would overstate it.
     */
    describe('the horizontal-scroll promise', () => {
        it('costs width on a shallow assessment, where the root sets it', () => {
            const shallow = [
                occurrence('root', null, 0, ['root/r']),
                occurrence('root/r', 'root', 1),
            ];

            const uniform = computeTreeLayout(shallow, {
                tierWidthFactors: [1, 1, 1, 1],
            });

            expect(computeTreeLayout(shallow).width).toBe(tierWidth(0));
            expect(uniform.width).toBe(cardWidth);
        });

        it('costs none on a wide one, where the leaves set it', () => {
            const wide: LayoutOccurrence[] = [
                occurrence(
                    'root',
                    null,
                    0,
                    Array.from({ length: 6 }, (_, i) => `root/r${i}`),
                ),
                ...Array.from({ length: 6 }, (_, i) =>
                    occurrence(`root/r${i}`, 'root', 1, [`root/r${i}/a`]),
                ),
                ...Array.from({ length: 6 }, (_, i) =>
                    occurrence(`root/r${i}/a`, `root/r${i}`, 2),
                ),
            ];

            const withRootFactorOne = computeTreeLayout(wide, {
                tierWidthFactors: [1, 1.5, 1.25, 1],
            });

            // Six risks at tier 1 set the width; the root fits inside it, so
            // its factor changes nothing.
            expect(computeTreeLayout(wide).width).toBe(
                6 * tierWidth(1) + 5 * gapX,
            );
            expect(withRootFactorOne.width).toBe(computeTreeLayout(wide).width);
        });

        it('never touches the height, which is what would start scrolling vertically', () => {
            const fourTiers = [
                occurrence('root', null, 0, ['root/r']),
                occurrence('root/r', 'root', 1, ['root/r/a']),
                occurrence('root/r/a', 'root/r', 2, ['root/r/a/d']),
                occurrence('root/r/a/d', 'root/r/a', 3),
            ];

            expect(computeTreeLayout(fourTiers).height).toBe(
                computeTreeLayout(fourTiers, {
                    tierWidthFactors: [1, 1, 1, 1],
                }).height,
            );
        });
    });
});

describe('a node in more than one place', () => {
    it('gets one box per occurrence, because that is what the reader sees', () => {
        // The same attack under both risks — the DAG case. It is drawn twice
        // (PLAN-2.1.md D5), which is what keeps this a tiered tree rather than
        // a general graph-layout problem.
        const { boxes } = computeTreeLayout([
            occurrence('root', null, 0, ['root/riskA', 'root/riskB']),
            occurrence('root/riskA', 'root', 1, ['root/riskA/shared']),
            occurrence('root/riskA/shared', 'root/riskA', 2),
            occurrence('root/riskB', 'root', 1, ['root/riskB/shared']),
            occurrence('root/riskB/shared', 'root/riskB', 2),
        ]);

        expect(boxes.get('root/riskA/shared')!.x).not.toBe(
            boxes.get('root/riskB/shared')!.x,
        );
        expect(boxes.size).toBe(5);
    });
});

describe('connectors', () => {
    it('runs from the bottom centre of the parent to the top centre of the child', () => {
        const { boxes, connectors } = computeTreeLayout(fixture());

        const link = connectors.find(
            (c) =>
                c.fromPathKey === 'root/riskA' &&
                c.toPathKey === 'root/riskA/atk1',
        )!;
        const parent = boxes.get('root/riskA')!;
        const child = boxes.get('root/riskA/atk1')!;

        expect(link.x1).toBeCloseTo(centre(parent), 6);
        expect(link.y1).toBe(parent.y + parent.height);
        expect(link.x2).toBeCloseTo(centre(child), 6);
        expect(link.y2).toBe(child.y);
    });

    it('draws one per visible edge and none into a collapsed subtree', () => {
        expect(computeTreeLayout(fixture()).connectors).toHaveLength(4);

        const collapsed = computeTreeLayout(
            fixture().map((o) =>
                o.pathKey === 'root/riskA' ? { ...o, childPathKeys: [] } : o,
            ),
        );
        expect(collapsed.connectors).toHaveLength(2);
    });
});

describe('an incomplete list', () => {
    it('skips a child whose parent was filtered away rather than placing it at the origin', () => {
        // Focus mode hands over a subset; a caller should not have to prune
        // consistently for the layout to stay sane.
        const { boxes } = computeTreeLayout([
            occurrence('root', null, 0, ['root/riskA']),
            occurrence('root/riskA', 'root', 1, ['root/riskA/atk1']),
            occurrence('root/riskA/atk1', 'root/riskA', 2),
            occurrence('root/ghost/atk9', 'root/ghost', 2),
        ]);

        expect(boxes.has('root/ghost/atk9')).toBe(false);
        expect(boxes.size).toBe(3);
    });

    it('returns an empty layout when there is no root at all', () => {
        const layout = computeTreeLayout([]);

        expect(layout.boxes.size).toBe(0);
        expect(layout.width).toBe(0);
        expect(layout.height).toBe(0);
    });
});
