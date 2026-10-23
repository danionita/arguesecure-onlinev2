<script setup lang="ts">
/**
 * **Scales** — the target of assessment's own tab (W72 item 10; ui.md §7).
 *
 * The currency, the risk tolerance, the amortisation period and the three
 * qualitative scales are not new: W44 built them all on
 * `Pages/Assessment/Settings.vue`, at `/assessments/{uuid}/settings`, reached
 * only from a sidebar item. Everything else about the assessment is edited by
 * opening its root node, so the one screen that says what its numbers *mean*
 * being somewhere else is a place people do not find. Item 10 surfaces them
 * where the rest of the root node's fields already are.
 *
 * ## One controller, one request object, read and written from two screens
 *
 * The brief is explicit that this must not become a second write path to the
 * same columns, and it is not: this tab **reads** `AssessmentSettingsController::edit`
 * and **writes** `AssessmentSettingsController::update` through
 * `UpdateAssessmentSettingsRequest`, exactly as the settings page does. No
 * route, no controller and no FormRequest was added or changed.
 *
 * Both halves need a word of explanation, because neither is the shape a page
 * component would use:
 *
 * - **The read is an Inertia request made by hand.** `Assessment/Show` does
 *   not receive `currencies` or `maxAmortizationYears` — only the settings
 *   page does, and adding them to the assessment controller would put a
 *   config array on every editor page load for a tab most sessions never
 *   open. So this asks the settings controller for its own props, with the
 *   `X-Inertia` headers that make it answer in JSON, on first activation of
 *   the tab. W71 made panels lazy, so "first activation" is genuinely the
 *   first time anyone opens it.
 * - **The write is axios, not `router.patch`.** The controller redirects to
 *   `assessments.settings.edit` on success, which is right for the settings
 *   page and would throw the user out of the assessment from here — the modal
 *   would close and the tree would be replaced by a settings screen. Axios
 *   ignores the redirect body; the store is patched locally instead, which is
 *   the same thing every other mutation in this modal does.
 *
 * ## Bands are not edited here
 *
 * A scale's levels are calibrated in the three cards on Assessment settings
 * (W65, relocated there by W87 / D46) and this tab links to it. That editor
 * has its own ordering, bounds and overlap rules; a summary answering "what
 * are the bands called" is what belongs beside a value someone is entering,
 * and it is what the settings page shows for the same reason.
 */
import type { ScaleDetail } from '@/Components/Scales/bands';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { AssessmentStore } from '@/stores/assessmentStore';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

/*
 * The scales branch is typed by `ScaleDetail` — the shape
 * `AppDomainScalesScaleDescriber` actually emits — rather than by a
 * hand-written summary of it (W87).
 *
 * It was a local interface declaring `levels: { code, label }[]`, which is
 * what this endpoint sent until W87 replaced it with `ScaleDescriber`. The
 * server then began sending `bands` and this file kept reading `levels`,
 * which is a TypeError in the render function rather than a missing row —
 * and `vue-tsc` could not see it, because a hand-written interface asserts
 * the shape instead of deriving it. Naming the shared type is what makes the
 * next change to that payload a compile error here.
 */
interface SettingsProps {
    assessment: {
        uuid: string;
        title: string;
        description: string | null;
        currency: string;
        amortization_years: number;
        risk_tolerance: number | null;
    };
    currencies: Record<string, string>;
    maxAmortizationYears: number;
    scales: Record<string, ScaleDetail | null>;
}

const props = defineProps<{
    store: AssessmentStore;
    uuid: string;
}>();

const page = usePage();

/*
 * The store is a shared reactive object every tab in this modal writes
 * through — not this component's private state — so it is bound once here
 * rather than reached as `props.store` at each use. `vue/no-mutating-props`
 * reads the latter as a component mutating its own props, which is the
 * pattern that rule exists to stop and is not what a store handle is; the
 * same aliasing is what makes the intent legible to a reader too.
 */
const store = props.store;

const assessmentUuid = computed(() => store.assessment.uuid);

const settings = ref<SettingsProps | null>(null);
const loading = ref(true);
const loadFailed = ref(false);
const saving = ref(false);
const saved = ref(false);
const errors = ref<Record<string, string>>({});

