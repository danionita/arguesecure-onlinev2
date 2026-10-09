<script setup lang="ts">
/**
 * The application's navigation (W41, ui.md §2, item 13).
 *
 * It replaces `TopNav`, whose only real navigation was a product mark and a
 * user menu. The rest of that bar was a search box whose placeholder read
 * "Search this assessment…" on every page in the application, including the
 * ones with no assessment — and which, on the page that *did* have one, was
 * not wired to the tree's own search either. It searched nothing, anywhere.
 * The editor's working search lives in the toolbar (W34) and stays there.
 *
 * ## Shape
 *
 * Destinations at the top, account at the bottom, in DOM order, so tabbing
 * through the sidebar walks it top to bottom exactly as it reads. Nothing is
 * inside a menu that could be a link: a dropdown is a click to reveal a
 * click, which CLAUDE.md names as the legacy defect this rewrite exists to
 * remove, and it applies to chrome as much as to node cards.
 *
 * ## Two widths
 *
 * See `useSidebar.ts`. In an assessment it is a 48px rail and expanding it
 * floats over the content rather than pushing it, because the chart lays out
 * to the width it is given and someone is usually pointing at it.
 *
 * ## What left (W82, D41)
 *
 * History and Assessment settings used to live here, gated on
 * `currentAssessment` — the tell that they never belonged: the sidebar is
 * application-scoped (dashboard, assessments, templates, admin, profile),
 * and both of those are meaningless without one specific assessment already
 * open. They are now in `Toolbar/AssessmentActions.vue`, rendered from the
 * chrome above whichever of the three views is on screen, and this file no
 * longer takes a `currentAssessment` prop at all — nothing here reads it any
 * more. Removed in the same commit that adds them to the action bar, per
 * CLAUDE.md's rule that an affordance may not leave one surface in one
 * commit and arrive on another in the next (W36, broken once by W72).
 */
import Icon from '@/Components/UI/Icon.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import NavGroup from './NavGroup.vue';
import NavItem from './NavItem.vue';
import ThemeControl from './ThemeControl.vue';
import { PANEL_WIDTH_CLASS } from './useSidebar';

export interface NavAssessment {
    uuid: string;
    title: string;
    ownerName?: string | null;
}

const props = defineProps<{
    myAssessments: NavAssessment[];
    sharedWithMe: NavAssessment[];
    isAdmin: boolean;
    notificationCount: number;
    /** Icon-rail mode: labels move to accessible names, groups become links. */
    railed: boolean;
    /** Whether the labelled panel is on screen at all (docked or floating). */
    panelVisible: boolean;
    /** The floating case, which needs a backdrop and Escape. */
    isOverlay: boolean;
    currentUrl: string;
}>();

const emit = defineEmits<{ toggle: []; close: []; notifications: [] }>();

/** Icons only when the panel is not showing — the overlay shows labels. */
const compact = computed(() => !props.panelVisible);

function isCurrent(href: string): boolean {
    try {
        return new URL(href, 'http://x').pathname === props.currentUrl;
    } catch {
        return false;
    }
}

const toggleLabel = computed(() => {
    if (props.railed) {
        return props.isOverlay ? 'Close navigation' : 'Open navigation';
    }

    return 'Collapse navigation';
});
</script>

