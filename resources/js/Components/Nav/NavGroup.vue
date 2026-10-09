<script setup lang="ts">
/**
 * A collapsible group in the sidebar — "My assessments", "Shared with me"
 * (W41).
 *
 * A real disclosure: a `<button>` carrying `aria-expanded` and
 * `aria-controls`, with the list it controls given the matching id. Not a div
 * with a click handler and a rotating chevron, which is what a nav sidebar
 * usually is and which announces nothing at all.
 *
 * The group header doubles as a link to the full page, because the sidebar
 * shows at most ten of anything. Those are two different actions on one row,
 * so they are two different controls: the label navigates, the chevron
 * expands. Making the whole row do both is how a user ends up somewhere they
 * did not ask to go.
 */
import Icon from '@/Components/UI/Icon.vue';
import type { IconName } from '@/Components/UI/icons.generated';
import Tooltip from '@/Components/UI/Tooltip.vue';
import { Link } from '@inertiajs/vue3';
import { computed, ref, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        icon: IconName;
        label: string;
        /** Where the label navigates: the full, unabridged list. */
        href: string;
        /** Collapsed to the icon rail: the group becomes a plain link. */
        compact?: boolean;
        count?: number;
        defaultOpen?: boolean;
    }>(),
    {
        compact: false,
        count: 0,
        defaultOpen: false,
    },
);

const open = ref(props.defaultOpen);
const listId = useId();

const toggleLabel = computed(() =>
    open.value ? `Collapse ${props.label}` : `Expand ${props.label}`,
);
</script>

<template>
    <!--
        In the rail there is nothing to disclose into: the group falls back to
        a single link to its full page. An expandable list of titles in a 48px
        column would be an unreadable stack of first letters.
    -->
    <!-- The label to the side, like every rail item (NavItem); a native
         `title` drew it under the cursor, on top of the next icon. -->
    <Tooltip v-if="compact" :text="label" placement="right" class="w-full">
        <Link
            :href="href"
            :aria-label="label"
            class="text-text-secondary hover:bg-surface-2 hover:text-text-primary focus-visible:outline-focus-ring flex w-full items-center justify-center rounded-md px-2 py-1.5 outline-none focus-visible:outline-2 focus-visible:-outline-offset-2"
        >
            <Icon :name="icon" :size="16" />
        </Link>
    </Tooltip>

    <div v-else>
        <div class="flex items-center">
            <Link
                :href="href"
                class="text-text-secondary hover:bg-surface-2 hover:text-text-primary focus-visible:outline-focus-ring flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-sm outline-none focus-visible:outline-2 focus-visible:-outline-offset-2"
            >
                <Icon :name="icon" :size="16" class="shrink-0" />
                <span class="min-w-0 flex-1 truncate text-left">{{
                    label
                }}</span>
            </Link>
            <button
                type="button"
                :aria-expanded="open"
                :aria-controls="listId"
                :aria-label="toggleLabel"
                class="text-text-muted hover:bg-surface-2 hover:text-text-primary focus-visible:outline-focus-ring shrink-0 rounded-md p-1.5 outline-none focus-visible:outline-2 focus-visible:-outline-offset-2"
                @click="open = !open"
            >
                <Icon
                    :name="open ? 'chevron-down' : 'chevron-right'"
                    :size="14"
                />
            </button>
        </div>

        <ul v-show="open" :id="listId" class="mt-0.5 space-y-0.5 pl-4">
            <slot />
            <li v-if="count === 0" class="text-text-muted px-2 py-1 text-xs">
                Nothing here yet.
            </li>
            <!--
                W73 item 19: a place for an action that belongs *to* the group
                rather than in it — "New assessment" under My assessments.
                Last, after the emptiness notice, because it is what you do
                about the emptiness and reads wrong above it.
            -->
            <slot name="footer" />
        </ul>
    </div>
</template>
