/**
 * Positions for the chart view (W32, ui.md §3.7): the assessment as an org
 * chart — Target of Assessment across the top, then risks, attacks and
 * defences, each tier a row, siblings side by side, connectors drawn
 * downward.
 *
 * The whole layout is **one pure function**. No DOM, no measurement, no
 * store, no reactivity: occurrences in, coordinates out. That is not
 * fastidiousness — a layout bug that can only be reproduced by taking a
 * screenshot is a layout bug nobody fixes, and this is the one part of the
 * chart with real arithmetic in it. Everything reactive (which occurrences
 * are visible, what is collapsed, what is filtered) is decided by the caller
 * and arrives here as a plain list.
 *
 * ## The algorithm
 *
 * Bottom-up width accumulation, the simple half of Reingold–Tilford. A leaf
 * occupies one card width. A parent occupies the sum of its children plus the
 * gaps between them, or its own width if that is greater, and centres itself
 * over that span. Widths are therefore exact and siblings can never overlap,
 * which is the property the tests pin.
 *
 * **Card width is per tier** (W69, PLAN-2.4.md D27): a base unit times a
 * factor, root widest and defence narrowest, because the tiers do not carry
 * comparable amounts of text. The root holds the assessment's title and its
 * whole-graph bar; a defence holds a control's name. Giving all four the same
 * 176px spent the chart's scarcest resource — horizontal space — evenly on
 * unequal needs.
 *
 * That is the *only* change to the shape of the algorithm: the constant
 * became a lookup. Connector geometry reads box coordinates and is unaffected.
 * The measure-then-place split below exists because the parent is now
 * routinely *wider* than its children's span (a root over a single risk), a
 * case a uniform width made impossible — and left-aligning a narrow child
 * under a wide parent would put the connector on a slant for no reason.
 *
 * A **collapsed** occurrence arrives with no children and is a leaf like any
 * other, so collapsing a risk narrows the chart by exactly the width of its
 * subtree. That is the primary tool for making a large assessment fit, and it
 * is why the depth limit matters: with four tiers the chart never needs
 * vertical scrolling, so the only dimension the reader has to manage is
 * horizontal.
 *
 * A shared attack or defence appears **once per occurrence**, not once with
 * several incoming edges (PLAN-2.1.md D5). That keeps this a tiered tree
 * rather than a general DAG-layout problem, and it matches both the list view
 * and the existing duplicate-highlighting affordance, which exists precisely
 * to make those repeats legible.
 */

export interface LayoutOccurrence {
    pathKey: string;
    /** pathKey of the parent occurrence; null for the root. */
    parentPathKey: string | null;
    /** 0 = root, 1 = risk, 2 = attack, 3 = defence. */
    tier: number;
    /**
     * Visible children, in render order. Empty for a leaf *and* for a
     * collapsed occurrence — see the docblock: the layout deliberately cannot
     * tell those apart, because it should not lay them out differently.
     */
    childPathKeys: string[];
}

export interface LayoutBox {
    pathKey: string;
    tier: number;
    /** Left edge, in layout units (CSS pixels before any zoom transform). */
    x: number;
    /** Top edge. */
    y: number;
    width: number;
    height: number;
}

export interface LayoutConnector {
    fromPathKey: string;
    toPathKey: string;
    /** Bottom-centre of the parent card. */
    x1: number;
    y1: number;
    /** Top-centre of the child card. */
    x2: number;
    y2: number;
}

export interface TreeLayout {
    boxes: Map<string, LayoutBox>;
    connectors: LayoutConnector[];
    width: number;
    height: number;
}

export interface TreeLayoutOptions {
    /** The base unit a tier's factor multiplies. See `tierWidthFactors`. */
    cardWidth?: number;
    /** Per-tier multipliers, indexed by tier. See `TREE_LAYOUT_DEFAULTS`. */
    tierWidthFactors?: readonly number[];
    cardHeight?: number;
    /** Horizontal gap between sibling subtrees. */
    gapX?: number;
    /** Vertical gap between tiers. */
    gapY?: number;
}

