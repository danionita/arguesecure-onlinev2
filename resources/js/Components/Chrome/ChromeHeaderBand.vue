<script setup lang="ts">
/**
 * The band that carries a page's title row (ui.md §2, §9).
 *
 * ## Why this is a component and not four lines of markup in two files
 *
 * The editor gets this band from `AuthenticatedLayout`; the report cannot —
 * a share-link guest has no `{name, email}` user for that layout to render,
 * so the report is deliberately not wrapped in it (see Pages/Report/Show.vue's
 * file header). W74 therefore reproduced the band by hand, and the copy drifted
 * from the original before it shipped: the report's toolbar sat 6px above the
 * editor's because only one of the editor's *two* paddings had been copied.
 *
 * ui.md §9 requires the view switcher to occupy the same screen position in
 * all three views. That is a claim about the height of everything above the
 * switcher, so every padding above it has to have exactly one definition.
 * This band is the first of them; `ChromeToolbarBand.vue` is the second.
 *
 * Nothing here knows about a user, an assessment or a store, which is the
 * property that lets the report render it: the account chrome stays in
 * `AuthenticatedLayout` and only the geometry is shared.
 *
 * ## `no-print` (W82, D42)
 *
 * This band now carries `AssessmentActions` — Settings, History, Share,
 * Export, Print — through whichever page's `header` slot fills it, and every
 * one of those is chrome, not content. The report already wrapped its own
 * copy of this band in a page-level `no-print` div, so it was never visible
 * here; the editor did not, because `AuthenticatedLayout` renders this band
 * *outside* the `.editor-print`-scoped `<main>` its other print rules are
 * keyed on, so nothing in `print.css` reached it. The band's title duplicates
 * onto paper anyway — `AuthenticatedLayout`'s `.print-header` running header,
 * inside `<main>`, carries the assessment title and the print date — so
 * hiding the whole band loses nothing and the fix belongs here rather than
 * in a per-page `no-print` wrapper, which is exactly the kind of thing two
 * pages have to agree on by inspection and once didn't (see this file's
 * first docblock).
 */
</script>

<template>
    <header class="border-border-subtle bg-surface-1 no-print border-b">
        <div class="px-4 py-4 sm:px-6">
            <slot />
        </div>
    </header>
</template>
