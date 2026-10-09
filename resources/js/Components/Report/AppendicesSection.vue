<script setup lang="ts">
/**
 * Appendices (PLAN.md Phase 7 §8): references, comments if requested,
 * methodology and formulas, and the engine's model-quality warnings
 * (calculation-engine.md §12). Methodology text is static and normative —
 * the independence assumption and the per-occurrence definition of impact
 * are quoted close to verbatim from calculation-engine.md §7 and §1, since
 * ui.md §9 requires exactly those two points be stated.
 */
import { computed } from 'vue';
import Icon from '../UI/Icon.vue';
import type { ReportAppendices, ReportOptions } from './types';

const props = defineProps<{
    appendices: ReportAppendices;
    options: ReportOptions;
    /**
     * W54. True when the written executive summary above was drafted by a
     * model. The disclaimer below is the long form of the mark printed beside
     * the summary itself — both print, so a report whose methodology appendix
     * is switched off still discloses it at the point of use.
     */
    summaryGenerated?: boolean;
}>();

// Appendix letters are sequential and skip whichever optional sections
// (comments, methodology) are switched off, so "A. References" is always
// first and the warnings appendix always lands on the next free letter.
const letters = computed(() => {
    let code = 'A'.charCodeAt(0);
    const next = () => String.fromCharCode(code++);
    const references = next();
    const comments = props.options.includeComments ? next() : null;
    const methodology = props.options.includeMethodology ? next() : null;
    const warnings = next();

    return { references, comments, methodology, warnings };
});
</script>