/**
 * ## The card height is a budget, and here is the arithmetic (W37)
 *
 * ui.md §3.7 requires **two title lines and three description lines visible at
 * 1080p** without scrolling. That is a claim about the whole page, not about
 * this file, so the sum is written down here — the next person to add a row to
 * the toolbar needs to know what they are spending.
 *
 * Vertical budget on a 1920×1080 screen, viewport ≈ 937px after browser chrome:
 *
 * | Consumer | px |
 * |---|---|
 * | Layout header (assessment title, `py-6`) | 76 |
 * | Page padding (`py-3`) | 12 |
 * | Toolbar, one row (`py-1.5` + `h-7` + border) | 41 |
 * | Toolbar margin (`mb-3`) | 12 |
 * | Chart's own "Fit to width" row | 36 |
 * | Bottom padding (`pb-4`) | 16 |
 * | **Available for four tiers** | **≈ 744** |
 *
 * Four tiers cost `4 × cardHeight + 3 × gapY`. At 124 and 44 that is 628,
 * leaving ~116px of slack — enough for a browser with heavier chrome, or for
 * the focus breadcrumb when focus mode is on.
 *
 * The card's own 124px is spent as: `p-2` padding 16, two title lines at
 * `text-xs`/`leading-tight` 30, gap 4, three description lines at 11px
 * /`leading-snug` 46, gap 4, indicator row 18 — 118, with the remainder as
 * breathing room. **If a future change makes the toolbar taller, the tiers no
 * longer fit and the fix is the toolbar, not the description clamp.** That
 * ordering is the whole reason W34 came before this package.
 *
 * The **tier gutter is gone** as of W37 (it was 84px of permanent horizontal
 * space naming four things that never change), so `x` now starts at the
 * viewport's left edge.
 *
 * ## What the tier factors cost horizontally (W69)
 *
 * `cardWidth` is the base unit; `tierWidthFactors[tier]` multiplies it —
 * defence 1, attack 1.25, risk 1.5, root 2. None of that touches the vertical
 * budget above, and it touches `layout.width` (which is what `fitToWidth`
 * divides the viewport by) **only where a tier's own width exceeds its
 * children's span**. On a wide assessment the leaves set the width and the
 * upper tiers fit inside it for free; on a shallow one — a root with a single
 * risk, or an empty assessment — the widest single card sets it, and the chart
 * is that much wider. Both cases are pinned in the spec file, because the
 * promise being protected is D3's: horizontal scrolling only, never vertical,
 * at fit-to-width.
 */
export const TREE_LAYOUT_DEFAULTS = {
    cardWidth: 176,
    tierWidthFactors: [2, 1.5, 1.25, 1],
    cardHeight: 124,
    gapX: 16,
    gapY: 44,
} as const;

/**
 * The width of a card on `tier`, in layout units (W69, PLAN-2.4.md D27).
 *
 * A tier out of range clamps to the last factor rather than falling back to
 * the base unit: the schema fixes the depth at four, so an out-of-range tier
 * is a caller bug, and a card silently drawn at base width would look like a
 * layout glitch instead of reading as one.
 */
function widthForTier(
    tier: number,
    cardWidth: number,
    factors: readonly number[],
): number {
    const factor = factors[Math.min(Math.max(tier, 0), factors.length - 1)];

    return cardWidth * (factor ?? 1);
}

/**
 * @param occurrences every visible occurrence, in any order; the root is the
 *        one with `parentPathKey === null`. An occurrence whose parent is not
 *        in the list is unreachable and is skipped, so a caller filtering by
 *        focus mode does not have to prune consistently.
 */
