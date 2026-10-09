<script setup lang="ts">
/**
 * The full assessment, indented, with everything (W33 — PLAN.md Phase 7 §7).
 *
 * This is the report's answer to the editor's list view, and after W33 it
 * carries what the list carries and more: for every node, the entered values
 * *and* the computed ones, under inherent, current and target. The 2.0
 * version printed a title and two ALEs, which made it a table of contents
 * rather than the record of the assessment — and the record is the point,
 * because this is the section a reader goes to when they want to know why a
 * figure upstream is what it is.
 *
 * One indentation level per DAG depth (risk -> attack -> defence). A shared
 * attack or defence is deliberately printed once per parent edge it actually
 * has, not deduplicated: that repetition is what makes a shared node's
 * contribution to each parent visible on paper the same way duplicate
 * highlighting does on screen.
 *
 * Missing values print as `—`, never as `0` (calculation-engine.md: a missing
 * impact or likelihood excludes the pair from the total rather than counting
 * as nothing).
 *
 * Assignee names (W46) arrive already empty unless the **Show assignees**
 * option is on — the assembler decides that, not this component, so there is
 * one place where "does this report name people?" is answered and the JSON
 * export cannot disagree with the page. A node with nobody on it renders
 * nothing either way, so an empty list and a switched-off option look the
 * same here on purpose.
 *
 * ## Search highlighting (W83)
 *
 * `activeMatchKey` is `useReportSearch.ts`'s `activeMatch.value?.pathKey` —
 * built the same way this template's own `:key`s are (`risk.uuid`,
 * `${risk.uuid}/${attack.uuid}`, `${attack.uuid}/${defence.uuid}`), so a row
 * here can compare directly against it. The ring is the same
 * `ring-focus-ring ring-2` `NodeCard.vue` uses for its own `isSearchMatch`
 * (ui.md §5) — one visual language for "this is where the search landed",
 * on the tree and on its paper equivalent alike.
 */
import Chip from '../UI/Chip.vue';
import Money from '../UI/Money.vue';
import Percent from '../UI/Percent.vue';
import StatusPill from '../UI/StatusPill.vue';
import type { ReportBaseline, ReportTree, TreeDefenceRow } from './types';

const props = withDefaults(
    defineProps<{
        tree: ReportTree;
        baseline: ReportBaseline;
        currency: string;
        /** `search.activeMatch.value?.pathKey ?? null` from useReportSearch.ts. */
        activeMatchKey?: string | null;
    }>(),
    { activeMatchKey: null },
);

const showCurrent = props.baseline !== 'target';
const showTarget = props.baseline !== 'current';

const REDUCTION_TARGET_LABELS: Record<string, string> = {
    likelihood: 'likelihood',
    impact: 'impact',
};

function reductionLabel(defence: TreeDefenceRow): string {
    const noun = defence.reductionTarget
        ? REDUCTION_TARGET_LABELS[defence.reductionTarget]
        : 'overall loss';

    return `Reduces ${noun}`;
}
</script>

