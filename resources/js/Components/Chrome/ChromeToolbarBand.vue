<script setup lang="ts">
/**
 * The toolbar band under the title header, shared by the editor and the
 * report (ui.md §9, "one chrome for the editor and the report").
 *
 * ## The two paddings
 *
 * There are two, and they are the reason this file exists. The band pads the
 * row away from the header (`py-3`) and from the window edges (`px-3`); the
 * row pads its own controls (`py-1.5`, `px-3`). Until W81 the outer one lived
 * in `Pages/Assessment/Show.vue` and the inner one in
 * `Components/Toolbar/Toolbar.vue`, and the report reproduced both by hand —
 * except that it reproduced only the outer one, so its switcher sat 6px above
 * the editor's. Two numbers that had to agree across two files by inspection
 * did not, and the drift shipped. They are now one definition.
 *
 * ## The anchor slot
 *
 * ui.md §9 requires the switcher to occupy the same screen position in all
 * three views. Vertically that is the paddings above. Horizontally it is this:
 * the `anchor` slot is **last** in the right-hand group, so the distance from
 * its right edge to the window's is `px-3 + px-3` and nothing else — it does
 * not depend on what the page put in `trailing` beside it.
 *
 * That is why the editor's zoom controls now sit to the *left* of the
 * switcher rather than the right. Through W80 they sat after it, so the
 * editor's switcher was one zoom-group further from the right edge than the
 * report's, and there was no honest way to close the gap: W74 considered
 * reserving an empty spacer the width of the zoom controls on the report and
 * rejected it, because a spacer asserts the fix rather than the property —
 * it would have to be resized by hand every time the zoom group changed, and
 * a test measuring it would pass for the wrong reason. Ordering the slot last
 * makes the property true by construction instead, for any siblings.
 *
 * Zoom and the switcher stay adjacent inside one right-hand group, which is
 * what W34 put them there for: zoom is a property of the view you are in, and
 * after W34 it does two different things depending on which one that is.
 *
 * `data-print-hide` sits on the group rather than on each control: everything
 * in it is chrome, and `print.css` hides the group as a whole (ui.md §9,
 * "Search, the view switcher and the zoom controls go"). The row itself stays
 * on paper because the filter chips in the default slot are the only
 * statement of what the reader is looking at.
 *
 * ## Wrapping below `lg`
 *
 * The report's `ReportOptionsRow.vue` puts a search box, two radio groups and
 * three toggles into this same row alongside zoom and the switcher — more
 * than the editor's `Toolbar.vue` ever put here. The inner option groups
 * already wrap on their own (`gap-x-4 gap-y-2 flex-wrap`), but until now the
 * outer row did not, so a narrow report window overflowed instead. The row
 * wraps below `lg` (`max-lg:flex-wrap`) and stays a single, non-wrapping row
 * at `lg` and above, which is what `Toolbar.spec.ts` and
 * `tests/e2e/16-toolbar-one-row.spec.ts` require at 1280px for the editor.
 *
 * ## `self-start` on the right-hand group (Integration 2.5)
 *
 * Even at `lg` and above the report's default-slot content (search plus two
 * option groups) does not fit its own natural width — `choices` and
 * `inclusions` measure roughly 495px and 514px unwrapped, well past what is
 * left of a 1280px row once the search box and the right-hand group are
 * accounted for. `flex-wrap` on each of those two groups (not on this row)
 * absorbs that by stacking their controls onto two or three lines, which is
 * correct and intended — but this row's own `items-center` then centred the
 * *right-hand group* against that taller, multi-line content, pushing the
 * view switcher 21px lower on the report than on the editor, where nothing
 * ever wraps. That is exactly the drift `tests/e2e/10-report.spec.ts`'s
 * "same place in all three views" test exists to catch.
 *
 * `self-start` on the right-hand group only pins it to the row's own
 * cross-start instead of the row's (content-dependent) centre. On the
 * editor, where the right-hand group is already the tallest thing in the
 * row, `self-start` and `items-center` land in the same place — there is no
 * visible change. On the report, it keeps the group flush with the first
 * line of options regardless of how many lines the options wrap onto below
 * it, which is what makes the two pages agree again.
 */
</script>

<template>
    <div class="border-border-subtle bg-surface-1 border-b px-3 py-3">
        <div
            data-testid="toolbar-row"
            class="flex items-center gap-3 px-3 py-1.5 max-lg:flex-wrap"
        >
            <slot />
            <div
                class="ml-auto flex shrink-0 items-center gap-2 self-start"
                data-print-hide
            >
                <slot name="trailing" />
                <slot name="anchor" />
            </div>
        </div>
        <slot name="below" />
    </div>
</template>