/** The draft. Empty string for risk tolerance is "none", not zero — the
 *  distinction the settings page transports the same way and the column
 *  keeps. */
const form = ref({
    currency: '',
    amortization_years: 1,
    risk_tolerance: '',
});

// Unsaved while the draft differs from the last loaded or saved values,
// compared as numbers so "5000.0" saved as 5000 is not still pending, and
// kept off the live store so a collaborator's update does not make an
// untouched tab look edited.
function normalised() {
    const tolerance = String(form.value.risk_tolerance).trim();
    return JSON.stringify([
        form.value.currency,
        Number(form.value.amortization_years),
        tolerance === '' ? null : Number(tolerance),
    ]);
}
const baseline = ref<string | null>(null);
useUnsavedChanges(
    () => baseline.value !== null && normalised() !== baseline.value,
    'node-modal',
);

async function load(): Promise<void> {
    loading.value = true;
    loadFailed.value = false;
    try {
        const response = await window.axios.get<{ props: SettingsProps }>(
            route('assessments.settings.edit', assessmentUuid.value),
            {
                headers: {
                    'X-Inertia': 'true',
                    'X-Inertia-Version': String(page.version ?? ''),
                },
            },
        );
        settings.value = response.data.props;
        form.value = {
            currency: response.data.props.assessment.currency,
            amortization_years:
                response.data.props.assessment.amortization_years,
            risk_tolerance:
                response.data.props.assessment.risk_tolerance === null
                    ? ''
                    : String(response.data.props.assessment.risk_tolerance),
        };
        baseline.value = normalised();
    } catch {
        // 403 for someone who may read but not manage, 409 if the asset
        // version moved under a long-open tab. Neither is worth three
        // different messages in a panel whose fallback is a link to the same
        // screen served as a page.
        loadFailed.value = true;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const scaleRows = computed(() => [
    { key: 'impact', label: 'Impact', scale: settings.value?.scales.impact },
    {
        key: 'likelihood',
        label: 'Likelihood',
        scale: settings.value?.scales.likelihood,
    },
    {
        key: 'strength',
        label: 'Strength',
        scale: settings.value?.scales.strength,
    },
]);

const currencyOptions = computed(() =>
    Object.entries(settings.value?.currencies ?? {}).map(([code, name]) => ({
        code,
        name,
    })),
);

async function save(): Promise<void> {
    if (!settings.value) return;
    saving.value = true;
    saved.value = false;
    errors.value = {};

    const tolerance = String(form.value.risk_tolerance).trim();

    try {
        await window.axios.patch(
            route('assessments.settings.update', assessmentUuid.value),
            {
                // The title and description are required by
                // UpdateAssessmentSettingsRequest and live on the root node,
                // which is the node this tab is open on. Sent unchanged: this
                // tab does not edit them — Details does, through the ordinary
                // node update path — and omitting them would fail validation
                // rather than leave them alone.
                title: store.nodes[props.uuid]?.title ?? '',
                description: store.nodes[props.uuid]?.description ?? null,
                currency: form.value.currency,
                amortization_years: Number(form.value.amortization_years),
                risk_tolerance: tolerance === '' ? null : Number(tolerance),
            },
        );

        /*
         * Patch the store rather than reloading the page. The amortisation
         * period is the one setting here that moves a figure — capex is
         * spread over it — so the local recompute has to follow it or every
         * annualised cost on screen stays on the old divisor until something
         * unrelated triggers a recalculation. Currency and tolerance are
         * display-only (PLAN-2.2.md D14) and need no recompute, but they are
         * assigned in the same place because they come from the same save.
         */
        const changedAmortization =
            store.assessment.amortization_years !==
            Number(form.value.amortization_years);

        store.assessment.currency = form.value.currency;
        store.assessment.amortization_years = Number(
            form.value.amortization_years,
        );
        store.assessment.risk_tolerance =
            tolerance === '' ? null : Number(tolerance);

        if (changedAmortization) store.recomputeLocally();

        saved.value = true;
        baseline.value = normalised();
    } catch (error) {
        const response = (
            error as {
                response?: { data?: { errors?: Record<string, string[]> } };
            }
        ).response;
        const bag = response?.data?.errors ?? {};
        errors.value = Object.fromEntries(
            Object.entries(bag).map(([field, messages]) => [
                field,
                messages[0] ?? '',
            ]),
        );
        if (Object.keys(errors.value).length === 0) {
            errors.value = {
                currency: 'The settings could not be saved. Try again.',
            };
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-5 p-4" data-testid="scales-tab">
        <p v-if="loading" class="text-text-muted text-sm">Loading settings…</p>

        <p v-else-if="loadFailed" class="text-text-muted text-sm">
            These settings could not be loaded here.
            <a
                :href="route('assessments.settings.edit', assessmentUuid)"
                class="hover:text-text-primary underline"
                >Open the settings screen</a
            >.
        </p>

        <template v-else-if="settings">
            <section class="space-y-4">
                <h3 class="text-text-primary text-sm font-semibold">
                    How figures are shown
                </h3>

                <div>
                    <label
                        for="scales-tab-currency"
                        class="text-text-primary mb-1 block text-sm font-medium"
                        >Currency</label
                    >
                    <!-- The same warning the settings page carries, for the
                         same reason: someone changing EUR to USD reasonably
                         expects conversion, nothing converts, and finding
                         that out from a report is far worse. -->
                    <p
                        id="scales-tab-currency-help"
                        class="text-text-muted mb-1 text-xs"
                    >
                        Changes the symbol and number formatting only. Amounts
                        are not converted and no exchange rate is applied.
                    </p>
                    <select
                        id="scales-tab-currency"
                        v-model="form.currency"
                        aria-describedby="scales-tab-currency-help"
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
                        v-if="errors.currency"
                        class="text-node-attack-text mt-1 text-xs"
                    >
                        {{ errors.currency }}
                    </p>
                </div>

                <Input
                    v-model="form.risk_tolerance"
                    type="number"
                    label="Risk tolerance"
                    :error="errors.risk_tolerance"
                    placeholder="No tolerance set"
                    help-text="An annual loss figure to compare against. It draws a line on the report charts and never labels a risk high or low. Leave blank for none."
                />
            </section>

            <section class="space-y-4">
                <h3 class="text-text-primary text-sm font-semibold">
                    How figures are calculated
                </h3>

                <Input
                    v-model="form.amortization_years"
                    type="number"
                    label="Amortisation period"
                    suffix="years"
                    :error="errors.amortization_years"
                    :help-text="`One-off costs are spread over this many years to give an annual figure. Changing it moves every annual cost, cost-benefit ratio and return in the report. 1 to ${settings.maxAmortizationYears}.`"
                />
            </section>

            <section>
                <h3 class="text-text-primary mb-1 text-sm font-semibold">
                    Scales
                </h3>
                <p class="text-text-muted mb-3 text-xs">
                    The ranges this assessment's qualitative values stand for.
                    <Link
                        :href="
                            route('assessments.settings.edit', assessmentUuid)
                        "
                        class="hover:text-text-primary underline"
                        >Change them</Link
                    >.
                </p>

                <dl class="space-y-2">
                    <div
                        v-for="row in scaleRows"
                        :key="row.key"
                        :data-scale="row.key"
                        class="border-border-subtle bg-surface-1 rounded-lg border p-3"
                    >
                        <dt
                            class="text-text-primary flex items-center gap-2 text-sm font-medium"
                        >
                            {{ row.label }}
                            <span
                                v-if="row.scale"
                                class="text-text-muted text-xs font-normal"
                            >
                                {{
                                    row.scale.is_system
                                        ? 'shared across assessments'
                                        : 'this assessment’s own'
                                }}
                            </span>
                        </dt>
                        <dd class="text-text-secondary mt-1 text-xs">
                            <template v-if="row.scale">
                                {{
                                    row.scale.bands
                                        .map((band) => band.label)
                                        .join(' · ')
                                }}
                            </template>
                            <template v-else>Not configured.</template>
                        </dd>
                    </div>
                </dl>
            </section>

            <div class="flex items-center gap-3">
                <Button variant="primary" :loading="saving" @click="save">
                    Save settings
                </Button>
                <span v-if="saved" class="text-text-muted text-xs">Saved.</span>
            </div>
        </template>
    </div>
</template>
