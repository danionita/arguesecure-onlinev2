<script setup lang="ts">
/**
 * One dashboard section: a heading, a list, and — when the list is empty —
 * an explanation rather than a gap (W42).
 *
 * The empty state is a prop rather than a slot because it is the part most
 * likely to be skipped. Making it required forces every caller to say what
 * would appear there and why, which is the difference between a dashboard
 * that reads as an invitation and one that reads as four broken boxes. A new
 * user sees all four empty on their first visit, and two of them stay empty
 * until W46 and W47 ship.
 *
 * A real `<section>` with `aria-labelledby`, so a screen reader can move
 * between them by landmark instead of walking every row.
 *
 * ## The `panel` variant (W73 item 21, D32)
 *
 * D32 turned the four stacked sections into two sections of two tabs. The
 * four empty states survive that unchanged — they are the part of this page
 * that was got right, and they are as true inside a tab panel as they were
 * under a heading. What does not survive is the heading and the landmark: in
 * a panel the tab *is* the accessible label, wired through
 * `aria-labelledby`, so rendering an `<h2>` as well would announce the same
 * name twice and add a landmark inside a tabpanel that nothing needs.
 *
 * Hence one component with two variants rather than a second one alongside
 * it. A copy would drift, and the empty states are exactly what would drift
 * first: they are prose, and prose is what gets updated in one place.
 */
import EmptyState from '@/Components/UI/EmptyState.vue';
import type { IconName } from '@/Components/UI/icons.generated';
import { Link } from '@inertiajs/vue3';
import { useId } from 'vue';

withDefaults(
    defineProps<{
        /** Announced in the `section` variant; the tab carries it in a panel. */
        title: string;
        /** Passed rather than derived from the slot: a slot's length is not observable. */
        count: number;
        emptyIcon: IconName;
        emptyTitle: string;
        emptyDescription: string;
        /** Sections that are an excerpt of a longer list link to the whole of it. */
        seeAllHref?: string;
        variant?: 'section' | 'panel';
    }>(),
    {
        seeAllHref: undefined,
        variant: 'section',
    },
);

const headingId = useId();
</script>

<template>
    <component
        :is="variant === 'panel' ? 'div' : 'section'"
        :aria-labelledby="variant === 'panel' ? undefined : headingId"
    >
        <div
            v-if="variant === 'section' || seeAllHref"
            class="mb-2 flex items-baseline justify-between gap-4"
        >
            <h2
                v-if="variant === 'section'"
                :id="headingId"
                class="text-text-primary text-sm font-semibold tracking-wide uppercase"
            >
                {{ title }}
            </h2>
            <Link
                v-if="seeAllHref && count > 0"
                :href="seeAllHref"
                class="text-text-muted hover:text-text-primary ml-auto text-sm hover:underline"
            >
                See all
            </Link>
        </div>

        <ul v-if="count > 0" class="flex flex-col gap-2">
            <slot />
        </ul>
        <EmptyState
            v-else
            :icon="emptyIcon"
            :title="emptyTitle"
            :description="emptyDescription"
        />
    </component>
</template>
