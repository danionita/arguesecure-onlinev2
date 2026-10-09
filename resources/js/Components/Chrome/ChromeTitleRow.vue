<script setup lang="ts">
/**
 * The title and its actions, inside a `ChromeHeaderBand` (ui.md §2, §9).
 *
 * Separate from the band because the two pages reach it by different routes:
 * the editor renders this into `AuthenticatedLayout`'s `header` slot, which
 * supplies the band around it, while the report — which cannot use that
 * layout — renders the band itself and this inside it. A single component
 * covering both would have to span two slots of a layout it does not control.
 *
 * `min-h-7` is the height of a `size="sm"` Button, which is what both pages
 * put beside the title. It is stated rather than inherited from whichever
 * control happens to be tallest, so the band's height — and with it the
 * vertical coordinate of the view switcher below — does not change when a
 * page adds, removes or hides one of its actions. `tests/e2e/10-report.spec.ts`
 * measures the consequence in a real browser.
 *
 * Actions are the default slot rather than a wrapped group: they become
 * direct children of this flex row, so a page can still place one with
 * `ml-auto` (the editor's presence avatars) without a wrapper fighting it.
 */
defineProps<{ title: string }>();
</script>

<template>
    <div class="flex min-h-7 items-center justify-between gap-4">
        <h2
            class="text-text-primary truncate text-xl leading-tight font-semibold"
        >
            {{ title }}
        </h2>
        <slot />
    </div>
</template>