<template>
    <section class="report-section" aria-labelledby="tree-heading">
        <h2 id="tree-heading" class="text-text-primary text-xl font-semibold">
            Full assessment
        </h2>
        <p class="text-text-muted mt-1 text-xs">
            Every node with every figure. Inherent is before any defence;
            current counts the implemented ones; target adds the planned ones. A
            dash means no value was entered — such a pair is left out of the
            totals rather than counted as zero.
        </p>

        <ul class="mt-4 space-y-4">
            <li
                v-for="risk in tree.risks"
                :key="risk.uuid"
                class="break-inside-avoid rounded"
                :class="
                    activeMatchKey === risk.uuid && 'ring-focus-ring ring-2'
                "
            >
                <!-- Risk -->
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <span class="text-node-risk-text font-semibold">
                        {{ risk.title }}
                    </span>
                    <span
                        v-if="risk.assignees.length"
                        class="text-text-muted text-xs"
                        >{{ risk.assignees.join(', ') }}</span
                    >
                    <span
                        class="text-text-muted flex gap-3 text-xs tabular-nums"
                    >
                        <span>
                            impact
                            <Money
                                :value="risk.impactAmount"
                                :currency="currency"
                            />
                            <template v-if="risk.impactMode === 'qualitative'">
                                (qualitative)
                            </template>
                        </span>
                        <span>
                            inherent
                            <Money
                                :value="risk.inherent?.ale ?? null"
                                :currency="currency"
                            />
                        </span>
                        <span v-if="showCurrent">
                            current
                            <Money
                                :value="risk.current?.ale ?? null"
                                :currency="currency"
                            />
                        </span>
                        <span v-if="showTarget">
                            target
                            <Money
                                :value="risk.target?.ale ?? null"
                                :currency="currency"
                            />
                        </span>
                    </span>
                </div>
                <p
                    v-if="risk.description"
                    class="text-text-secondary mt-0.5 text-xs"
                >
                    {{ risk.description }}
                </p>

                <!-- Attacks -->
                <ul class="mt-2 space-y-2 pl-4">
                    <li
                        v-for="attack in risk.attacks"
                        :key="`${risk.uuid}/${attack.uuid}`"
                        class="break-inside-avoid rounded"
                        :class="
                            activeMatchKey === `${risk.uuid}/${attack.uuid}` &&
                            'ring-focus-ring ring-2'
                        "
                    >
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <span class="text-node-attack-text font-medium">
                                {{ attack.title }}
                            </span>
                            <span
                                v-if="attack.assignees.length"
                                class="text-text-muted text-xs"
                                >{{ attack.assignees.join(', ') }}</span
                            >
                            <span
                                class="text-text-muted flex gap-3 text-xs tabular-nums"
                            >
                                <span>
                                    likelihood
                                    <template
                                        v-if="attack.likelihoodAro !== null"
                                    >
                                        {{ attack.likelihoodAro.toFixed(2) }}/yr
                                    </template>
                                    <template v-else>—</template>
                                </span>
                                <span v-if="showCurrent">
                                    residual
                                    <template
                                        v-if="
                                            attack.current?.aroResidual != null
                                        "
                                    >
                                        {{
                                            attack.current.aroResidual.toFixed(
                                                2,
                                            )
                                        }}/yr
                                    </template>
                                    <template v-else>—</template>
                                </span>
                                <span>
                                    inherent
                                    <Money
                                        :value="attack.inherent?.ale ?? null"
                                        :currency="currency"
                                    />
                                </span>
                                <span v-if="showCurrent">
                                    current
                                    <Money
                                        :value="attack.current?.ale ?? null"
                                        :currency="currency"
                                    />
                                </span>
                                <span v-if="showTarget">
                                    target
                                    <Money
                                        :value="attack.target?.ale ?? null"
                                        :currency="currency"
                                    />
                                </span>
                            </span>
                        </div>
                        <p
                            v-if="attack.description"
                            class="text-text-secondary mt-0.5 text-xs"
                        >
                            {{ attack.description }}
                        </p>

                        <!-- Defences on this attack -->
                        <ul class="mt-1 space-y-1.5 pl-4">
                            <li
                                v-for="defence in attack.defences"
                                :key="`${attack.uuid}/${defence.uuid}`"
                                class="break-inside-avoid rounded text-xs"
                                :class="
                                    activeMatchKey ===
                                        `${attack.uuid}/${defence.uuid}` &&
                                    'ring-focus-ring ring-2'
                                "
                            >
                                <div
                                    class="flex flex-wrap items-baseline gap-2"
                                >
                                    <span class="text-node-defence-text">
                                        {{ defence.title }}
                                    </span>
                                    <span
                                        v-if="defence.assignees.length"
                                        class="text-text-muted text-xs"
                                        >{{
                                            defence.assignees.join(', ')
                                        }}</span
                                    >
                                    <StatusPill :status="defence.status" />
                                    <Chip
                                        v-if="defence.isTransfer"
                                        tone="transfer"
                                        >Transfer</Chip
                                    >
                                    <span class="text-text-muted tabular-nums">
                                        {{ reductionLabel(defence) }} by
                                        <Percent :value="defence.strength" />
                                    </span>
                                </div>

                                <div
                                    class="text-text-muted mt-0.5 flex flex-wrap gap-3 tabular-nums"
                                >
                                    <span>
                                        one-off
                                        <Money
                                            :value="defence.capexAmount"
                                            :currency="currency"
                                        />
                                    </span>
                                    <span>
                                        recurring
                                        <Money
                                            :value="defence.opexAnnualAmount"
                                            :currency="currency"
                                        />/yr
                                    </span>
                                    <span>
                                        annualised
                                        <Money
                                            :value="
                                                defence.target?.annualCost ??
                                                defence.current?.annualCost ??
                                                null
                                            "
                                            :currency="currency"
                                        />/yr
                                    </span>
                                    <span v-if="showCurrent">
                                        saves now
                                        <Money
                                            :value="
                                                defence.current?.mc ??
                                                defence.current?.mcAdd ??
                                                null
                                            "
                                            :currency="currency"
                                        />/yr
                                    </span>
                                    <span v-if="showTarget">
                                        saves at target
                                        <Money
                                            :value="
                                                defence.target?.mc ??
                                                defence.target?.mcAdd ??
                                                null
                                            "
                                            :currency="currency"
                                        />/yr
                                    </span>
                                </div>

                                <p
                                    v-if="defence.rationale"
                                    class="text-text-secondary mt-0.5 italic"
                                >
                                    {{ defence.rationale }}
                                </p>
                                <p
                                    v-else-if="defence.description"
                                    class="text-text-secondary mt-0.5"
                                >
                                    {{ defence.description }}
                                </p>
                            </li>
                        </ul>
                    </li>
                </ul>
            </li>
        </ul>

        <div
            v-if="tree.orphanAttacks.length || tree.orphanDefences.length"
            class="mt-6 break-inside-avoid"
        >
            <h3 class="text-text-primary text-sm font-semibold">
                Not linked into the assessment
            </h3>
            <p class="text-text-muted text-xs">
                These exist but contribute to no total, because nothing connects
                them.
            </p>
            <ul class="text-text-secondary mt-1 space-y-0.5 text-xs">
                <li v-for="attack in tree.orphanAttacks" :key="attack.uuid">
                    Attack — {{ attack.title }}
                </li>
                <li v-for="defence in tree.orphanDefences" :key="defence.uuid">
                    Defence — {{ defence.title }}
                </li>
            </ul>
        </div>
    </section>
</template>