export function computeTreeLayout(
    occurrences: LayoutOccurrence[],
    options: TreeLayoutOptions = {},
): TreeLayout {
    const cardWidth = options.cardWidth ?? TREE_LAYOUT_DEFAULTS.cardWidth;
    const factors =
        options.tierWidthFactors ?? TREE_LAYOUT_DEFAULTS.tierWidthFactors;
    const cardHeight = options.cardHeight ?? TREE_LAYOUT_DEFAULTS.cardHeight;
    const gapX = options.gapX ?? TREE_LAYOUT_DEFAULTS.gapX;
    const gapY = options.gapY ?? TREE_LAYOUT_DEFAULTS.gapY;

    const boxes = new Map<string, LayoutBox>();
    const connectors: LayoutConnector[] = [];

    const byPathKey = new Map(occurrences.map((o) => [o.pathKey, o]));
    const root = occurrences.find((o) => o.parentPathKey === null);

    if (!root) return { boxes, connectors, width: 0, height: 0 };

    /** Visible children, skipping any whose entry the caller filtered away. */
    function childrenOf(occurrence: LayoutOccurrence): LayoutOccurrence[] {
        return occurrence.childPathKeys
            .map((key) => byPathKey.get(key))
            .filter((child): child is LayoutOccurrence => child !== undefined);
    }

    function ownWidth(occurrence: LayoutOccurrence): number {
        return widthForTier(occurrence.tier, cardWidth, factors);
    }

    // Memoised because `measure` is called once per node by `place` and again
    // by its parent's measure; without this the two recursions multiply. Four
    // tiers makes that harmless in practice, but a layout that is quadratic
    // only by accident of the depth limit is a trap for whoever raises it.
    const measured = new Map<string, number>();

    /**
     * The horizontal slot `occurrence` needs: its children's span, or its own
     * card, whichever is greater. No side effects — this is the bottom-up
     * pass, and `place` below is the top-down one.
     */
    function measure(occurrence: LayoutOccurrence): number {
        const cached = measured.get(occurrence.pathKey);
        if (cached !== undefined) return cached;

        const children = childrenOf(occurrence);
        const span = children.length
            ? children.reduce((sum, child) => sum + measure(child), 0) +
              gapX * (children.length - 1)
            : 0;

        const width = Math.max(span, ownWidth(occurrence));
        measured.set(occurrence.pathKey, width);

        return width;
    }

    /**
     * Places `occurrence` and its whole subtree inside the slot starting at
     * `left`, and returns the width that slot consumed. Recursive rather than
     * iterative because the depth is bounded at four by the schema — this
     * cannot run deep enough to matter.
     */
    function place(occurrence: LayoutOccurrence, left: number): number {
        const slot = measure(occurrence);
        const width = ownWidth(occurrence);
        const y = occurrence.tier * (cardHeight + gapY);
        // Centred in its own slot. With a uniform card width this only ever
        // moved a parent over its children; with per-tier widths it also
        // centres a wide parent over a narrower subtree.
        const x = left + (slot - width) / 2;

        boxes.set(occurrence.pathKey, {
            pathKey: occurrence.pathKey,
            tier: occurrence.tier,
            x,
            y,
            width,
            height: cardHeight,
        });

        const children = childrenOf(occurrence);
        if (children.length === 0) return slot;

        const span =
            children.reduce((sum, child) => sum + measure(child), 0) +
            gapX * (children.length - 1);

        let cursor = left + (slot - span) / 2;
        for (const [index, child] of children.entries()) {
            if (index > 0) cursor += gapX;
            cursor += place(child, cursor);
        }

        for (const child of children) {
            const childBox = boxes.get(child.pathKey);
            if (!childBox) continue;
            connectors.push({
                fromPathKey: occurrence.pathKey,
                toPathKey: child.pathKey,
                x1: x + width / 2,
                y1: y + cardHeight,
                x2: childBox.x + childBox.width / 2,
                y2: childBox.y,
            });
        }

        return slot;
    }

    const width = place(root, 0);

    let maxTier = 0;
    for (const box of boxes.values()) maxTier = Math.max(maxTier, box.tier);

    return {
        boxes,
        connectors,
        width,
        height: (maxTier + 1) * cardHeight + maxTier * gapY,
    };
}
