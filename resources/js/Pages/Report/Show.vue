<script setup lang="ts">
/**
 * The report page (PLAN.md Phase 7, ui.md §9): route
 * `GET /assessments/{assessment}/report`.
 *
 * ## D39 — two chromes, chosen on whether `auth()->user()` exists
 *
 * The report is visible to the same audience as the tree editor's read
 * route (AssessmentPolicy::view: owner, any collaborator role, admin, a
 * public assessment, or a valid share-link session — see
 * ReportController's docblock), which includes anonymous guests
 * `AuthenticatedLayout` has no chrome for (it requires a `{name,email}`
 * user). Wrapping this page in it unconditionally is the obvious fix and it
 * breaks every share link — a guest hits the layout's `nav` prop reads and
 * gets a chrome built for an account that does not exist.
 *
 * So: `isGuest` below is read off the shared `auth.user` Inertia prop
 * (`HandleInertiaRequests::share()` — present on every request, not just
 * this controller's), and it decides which of two branches in the template
 * mounts. An authenticated viewer — owner, collaborator, admin, or an
 * authenticated reader of a public assessment — gets the sidebar shell
 * every other authenticated page has (W41). A share-link guest gets the
 * bare page this route always rendered.
 *
 * Both branches mount `Components/Report/ReportDocument.vue` and
 * `Components/Report/ReportOptionsRow.vue` once each — the report content
 * and the search/options/zoom row are identical either way and each lives
 * in exactly one file, so the two branches cannot drift the way W74's
 * hand-copied toolbar geometry once did (see `Components/Chrome/*`'s own
 * docblocks: mounting the same component twice does not risk that; hand-
 * copying markup twice is exactly what did). What differs between the
 * branches is only the surrounding chrome.
 *
 * ## AssessmentActions — mounted for an authenticated viewer only (D40)
 *
 * `Components/Toolbar/AssessmentActions.vue` bundles Settings, History,
 * Share, Export and Print. Its own docblock says it renders nothing
 * `abilities.manage` does not allow for Settings and Share, but **History
 * has no ability gate at all** — it is offered to anyone who can see the
 * bar, because in the editor and the list "anyone who can see the bar" is
 * always an authenticated viewer with at least `view`. On the report that
 * is no longer true: a share-link guest reaches this page with no
 * `auth.user`, and `assessments.history.index` sits behind `auth`
 * middleware (routes/web.php) — a guest who clicked History would be
 * bounced to a login page for an assessment they were never asked to sign
 * in to see. Rather than editing AssessmentActions.vue to add a gate it has
 * never needed anywhere else (it is not this package's file — W82 owns it),
 * the bar is simply not mounted for a guest. Export and Print stay
 * reachable for a guest exactly as they always were, through this page's
 * own controls in the bare branch below — both routes carry no `auth`
 * middleware (`ReportController`'s docblock: "every action here is a
 * read", gated only on `AssessmentPolicy::view`), so neither breaks.
 *
 * ## Search, options, zoom (W83)
 *
 * `useReportSearch.ts` — not `useSearch.ts` — backs the reused
 * `SearchBox.vue`: see that composable's file header for why the report
 * does not construct an `AssessmentStore` just to search its own content.
 * One instance (`search` below) is shared by both branches' mount of
 * `ReportOptionsRow`/`ReportDocument`, so typing in the box and the
 * highlighted row in the document stay the same state regardless of which
 * chrome is showing.
 *
 * The options row is now always visible beside the search box — there is no
 * open/close state any more (contrast this file's git history through
 * W80). Changing one still re-requests the page with a new query string:
 * `updateOptions()` is unchanged, and the assembled report is still
 * produced exactly once, server-side, by `ReportBuilder` — nothing on this
 * page recomputes a figure from it
 * (`tests/js/report/noClientSideFigures.spec.ts`).
 *
 * Zoom (D43) applies to the report body only, via `ReportDocument.vue`'s
 * `zoomStyle` prop — the chrome above it and the print output are both
 * untouched. `useCanvas.ts` (Integration 2.5) widened its own `view` option
 * to a `CanvasView` (`EditorViewMode | 'report'`) precisely so this page
 * could pass `view: 'report'` and get a key and a baseline
 * (`CANVAS_BASE_SCALE.report`, `1.0`) of its own through the same mechanism
 * the chart and list use, rather than folding a marker into the
 * `assessmentUuid` argument.
 *
 * **Zoom scales type size, not page width** — the same primitive the list
 * view uses (CSS `zoom`, D9), applied to `ReportDocument`'s `max-w-4xl`
 * container. This is the half of the brief flagged "decide by measurement,
 * not by argument, against a printed page," and it is not settled by this
 * comment: it is the defensible default, recorded honestly as a guess. The
 * report already has a fixed reading measure, and a reader asking to see
 * "more" of a document that is already word-wrapped almost always means
 * "bigger," not "wider" — stretching the measure would fight a cap chosen
 * for readability (the same reasoning W70 gives for the list view's own
 * cap) for no reason a print reader has asked for. What would change this:
 * printing a zoomed report at several zoom levels and checking whether the
 * flex/tabular-nums rows in `AssessmentTreeSection`/`RiskRegisterSection`
 * (not `<table>`s) visibly stop agreeing in size with the reflowing prose —
 * jsdom has no layout engine and cannot show that either way. If they do,
 * the fix most likely belongs in those rows' own markup, not in a switch
 * away from `zoom`.
 */
