<script setup lang="ts">
/**
 * Control coverage (PLAN-2.7 D65, D66): per framework, each control the
 * assessment's defences cite, whether a citing defence is in place, and
 * which defences those are. It reads the assessment, not a catalogue of the
 * framework, so it lists no uncited control and never says "compliant"
 * (docs/PRODUCT.md, "not a compliance certificate"). Not rendered at all
 * when nothing cites a control, and a framework with nothing left to list
 * once shelved defences are left out is not drawn as an empty card. The same rows reach HTML, Word and Excel
 * through ReportSectionBuilder::controlCoverage().
 */
import Chip from '../UI/Chip.vue';
import StatusPill from '../UI/StatusPill.vue';
import type {
    ControlCoverage,
    ControlStatus,
    CoverageFramework,
} from './types';

defineProps<{
    coverage: ControlCoverage;
}>();

const statusLabels: Record<ControlStatus, string> = {
    implemented: 'Implemented',
    planned: 'Planned',
    shelved: 'Shelved',
};

// The chip tones are the defence statuses a control's status comes from.
const statusTones: Record<ControlStatus, 'implemented' | 'todo' | 'shelved'> = {
    implemented: 'implemented',
    planned: 'todo',
    shelved: 'shelved',
};

function count(framework: CoverageFramework, status: ControlStatus): number {
    return framework.controls.filter((c) => c.status === status).length;
}
</script>

<template>
    <section
        v-if="coverage.frameworks.length > 0 || coverage.hiddenShelved > 0"
        class="report-section"
        aria-labelledby="control-coverage-heading"
    >
        <h2
            id="control-coverage-heading"
            class="text-text-primary text-xl font-semibold"
        >
            Control coverage
        </h2>
        <p class="text-text-secondary mt-1 text-sm">
            The framework controls this assessment's defences cite, and whether
            a defence citing each is in place. It lists only what is cited, not
            every control a framework contains, and it is not a statement of
            compliance.
        </p>
        <p
            v-if="coverage.hiddenShelved > 0"
            class="text-text-muted mt-1 text-xs"
        >
            {{
                coverage.hiddenShelved === 1
                    ? '1 control cited only by shelved defences is not listed; include shelved defences to see it.'
                    : `${coverage.hiddenShelved} controls cited only by shelved defences are not listed; include shelved defences to see them.`
            }}
        </p>

        <div class="mt-4 space-y-6">
            <article
                v-for="framework in coverage.frameworks"
                :key="framework.framework"
                class="report-card"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h3 class="text-text-primary font-semibold">
                        <a
                            :href="framework.url"
                            target="_blank"
                            rel="noopener nofollow"
                            class="hover:underline"
                            >{{ framework.label }}</a
                        >
                    </h3>
                    <p class="text-text-muted text-xs">
                        {{ framework.controls.length }} cited ·
                        {{ count(framework, 'implemented') }} implemented ·
                        {{ count(framework, 'planned') }} planned<template
                            v-if="count(framework, 'shelved') > 0"
                        >
                            ·
                            {{ count(framework, 'shelved') }} shelved</template
                        >
                    </p>
                </div>

                <table class="mt-2 w-full text-left text-xs">
                    <thead>
                        <tr class="text-text-muted">
                            <th class="w-28 py-1 font-medium">Control</th>
                            <th class="w-28 py-1 font-medium">Status</th>
                            <th class="py-1 font-medium">Cited by</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="control in framework.controls"
                            :key="control.identifier"
                            class="border-border-subtle break-inside-avoid border-t align-top"
                        >
                            <td class="text-text-primary py-1 font-medium">
                                {{ control.label }}
                            </td>
                            <td class="py-1">
                                <Chip :tone="statusTones[control.status]">{{
                                    statusLabels[control.status]
                                }}</Chip>
                            </td>
                            <td class="py-1">
                                <ul class="space-y-1">
                                    <li
                                        v-for="defence in control.defences"
                                        :key="defence.uuid"
                                        class="text-text-secondary flex flex-wrap items-center gap-1.5"
                                    >
                                        <span>{{ defence.title }}</span>
                                        <StatusPill :status="defence.status" />
                                        <span
                                            v-if="defence.attackCount === 0"
                                            class="text-text-muted italic"
                                            >counters no attack</span
                                        >
                                    </li>
                                </ul>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </article>
        </div>
    </section>
</template>