<template>
    <!--
        A click-catching backdrop, only in the floating case. Not rendered
        when docked, where there is nothing to dismiss.
    -->
    <div
        v-if="isOverlay"
        class="bg-surface-overlay fixed inset-0 z-30"
        aria-hidden="true"
        data-testid="nav-backdrop"
        @click="emit('close')"
    />

    <nav
        aria-label="Main"
        class="border-border-subtle bg-surface-1 flex h-screen flex-col border-r"
        :class="
            // Floating: taken out of flow entirely and given its own width,
            // so the gutter the layout reserves stays at the rail's width and
            // nothing below it moves. This is the line the 'expanding the nav
            // does not change the chart's width' test is really about.
            // Docked: fills whatever gutter the layout reserved, which is the
            // single place either width is decided.
            isOverlay
                ? `fixed top-0 left-0 z-40 shadow-xl ${PANEL_WIDTH_CLASS}`
                : 'sticky top-0 w-full'
        "
        @keydown.esc="isOverlay && emit('close')"
    >
        <!--
            Fixed height rather than vertical padding, so this row is the same
            height at both widths.

            It holds a 20px icon in the rail and that icon plus a line of
            16px/24px text in the panel, so `py-2` alone made the panel's row
            6px taller — which pushed the toggle row below it down by exactly
            that, and the toggle is the control W73 item 18 moved *because* it
            was moving. The end-to-end assertion caught it the first time it
            ran; unit tests cannot, since jsdom computes no layout.
            `h-9` is 36px: `py-2` (16px) plus the 20px icon, i.e. the rail's
            natural height, held for both.
        -->
        <div
            class="flex h-9 items-center gap-2 px-2"
            :class="compact && 'justify-center'"
        >
            <Link
                :href="route('dashboard')"
                class="text-text-primary focus-visible:outline-focus-ring flex min-w-0 items-center gap-2 rounded font-semibold outline-none focus-visible:outline-2"
                :aria-label="compact ? 'ArgueSecure — dashboard' : undefined"
            >
                <Icon name="shield" :size="20" class="text-node-root-text" />
                <span v-if="!compact" class="truncate">ArgueSecure</span>
            </Link>
        </div>

        <!--
            One location for the toggle, at both widths (W73 item 18).

            W41 gave the rail's control a dedicated row because two 16px
            targets side by side in a 48px column is a coin toss, and that
            reasoning is right — so the fix is not to merge this row upward
            into the logo row. It is to move the *other* control down into
            this one. Before W73 the expanded state put it at `ml-auto`
            beside the logo, which meant the button that changes the width
            moved when the width changed: the click that collapses the panel
            and the click that expands it again were never in the same place,
            and this is the control in the sidebar that gets used most.

            Labelled by its visible text when there is room for it, and by
            `aria-label` when there is not — the same rule NavItem follows,
            so the row cannot drift from the destinations under it.

            The button is `h-8` rather than `py-1.5`, for the same reason the
            logo row above is `h-9`: it holds a 16px icon alone in the rail and
            that icon beside a 20px line of `text-sm` in the panel, so padding
            alone made it 4px taller in one state and moved its centre by 2px.
            This is the control item 18 moved *because* it was moving, so its
            own box must not depend on the width either.
        -->
        <div class="border-border-subtle border-b px-2 pb-2">
            <button
                type="button"
                :aria-expanded="panelVisible"
                :aria-label="compact ? toggleLabel : undefined"
                :title="compact ? undefined : toggleLabel"
                data-testid="nav-toggle"
                class="text-text-muted hover:bg-surface-2 hover:text-text-primary focus-visible:outline-focus-ring flex h-8 w-full items-center gap-2 rounded-md px-2 text-sm outline-none focus-visible:outline-2 focus-visible:-outline-offset-2"
                :class="compact && 'justify-center'"
                @click="emit('toggle')"
            >
                <Icon
                    :name="compact ? 'chevron-right' : 'chevron-left'"
                    :size="16"
                    class="shrink-0"
                />
                <span v-if="!compact" class="min-w-0 flex-1 truncate text-left">
                    {{ toggleLabel }}
                </span>
            </button>
        </div>

        <div class="flex-1 space-y-0.5 overflow-y-auto p-2">
            <NavItem
                icon="layout-grid"
                label="Dashboard"
                :href="route('dashboard')"
                :compact="compact"
                :current="isCurrent(route('dashboard'))"
            />

            <NavGroup
                icon="file-text"
                label="My assessments"
                :href="route('assessments.index')"
                :compact="compact"
                :count="myAssessments.length"
                default-open
            >
                <li v-for="item in myAssessments" :key="item.uuid">
                    <NavItem
                        icon="corner-down-right"
                        :label="item.title"
                        :href="route('assessments.show', item.uuid)"
                        :current="
                            isCurrent(route('assessments.show', item.uuid))
                        "
                    />
                </li>

                <!--
                    W73 item 19. Below the list it adds to, inside the group
                    it adds to, rather than floating between two groups where
                    it read as a third destination. And it lands on the
                    creation dialog rather than on a list: until W73 this was a
                    bare `href` to `assessments.index`, so the most-used verb
                    in the product was a link to a list where the user then had
                    to find the real control themselves.

                    `?new=1` opens the dialog `Pages/Assessment/Index.vue`
                    already owns. W73's first version POSTed an untitled
                    assessment straight from here, which put two buttons
                    reading "New assessment" on the list page doing two
                    different things — one asking for a title, one not. Same
                    name, same behaviour is fine; same name, different
                    behaviour is not, and it is worse for a screen reader,
                    which announces both identically. Reusing the dialog also
                    keeps one write path to `assessments.store`.
                -->
                <template #footer>
                    <li>
                        <NavItem
                            icon="square-plus"
                            label="New assessment"
                            :href="route('assessments.index', { new: 1 })"
                        />
                    </li>
                </template>
            </NavGroup>

            <!--
                In the rail a NavGroup collapses to a bare link and has no
                list to sit under, so the create control returns to the top
                level rather than disappearing with the titles. The two are
                mutually exclusive — `compact` is exactly the state where the
                group renders no children at all — so this is one control in
                one place at any width, not a duplicate.
            -->
            <NavItem
                v-if="compact"
                icon="square-plus"
                label="New assessment"
                :href="route('assessments.index', { new: 1 })"
                compact
            />

            <NavGroup
                icon="users"
                label="Shared with me"
                :href="route('assessments.shared')"
                :compact="compact"
                :count="sharedWithMe.length"
            >
                <li v-for="item in sharedWithMe" :key="item.uuid">
                    <NavItem
                        icon="corner-down-right"
                        :label="
                            item.ownerName
                                ? `${item.title} — ${item.ownerName}`
                                : item.title
                        "
                        :href="route('assessments.show', item.uuid)"
                        :current="
                            isCurrent(route('assessments.show', item.uuid))
                        "
                    />
                </li>
            </NavGroup>

            <NavItem
                icon="copy"
                label="Templates"
                :href="route('templates.index')"
                :compact="compact"
                :current="isCurrent(route('templates.index'))"
            />
        </div>

        <div class="border-border-subtle space-y-0.5 border-t p-2">
            <!--
                W82 item 18. The help pages have existed since W51 and were
                reachable only by typing the URL — built once, linked from
                nowhere. Immediately above Notifications: the last item
                someone reaches for before "how do I..." is "what just
                happened".
            -->
            <NavItem
                icon="info"
                label="Help"
                :href="route('help.index')"
                :compact="compact"
                :current="
                    isCurrent(route('help.index')) ||
                    currentUrl.startsWith('/help/')
                "
            />

            <!--
                W47. No `href`: this one opens the feed rather than going
                anywhere. Through W46 it pointed at the dashboard, which was
                the closest true thing at the time and still a link that did
                not do what its label said.
            -->
            <NavItem
                icon="bell"
                label="Notifications"
                :compact="compact"
                :badge="notificationCount"
                @select="emit('notifications')"
            />

            <ThemeControl :compact="compact" />

            <!--
                Hidden for a non-admin as a courtesy, never as a guard:
                `EnsureUserIsAdmin` 403s the route regardless, and W41's tests
                assert that separately so the two are never confused.
            -->
            <NavItem
                v-if="isAdmin"
                icon="settings"
                label="Admin"
                :href="route('admin.users.index')"
                :compact="compact"
                :current="isCurrent(route('admin.users.index'))"
            />

            <NavItem
                icon="user"
                label="Profile"
                :href="route('profile.edit')"
                :compact="compact"
                :current="isCurrent(route('profile.edit'))"
            />

            <NavItem
                icon="lock"
                label="Log out"
                :href="route('logout')"
                method="post"
                :compact="compact"
            />
        </div>
    </nav>
</template>
