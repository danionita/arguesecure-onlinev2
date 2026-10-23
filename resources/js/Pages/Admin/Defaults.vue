<script setup lang="ts">
/**
 * The admin **Defaults** tab (W87, item 10, PLAN-2.5.md D47).
 *
 * What a new assessment starts with: the currency and the scales (W126). A
 * scale is a named set of impact, likelihood and strength bands; each is a
 * card with its own Save (`AdminScaleCard`), and the built-in two can be edited
 * but not renamed or deleted. Separate from Instance settings, which is about
 * the deployment rather than about content.
 *
 * ## Defaults, not global settings (2.5.1)
 *
 * Assessments are given their own copy of the bands as they were before an
 * edit (SystemScaleCalibrator), so a change here reaches only assessments
 * created from a scale afterwards, and assessments that pick it again in
 * their settings.
 */
import AdminScaleCard from '@/Components/Scales/AdminScaleCard.vue';
import type { ScaleSetDetail } from '@/Components/Scales/bands';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Select from '@/Components/UI/Select.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    defaultCurrency: string;
    currencies: Record<string, string>;
    scaleSets: ScaleSetDetail[];
    topBandMultiplier: number;
    missingKinds: string[];
    /** See Assessment/Settings.vue — the flash bag is not a shared prop. */
    flashSuccess: string | null;
    errors: Record<string, string>;
}>();

const currencyForm = useForm({ default_currency: props.defaultCurrency });

const currencyOptions = computed(() =>
    Object.entries(props.currencies).map(([code, name]) => ({ code, name })),
);

const newScale = useForm({
    name: '',
    from: props.scaleSets[0]?.key ?? 'default',
});

useUnsavedChanges(() => currencyForm.isDirty || newScale.isDirty);

const fromOptions = computed(() =>
    props.scaleSets.map((set) => ({ value: set.key, label: set.name })),
);

function createScale() {
    newScale.post(route('admin.scales.store'), {
        preserveScroll: true,
        onSuccess: () => newScale.reset('name'),
    });
}

function saveCurrency() {
    currencyForm.put(route('admin.defaults.currency'), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Admin — Defaults" />

    <AdminLayout title="Defaults">
        <p class="text-text-muted mb-6 max-w-2xl text-sm">
            What a new assessment starts with. These are not deployment settings
            — those are on
            <strong class="text-text-secondary">Instance settings</strong>.
        </p>

        <!--
            What the last save did. It carries the count of stored values a
            removed band emptied — and here those values belong to other
            people's assessments, which is precisely why it must be said.
        -->
        <p
            v-if="flashSuccess"
            class="border-status-implemented-border bg-status-implemented-bg text-text-secondary mb-6 max-w-2xl rounded-lg border p-3 text-sm"
            data-testid="defaults-flash"
        >
            {{ flashSuccess }}
        </p>

        <section class="mb-8 max-w-lg">
            <h3 class="text-text-primary mb-1 text-sm font-semibold">
                Default currency
            </h3>
            <p class="text-text-muted mb-2 text-xs">
                Applied to assessments created from now on. Existing assessments
                keep the currency they were created with, and changing a
                currency never converts a figure.
            </p>

            <div class="flex items-end gap-2">
                <select
                    v-model="currencyForm.default_currency"
                    aria-label="Default currency"
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
                <Button
                    variant="primary"
                    :loading="currencyForm.processing"
                    data-testid="save-currency"
                    @click="saveCurrency"
                >
                    Save
                </Button>
            </div>
            <p
                v-if="currencyForm.errors.default_currency"
                class="text-node-attack-text mt-1 text-xs"
                data-testid="currency-error"
            >
                {{ currencyForm.errors.default_currency }}
            </p>
        </section>

        <section>
            <h3 class="text-text-primary mb-1 text-sm font-semibold">Scales</h3>
            <p
                class="text-text-muted mb-3 max-w-2xl text-xs"
                data-testid="defaults-bands-note"
            >
                A scale is a named set of impact, likelihood and strength bands.
                Each new assessment starts from one scale you choose when you
                create it. Changing a scale here never changes assessments that
                already exist.
            </p>

            <p
                v-if="missingKinds.length > 0"
                class="border-danger-border bg-danger-bg text-danger-text mb-4 max-w-2xl rounded-lg border p-3 text-xs"
                data-testid="incomplete-set"
            >
                The Standard scale is missing its
                {{ missingKinds.join(', ') }} bands. Run the system scale seeder
                — an incomplete scale cannot start an assessment.
            </p>

            <AdminScaleCard
                v-for="set in scaleSets"
                :key="set.key"
                :set="set"
                :currency="currencyForm.default_currency"
                :top-band-multiplier="topBandMultiplier"
                :errors="errors"
            />

            <form
                class="border-border-subtle mb-8 flex max-w-xl flex-wrap items-end gap-3 rounded-lg border p-4"
                data-testid="new-scale"
                @submit.prevent="createScale"
            >
                <Input
                    v-model="newScale.name"
                    label="New scale"
                    placeholder="Name"
                    :error="newScale.errors.name"
                    required
                />
                <Select
                    v-model="newScale.from"
                    :options="fromOptions"
                    label="Start from"
                />
                <Button
                    type="submit"
                    variant="primary"
                    :loading="newScale.processing"
                >
                    Create scale
                </Button>
            </form>
        </section>
    </AdminLayout>
</template>
