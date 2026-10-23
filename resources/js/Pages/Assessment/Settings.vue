<script setup lang="ts">
/**
 * Assessment settings (W44, item 12; ui.md §8).
 *
 * One screen for the things that were scattered or absent. The amortisation
 * period and the currency have been columns on `assessments` since the
 * original schema with no control anywhere in the application; the title and
 * description were editable only by clicking the root node in the tree; a
 * risk tolerance did not exist.
 *
 * ## Two of these are display-only, and the screen says so
 *
 * Currency and risk tolerance change how figures are *presented* and never
 * what they are (PLAN-2.2.md D14). That is easy to misread as "the tool will
 * convert my numbers" or "the tool will tell me which risks are acceptable",
 * and either misreading produces a report someone acts on. So each says what
 * it does in the field's own help text rather than in documentation nobody
 * opens: no conversion, no rates; a line on a chart, not a verdict.
 *
 * The amortisation period is the one setting here that *does* move a figure —
 * capital cost is spread over it — so it is grouped apart from the two that
 * do not, and its help text says which figures move.
 *
 * ## No title or description here (W86, item 9)
 *
 * They live on the root node and are edited there, in the tree. This screen
 * used to carry its own copies, which is the two-editors-for-one-field
 * mistake CLAUDE.md's history rules warn about — and the write actually went
 * through `NodeObserver`/`RevisionRecorder` like any other node edit, so the
 * history page was recording this screen's title/description saves from a
 * second place on top of the tree's own. `UpdateAssessmentSettingsRequest`
 * now `prohibited`s both fields at the request level, not just here — a
 * removed input is not a removed endpoint.
 */
import AssessmentScales from '@/Components/Scales/AssessmentScales.vue';
import type { ScaleDetail, ScaleSetDetail } from '@/Components/Scales/bands';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    assessment: {
        uuid: string;
        currency: string;
        amortization_years: number;
        risk_tolerance: number | null;
    };
    currencies: Record<string, string>;
    maxAmortizationYears: number;
    /**
     * The three scales in full, not the read-only summary this page carried
     * until W87. They are edited here now (D46) — the calibration screen and
     * its route are gone, because a screen that still answers its URL after
     * its entry points are removed is how a second, divergent editor survives
     * a consolidation.
     */
    scales: Record<string, ScaleDetail | null>;
    /** Every admin-defined scale, in full (W126), and the one these bands were last set to. */
    scaleSets: ScaleSetDetail[];
    scaleSetKey: string | null;
    topBandMultiplier: number;
    /**
     * What the last scales save did, including how many stored values
     * a removed band emptied. A prop rather than a shared flash: the flash bag
     * is not shared to Inertia, so `->with('success', …)` alone would never
     * reach this page.
     */
    flashSuccess: string | null;
    errors: Record<string, string>;
}>();

const form = useForm({
    currency: props.assessment.currency,
    amortization_years: props.assessment.amortization_years,
    // An empty box means "no tolerance", which is a different statement from
    // a tolerance of zero — so it is transported as an empty string here and
    // nulled on submit rather than defaulting to 0.
    risk_tolerance:
        props.assessment.risk_tolerance === null
            ? ''
            : String(props.assessment.risk_tolerance),
});

useUnsavedChanges(() => form.isDirty);

const currencyOptions = computed(() =>
    Object.entries(props.currencies).map(([code, name]) => ({ code, name })),
);

const scalesUrl = computed(() =>
    route('assessments.scales.update', { assessment: props.assessment.uuid }),
);

