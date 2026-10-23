<script setup lang="ts">
/**
 * The report document itself — everything from the cover to the appendices
 * (PLAN.md Phase 7, ui.md §9). Extracted from `Pages/Report/Show.vue` in
 * W83 so the page can mount it once from either of its two chromes (D39:
 * sidebar for an authenticated user, bare for a share-link guest) without
 * hand-copying this ~120-line block into both branches — which is exactly
 * the failure `Components/Chrome/*`'s own docblocks describe for the
 * geometry above it (two copies that have to agree by inspection and once
 * did not). This component owns none of that geometry; it is the content
 * both chromes wrap.
 *
 * `zoomStyle` (W83, D43) is applied to this component's own root `<main>`
 * rather than to anything above it: the brief is explicit that "the chrome
 * does not scale, and neither does the print output" — the toolbar, the
 * search box and the options row live in the page, outside this component,
 * and are untouched by it. `print.css`'s `.report-print { zoom: 1 !important; }`
 * is the print-time override, matching how the list view's own CSS `zoom`
 * is reset for the same reason (D9, ui.md §9 "The current zoom is
 * ignored").
 */
import { computed, type CSSProperties } from 'vue';
import SummaryNarrative from '../Assistant/SummaryNarrative.vue';
import AppendicesSection from './AppendicesSection.vue';
import AssessmentTreeSection from './AssessmentTreeSection.vue';
import AttributionChart from './AttributionChart.vue';
import ControlCoverageSection from './ControlCoverageSection.vue';
import CoverSection from './CoverSection.vue';
import DefenceRankingSection from './DefenceRankingSection.vue';
import ExecutiveSummarySection from './ExecutiveSummarySection.vue';
import ProgrammeSection from './ProgrammeSection.vue';
import { omittedLabel } from './reportSections';
import RiskRegisterSection from './RiskRegisterSection.vue';
import TransfersSection from './TransfersSection.vue';
import type { ReportData } from './types';

const props = withDefaults(
    defineProps<{
        assessment: {
            uuid: string;
            summaryNarrative?: string | null;
            summaryNarrativeGenerated?: boolean;
            summaryDraft?: string | null;
            canEditSummary?: boolean;
            assistantAvailable?: boolean;
        };
        report: ReportData;
        /** `useCanvas.ts`'s `listStyle` value — CSS `zoom`, applied to the document only. */
        zoomStyle?: CSSProperties;
        /** `search.activeMatch.value?.pathKey ?? null` from useReportSearch.ts. */
        activeMatchKey?: string | null;
    }>(),
    {
        zoomStyle: undefined,
        activeMatchKey: null,
    },
);

const importanceAttributionRows = computed(() => [
    ...props.report.defencesByImportance.priced,
    ...props.report.defencesByImportance.free,
    ...props.report.defencesByImportance.unpriced,
]);

const shows = (id: string) =>
    !props.report.options.omittedSections.includes(id);
</script>

<template>
    <main
        class="report-print mx-auto max-w-4xl space-y-10 px-4 py-10 sm:px-6"
        :style="zoomStyle"
    >
        <CoverSection
            v-if="shows('cover')"
            :cover="report.cover"
            :currency="report.assessment.currency"
        />

        <!--
            W54. The written summary sits above the figures because that
            is the order it is read in: prose first, then the numbers it
            refers to.
        -->
        <SummaryNarrative
            v-if="shows('written-summary')"
            :assessment-uuid="assessment.uuid"
            :narrative="assessment.summaryNarrative ?? null"
            :generated="assessment.summaryNarrativeGenerated ?? false"
            :draft="assessment.summaryDraft ?? null"
            :can-edit="assessment.canEditSummary ?? false"
            :assistant-available="assessment.assistantAvailable ?? false"
        />

        <ExecutiveSummarySection
            v-if="shows('executive-summary')"
            :summary="report.executiveSummary"
            :baseline="report.options.baseline"
            :currency="report.assessment.currency"
            :risk-tolerance="report.assessment.riskTolerance"
        />

        <ProgrammeSection
            v-if="shows('programme')"
            :programme="report.programme"
            :currency="report.assessment.currency"
        />

        <RiskRegisterSection
            v-if="shows('risk-register')"
            :risks="report.riskRegister"
            :baseline="report.options.baseline"
            :currency="report.assessment.currency"
        />

        <DefenceRankingSection
            v-if="shows('defences-importance')"
            title="Defences ranked by cost benefit"
            description="Implemented controls. Priced ones are ordered by cost-benefit ratio; free and unpriced ones by the annual expected loss that would return if each were switched off."
            :buckets="report.defencesByImportance"
            benefit-label="If removed"
            :currency="report.assessment.currency"
        />

        <section
            v-if="shows('defences-importance')"
            class="report-section break-inside-avoid"
        >
            <h3 class="text-text-primary text-sm font-semibold">
                Share of risk reduction (log-share attribution)
            </h3>
            <p class="text-text-secondary mt-1 text-xs">
                Not a decomposition of marginal contribution — reductions are
                multiplicative, so removal deltas overlap and cannot be summed.
                This chart uses log-share attribution instead, which sums
                exactly to the inherent-to-current reduction.
            </p>
            <div class="mt-3">
                <AttributionChart
                    :rows="importanceAttributionRows"
                    :currency="report.assessment.currency"
                />
            </div>
        </section>

        <DefenceRankingSection
            v-if="shows('defences-opportunity')"
            title="Defences ranked by opportunity"
            description="Todo controls, by what implementing each would buy against the current baseline — the roadmap."
            :buckets="report.defencesByOpportunity"
            benefit-label="If implemented"
            :currency="report.assessment.currency"
        />

        <DefenceRankingSection
            v-if="report.options.includeShelved"
            title="Shelved defences — what was given up"
            description="Evaluated on demand only; never included in any total."
            :buckets="report.shelvedOpportunity"
            benefit-label="If unshelved"
            :currency="report.assessment.currency"
            :heading-level="shows('defences-opportunity') ? 'h3' : 'h2'"
        />

        <TransfersSection
            v-if="shows('transfers')"
            :transfers="report.transfers"
            :baseline="report.options.baseline"
            :currency="report.assessment.currency"
        />

        <ControlCoverageSection
            v-if="shows('control-coverage')"
            :coverage="report.controlCoverage"
        />

        <AssessmentTreeSection
            v-if="shows('tree')"
            :tree="report.tree"
            :baseline="report.options.baseline"
            :currency="report.assessment.currency"
            :active-match-key="activeMatchKey"
        />

        <AppendicesSection
            :appendices="report.appendices"
            :options="report.options"
            :summary-generated="
                (assessment.summaryNarrativeGenerated ?? false) &&
                !!assessment.summaryNarrative &&
                shows('written-summary')
            "
        />

        <!--
            W139, D118. A report with sections left out names them, so a
            partial document cannot pass for a complete one.
        -->
        <p
            v-if="report.options.omittedSections.length > 0"
            class="text-text-muted text-xs"
            data-testid="sections-omitted"
        >
            <template v-if="!shows('cover')">
                Report on {{ report.cover.title || 'Untitled assessment' }}.
            </template>
            Sections left out of this report:
            {{ report.options.omittedSections.map(omittedLabel).join(', ') }}.
        </p>
    </main>
</template>