import AssessmentActions from '@/Components/Toolbar/AssessmentActions.vue';
import Button from '@/Components/UI/Button.vue';
import { useTheme } from '@/Components/UI/useTheme';
import { useCanvas } from '@/composables/useCanvas';
import { useReportSearch } from '@/composables/useReportSearch';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import '../../../css/print.css';
import ChromeHeaderBand from '../../Components/Chrome/ChromeHeaderBand.vue';
import ChromeTitleRow from '../../Components/Chrome/ChromeTitleRow.vue';
import ExportMenu from '../../Components/Report/ExportMenu.vue';
import ReportDocument from '../../Components/Report/ReportDocument.vue';
import ReportOptionsRow from '../../Components/Report/ReportOptionsRow.vue';
import type { ReportData } from '../../Components/Report/types';
import ShareDialog from '../../Components/Share/ShareDialog.vue';

const props = defineProps<{
    /**
     * W54 adds the written executive summary to this prop. `summaryDraft` is
     * null for anyone who cannot accept it — the server withholds it rather
     * than the template hiding it, because un-reviewed model text should not
     * reach a public reader's page source at all.
     */
    assessment: {
        uuid: string;
        summaryNarrative?: string | null;
        summaryNarrativeGenerated?: boolean;
        summaryDraft?: string | null;
        canEditSummary?: boolean;
        assistantAvailable?: boolean;
    };
    /** ReportController::show() — AssessmentPolicy::manage(), resolved once server-side. */
    abilities?: { manage: boolean };
    report: ReportData;
}>();

useTheme();

const page = usePage();

/** D39. Present on every request (HandleInertiaRequests::share()); this is the only branch this file needs. */
const isGuest = computed(() => !page.props.auth?.user);
const currentUserId = computed(() => page.props.auth?.user?.id ?? null);
const abilities = computed(() => props.abilities ?? { manage: false });