function submit() {
    form.transform((data) => ({
        ...data,
        risk_tolerance:
            String(data.risk_tolerance).trim() === ''
                ? null
                : Number(data.risk_tolerance),
    })).patch(route('assessments.settings.update', props.assessment.uuid), {
        preserveScroll: true,
    });
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Assessment settings" />

        <div class="mx-auto max-w-3xl px-4 py-8">
            <div class="mb-6 flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-text-primary text-xl font-semibold">
                        Assessment settings
                    </h1>
                    <p class="text-text-muted text-sm">
                        Applies to everyone working on this assessment.
                    </p>
                </div>

                <!--
                    Top right (item 9): the title and description this screen
                    used to edit live on the root node now, in the tree — so
                    "go edit the name" is one click from here, not a second
                    editor on this page.
                -->
                <a
                    :href="route('assessments.show', assessment.uuid)"
                    class="text-text-muted hover:text-text-primary shrink-0 text-sm hover:underline"
                >
                    Return to assessment
                </a>
            </div>

            <form class="space-y-8" @submit.prevent="submit">
                <section class="space-y-4">
                    <h2 class="text-text-primary text-sm font-semibold">
                        How figures are shown
                    </h2>

                    <div>
                        <label
                            for="settings-currency"
                            class="text-text-primary mb-1 block text-sm font-medium"
                            >Currency</label
                        >
                        <!-- The help text is the whole point of this control
                             being here rather than assumed. Someone changing
                             EUR to USD reasonably expects their numbers to be
                             converted; nothing converts them, and finding that
                             out from a report is far worse than reading it
                             here. -->
                        <p
                            id="settings-currency-help"
                            class="text-text-muted mb-1 text-xs"
                        >
                            Changes the symbol and number formatting only.
                            Amounts are not converted and no exchange rate is
                            applied — the figures stay exactly as they were
                            entered.
                        </p>
                        <select
                            id="settings-currency"
                            v-model="form.currency"
                            aria-describedby="settings-currency-help"
                            class="border-border-default bg-surface-1 text-text-primary focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md text-sm shadow-sm"
                        >
                            <option
                                v-for="option in currencyOptions"
                                :key="option.code"
                                :value="option.code"
                            >
                                {{ option.code }} — {{ option.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.currency"
                            class="text-node-attack-text mt-1 text-xs"
                        >
                            {{ form.errors.currency }}
                        </p>
                    </div>

                    <Input
                        v-model="form.risk_tolerance"
                        type="number"
                        label="Risk tolerance"
                        :error="form.errors.risk_tolerance"
                        placeholder="No tolerance set"
                        help-text="An annual loss figure to compare against. It draws a line on the report charts and a badge on the assessment card. It never changes a computed figure and never labels a risk high or low — that judgement stays yours. Leave blank for none."
                    />
                </section>

                <section class="space-y-4">
                    <h2 class="text-text-primary text-sm font-semibold">
                        How figures are calculated
                    </h2>

                    <Input
                        v-model="form.amortization_years"
                        type="number"
                        label="Amortisation period"
                        required
                        suffix="years"
                        :error="form.errors.amortization_years"
                        :help-text="`One-off costs are spread over this many years to give an annual figure. Changing it moves every annual cost, cost-benefit ratio and return in the report. 1 to ${maxAmortizationYears}.`"
                    />
                </section>

                <div class="flex items-center gap-3">
                    <Button
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                    >
                        Save settings
                    </Button>
                </div>
            </form>

            <!--
                Outside the form above, and deliberately: the scales save on
                their own request, so nesting them in the settings form would
                tie a change of bands to a currency change.
            -->
            <section class="mt-10">
                <h2 class="text-text-primary mb-1 text-sm font-semibold">
                    Scales
                </h2>
                <p class="text-text-muted mb-4 text-xs">
                    The ranges that your impact, likelihood and strength choices
                    stand for. Pick a scale from the list to load it, or change
                    any band yourself and the list switches to Custom. Nothing
                    is stored until you press Save.
                </p>

                <!--
                    What the last save did. It carries what a user has to be
                    told rather than left to notice: how many stored values a
                    removed band emptied — someone spent a workshop arriving
                    at those.
                -->
                <p
                    v-if="flashSuccess"
                    class="border-status-implemented-border bg-status-implemented-bg text-text-secondary mb-4 rounded-lg border p-3 text-sm"
                    data-testid="scales-flash"
                >
                    {{ flashSuccess }}
                </p>

                <AssessmentScales
                    :scales="scales"
                    :scale-sets="scaleSets"
                    :scale-set-key="scaleSetKey"
                    :save-url="scalesUrl"
                    :currency="assessment.currency"
                    :top-band-multiplier="topBandMultiplier"
                    :errors="errors"
                />
            </section>
        </div>
    </AuthenticatedLayout>
</template>