<template>
    <section class="report-section" aria-labelledby="appendices-heading">
        <h2
            id="appendices-heading"
            class="text-text-primary text-xl font-semibold"
        >
            Appendices
        </h2>

        <div class="mt-4 break-inside-avoid">
            <h3 class="text-text-primary text-sm font-semibold">
                {{ letters.references }}. References
            </h3>
            <p
                v-if="appendices.references.length === 0"
                class="text-text-muted text-xs"
            >
                No references recorded.
            </p>
            <table v-else class="mt-1 w-full text-left text-xs">
                <thead>
                    <tr class="text-text-muted border-border-subtle border-b">
                        <th class="py-1 font-medium">Node</th>
                        <th class="py-1 font-medium">Kind</th>
                        <th class="py-1 font-medium">Identifier</th>
                        <th class="py-1 font-medium">Link</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(ref, i) in appendices.references"
                        :key="i"
                        class="border-border-subtle border-b last:border-0"
                    >
                        <td class="text-text-secondary py-1">
                            {{ ref.nodeTitle }}
                        </td>
                        <td class="py-1 uppercase">{{ ref.kind }}</td>
                        <td class="py-1">{{ ref.identifier }}</td>
                        <td class="py-1">
                            <!-- Expanded link URLs after their text (ui.md §9 print rule). -->
                            <a
                                v-if="ref.url"
                                :href="ref.url"
                                rel="noopener nofollow"
                                class="text-text-link underline"
                                >{{ ref.url }}</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="options.includeComments" class="mt-6 break-inside-avoid">
            <h3 class="text-text-primary text-sm font-semibold">
                {{ letters.comments }}. Comments
            </h3>
            <p
                v-if="appendices.comments.length === 0"
                class="text-text-muted text-xs"
            >
                No comments recorded.
            </p>
            <ul v-else class="mt-1 space-y-2">
                <li
                    v-for="(comment, i) in appendices.comments"
                    :key="i"
                    class="border-border-subtle rounded border p-2 text-xs"
                >
                    <div class="text-text-muted flex items-center gap-2">
                        <span class="font-medium">{{ comment.nodeTitle }}</span>
                        <span>· {{ comment.author ?? 'Unknown' }}</span>
                        <span v-if="comment.resolved">· resolved</span>
                    </div>
                    <p class="text-text-primary mt-0.5">{{ comment.body }}</p>
                    <ul
                        v-if="comment.replies.length"
                        class="border-border-subtle mt-1 ml-3 space-y-1 border-l pl-2"
                    >
                        <li v-for="(reply, j) in comment.replies" :key="j">
                            <span class="text-text-muted font-medium"
                                >{{ reply.author ?? 'Unknown' }}:</span
                            >
                            {{ reply.body }}
                        </li>
                    </ul>
                </li>
            </ul>
        </div>

        <div v-if="options.includeMethodology" class="mt-6 break-inside-avoid">
            <h3 class="text-text-primary text-sm font-semibold">
                {{ letters.methodology }}. Methodology and formulas
            </h3>
            <div class="text-text-secondary mt-1 space-y-2 text-xs">
                <!--
                    W54. First in the methodology appendix, not last: a reader
                    deciding how much weight to give this document needs to
                    know which parts of it a machine wrote before they read the
                    formulas, not after.
                -->
                <p v-if="summaryGenerated" data-testid="generated-disclaimer">
                    <strong>Machine-drafted text.</strong> The executive summary
                    in this report was drafted by a language model running on
                    the server that produced this document, from the assessment
                    data shown here, and was then reviewed and edited by the
                    author before publication. No other section of this report
                    is machine-written; every figure is computed by the
                    calculation engine described below. The author is
                    responsible for the contents of the summary.
                </p>
                <p>
                    <strong
                        >ALE(risk, attack, S) = ARO(attack) × Impact(risk) × Π
                        over defences d ∈ S of (1 − strength(d, attack))</strong
                    >, summed over every valued (risk, attack) pair for the
                    total.
                </p>
                <p>
                    <strong
                        >Impact is loss per occurrence, not annual loss.</strong
                    >
                    <strong
                        >Likelihood (ARO) is a rate of occurrences per
                        year</strong
                    >, not a probability, and may exceed 1.
                </p>
                <p>
                    Three baselines are computed: <em>inherent</em> (no
                    defences), <em>current</em> (implemented controls only) and
                    <em>target</em> (implemented plus planned). Shelved defences
                    never enter a total.
                </p>
                <p>
                    Multiple defences on one attack combine by
                    <strong>independent multiplicative composition</strong>:
                    residual = original × Π(1 − sᵢ). This assumes the controls
                    fail independently, which flatters defence-in-depth — two
                    controls that both fail when the same account is compromised
                    are not independent in reality. This assumption is not
                    modelled.
                </p>
                <p>
                    A missing impact or likelihood excludes that pair from the
                    total (never coerced to zero, which would understate risk).
                    A missing defence strength is treated as zero reduction
                    (overstates residual risk — the safe direction).
                </p>
                <p>
                    Defence rankings use
                    <strong>marginal contribution</strong> ("if removed") for
                    implemented controls and the
                    <strong>addition delta</strong> ("if implemented") for
                    planned ones. These removal/addition deltas are not additive
                    across defences; the "share of reduction" chart instead uses
                    <strong>log-share attribution</strong>, the metric that does
                    sum exactly to the total reduction.
                </p>
                <p>
                    Computed loss figures are shown as currency, never mapped to
                    a low/medium/high band.
                </p>
            </div>
        </div>

        <div class="mt-6 break-inside-avoid">
            <h3 class="text-text-primary text-sm font-semibold">
                {{ letters.warnings }}. Model-quality warnings
            </h3>
            <p
                v-if="appendices.warnings.length === 0"
                class="text-text-muted text-xs"
            >
                No model-quality warnings.
            </p>
            <ul v-else class="mt-1 space-y-1">
                <li
                    v-for="(warning, i) in appendices.warnings"
                    :key="i"
                    class="text-status-todo-text flex items-start gap-1.5 text-xs"
                >
                    <Icon
                        name="alert-circle"
                        :size="12"
                        class="mt-0.5 shrink-0"
                    />
                    <span
                        ><span v-if="warning.nodeTitle" class="font-medium"
                            >{{ warning.nodeTitle }}:</span
                        >
                        {{ warning.message }}</span
                    >
                </li>
            </ul>
        </div>
    </section>
</template>