function updateOptions(patch: Partial<ReportData['options']>) {
    const next = { ...props.report.options, ...patch };
    router.get(
        route('assessments.report.show', props.assessment.uuid),
        {
            baseline: next.baseline,
            include_shelved: next.includeShelved ? '1' : '0',
            include_comments: next.includeComments ? '1' : '0',
            include_methodology: next.includeMethodology ? '1' : '0',
            show_assignees: next.showAssignees ? '1' : '0',
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

/**
 * Download mode (W33): `?download=1`. Reached from the export menu's
 * **Everything expanded** item since W74 (D35) and from each row of the
 * assessment list; the button it was named after is gone, the URL is not.
 * Everything is expanded and both states are shown — ReportOptions forces
 * that server-side and ignores the rest of the query string, so a stale link
 * cannot produce a "full" report quietly missing the target state.
 *
 * Here it means three things: no options row (the state it would show is
 * not editable on this path), a document title that makes a sensible
 * filename, and the save dialog opening by itself.
 */
const isDownload = computed(() => props.report.options.download);

const documentTitle = computed(() => {
    const name = props.report.cover.title ?? 'Assessment';
    if (!isDownload.value) return `Report — ${name}`;

    // Every browser derives the suggested PDF filename from the document
    // title, so this is the filename: "Report — Assessment" is not one
    // anybody wants on disk.
    const date = new Date(props.report.cover.generatedAt);
    const stamp = Number.isNaN(date.getTime())
        ? ''
        : ` — ${date.toISOString().slice(0, 10)}`;

    return `ArgueSecure — ${name}${stamp}`;
});

const showPrintHint = ref(false);
const PRINT_HINT_KEY = 'arguesecure.report.print-hint-shown';

/** Shown once per browser, the first time either Print path is used. */
function showPrintHintOnce() {
    if (
        typeof window !== 'undefined' &&
        !window.localStorage.getItem(PRINT_HINT_KEY)
    ) {
        showPrintHint.value = true;
        window.localStorage.setItem(PRINT_HINT_KEY, '1');
    }
}

/**
 * The guest branch's own Print button calls this directly. The authenticated
 * branch's `AssessmentActions` calls `window.print()` itself (its own
 * docblock: "Print calls `window.print()` itself... also emits `print`
 * first so a caller that wants to layer something on... can") — its
 * `@print` handler is `showPrintHintOnce` alone, so the hint is not shown
 * twice.
 */
function onPrint() {
    showPrintHintOnce();
    window.print();
}

/**
 * Opens the save dialog once in download mode, after the first paint so the
 * charts and fonts are settled. The dialog is guarded per URL in
 * sessionStorage: an Inertia back-navigation onto the same page must not
 * reopen it, which is the kind of thing that makes a browser feel possessed.
 */
onMounted(() => {
    if (!isDownload.value || typeof window === 'undefined') return;

    const key = `arguesecure.report.autoprint:${window.location.search}`;
    if (window.sessionStorage.getItem(key)) return;
    window.sessionStorage.setItem(key, '1');

    showPrintHint.value = true;
    window.requestAnimationFrame(() =>
        window.setTimeout(() => window.print(), 0),
    );
});

/**
 * The switcher's other two positions (W35). A share-link guest has no
 * `auth.user`, which is exactly the `'anon'` case the storage key already
 * handles — so the switcher works for them too, and lands them in the editor's
 * read-only view rather than nowhere.
 */
const editorHref = computed(() =>
    route('assessments.show', props.assessment.uuid),
);

/*
 * The three export links that used to live here — Download report, Defences
 * CSV, Export JSON — are now items in Components/Report/ExportMenu.vue
 * (D34, D35). The forced-full URL W33 defined has not gone anywhere: it is
 * the menu's "Everything expanded" item, and `?download=1` still overrides
 * every other parameter server-side on every export route.
 */

/** W83. One search instance, shared by both branches. See file header. */
const search = useReportSearch(() => props.report);

/**
 * W83, D43, rewired for Integration 2.5. `useCanvas.ts` now accepts
 * `view: 'report'` (a `CanvasView`, not an `EditorViewMode` — the report is
 * not a selectable editor view) and keys and scales this instance's zoom
 * for that view on its own, the same way the chart and list already do. No
 * fold onto `assessmentUuid` is needed any more.
 */
const canvas = useCanvas({
    assessmentUuid: props.assessment.uuid,
    userId: currentUserId.value,
    view: 'report',
});

/**
 * The report's own `listStyle` — CSS `zoom`, not the chart's geometric
 * `transform` (see the file header's type-size-not-width note) — scaled by
 * `CANVAS_BASE_SCALE.report` (`1.0`) because `view: 'report'` was passed
 * above. Reading `canvas.listStyle` here does not borrow the *list view's*
 * baseline; `listStyle` is the CSS-`zoom` mechanism generically, and it
 * multiplies by whichever view this `useCanvas()` instance was constructed
 * for.
 */
const zoomStyle = computed(() => canvas.listStyle.value);

const shareDialogOpen = ref(false);
</script>

<template>
    <Head :title="documentTitle" />

    <AuthenticatedLayout v-if="!isGuest">
        <template v-if="!isDownload" #header>
            <ChromeTitleRow :title="report.cover.title ?? 'Report'">
                <AssessmentActions
                    :assessment="assessment"
                    :abilities="abilities"
                    view-mode="report"
                    :export-options="report.options"
                    @share="shareDialogOpen = true"
                    @print="showPrintHintOnce"
                />
            </ChromeTitleRow>
        </template>

        <!--
            `no-print` is explicit here, not incidental. Without it, this row
            is hidden at print time only by `data-print-hide`, which
            `print.css` scopes as `.editor-print [data-print-hide]` — a class
            `AuthenticatedLayout.vue`'s `inAssessment` regex
            (`/^\/assessments\/[^/]+/`) happens to also match this route, so
            the hiding worked, but by an accident of two files agreeing with
            no test and no comment tying them together. A future tightening
            of that regex to exclude non-editor assessment routes would
            silently reprint the search box, the options row, the zoom
            controls and the switcher onto every authenticated user's report
            (ui.md §9: print hides all navigation, actions and the options
            row). The guest branch below already states this on its own
            wrapper; this one now does too, so the property holds
            independently of `inAssessment`
            (tests/js/report/reportPrintVisibility.spec.ts).
        -->
        <div v-if="!isDownload" class="no-print">
            <ReportOptionsRow
                :options="report.options"
                :search="search"
                :zoom="canvas.zoom.value"
                :report-href="route('assessments.report.show', assessment.uuid)"
                :editor-href="editorHref"
                :assessment-uuid="assessment.uuid"
                :user-id="currentUserId"
                @update-options="updateOptions"
                @zoom-in="canvas.zoomIn"
                @zoom-out="canvas.zoomOut"
                @zoom-reset="canvas.zoomReset"
            />

            <p
                v-if="showPrintHint"
                class="no-print text-text-muted mx-auto max-w-4xl px-4 pt-3 text-xs sm:px-6"
            >
                Choose “Save as PDF” as the destination.
            </p>
        </div>

        <ReportDocument
            :assessment="assessment"
            :report="report"
            :zoom-style="zoomStyle"
            :active-match-key="search.activeMatch.value?.pathKey ?? null"
        />

        <ShareDialog
            :show="shareDialogOpen"
            :assessment-uuid="assessment.uuid"
            @close="shareDialogOpen = false"
        />
    </AuthenticatedLayout>

    <div v-else class="bg-surface-0 min-h-screen">
        <div v-if="!isDownload" class="no-print">
            <!--
                W74 item 15, rebuilt in W81 — the chrome above the report is
                the editor's, not a copy of it. See `Components/Chrome/*` for
                why: the report cannot use `AuthenticatedLayout` for a guest
                (this file's header docblock), so it renders the same shared
                components directly instead of reproducing their geometry.

                A guest gets no `AssessmentActions` (this file's header
                docblock explains why: History has no ability gate and sits
                behind `auth` middleware) — Export and Print stay here as
                their own controls, exactly as before W83.
            -->
            <ChromeHeaderBand>
                <ChromeTitleRow :title="report.cover.title ?? 'Report'">
                    <div class="flex shrink-0 items-center gap-2">
                        <ExportMenu
                            :assessment-uuid="assessment.uuid"
                            :options="report.options"
                        />
                        <!--
                            D35: "Print as configured" is just "Print". The
                            aria-label matches AssessmentActions.vue's own
                            `printLabel` for view-mode "report" — one action,
                            one accessible name, whichever chrome renders it.
                        -->
                        <Button
                            size="sm"
                            icon="file-text"
                            aria-label="Print report"
                            @click="onPrint"
                        >
                            Print
                        </Button>
                    </div>
                </ChromeTitleRow>
            </ChromeHeaderBand>

            <ReportOptionsRow
                :options="report.options"
                :search="search"
                :zoom="canvas.zoom.value"
                :report-href="route('assessments.report.show', assessment.uuid)"
                :editor-href="editorHref"
                :assessment-uuid="assessment.uuid"
                :user-id="currentUserId"
                @update-options="updateOptions"
                @zoom-in="canvas.zoomIn"
                @zoom-out="canvas.zoomOut"
                @zoom-reset="canvas.zoomReset"
            />
        </div>

        <p
            v-if="isDownload && showPrintHint"
            class="no-print text-text-muted mx-auto max-w-4xl px-4 pt-3 text-xs sm:px-6"
        >
            Choose “Save as PDF” as the destination.
        </p>

        <ReportDocument
            :assessment="assessment"
            :report="report"
            :zoom-style="zoomStyle"
            :active-match-key="search.activeMatch.value?.pathKey ?? null"
        />
    </div>
</template>
