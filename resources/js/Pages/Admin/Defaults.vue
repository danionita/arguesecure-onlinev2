<script setup lang="ts">
/**
 * The admin **Defaults** tab (W87, item 10, PLAN-2.5.md D47).
 *
 * What a new assessment starts with: the currency, and the impact, likelihood
 * and strength bands. Separate from Instance settings, which is about the
 * deployment rather than about content.
 *
 * It mounts `ScaleCards`, the same editor Assessment settings mounts, because
 * D47 says so in as many words: the admin "wants the same band editor the
 * assessment gets, not a different one". The only differences are the URLs it
 * is handed and the paragraph above it.
 *
 * ## Defaults, not global settings (2.5.1)
 *
 * Through 2.5 editing a band here moved the figures of every assessment that
 * had never calibrated its own scales, and a warning box said so. Now those
 * assessments are given their own copy of the bands as they were before the
 * edit (SystemScaleCalibrator), so a change here reaches only assessments
 * created afterwards and "Reset to default" — which is what a default is, and
 * what the copy under each heading now says, in the same words as the
 * currency's.
 *
 * **There is no "Reset to default" here.** This *is* the default. Offering one
 * would need a default behind the default, which does not exist — the seeder
 * is not a fallback the application can reach at run time.
 */
import type { ScaleDetail } from '@/Components/Scales/bands';
import ScaleCards from '@/Components/Scales/ScaleCards.vue';
import Button from '@/Components/UI/Button.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    defaultCurrency: string;
    currencies: Record<string, string>;
    scales: Record<string, ScaleDetail | null>;
    topBandMultiplier: number;
    missingKinds: string[];
    /** See Assessment/Settings.vue — the flash bag is not a shared prop. */
    flashSuccess: string | null;
    errors: Record<string, string>;
}>();

const KINDS = ['impact', 'likelihood', 'strength'] as const;

const currencyForm = useForm({ default_currency: props.defaultCurrency });

const currencyOptions = computed(() =>
    Object.entries(props.currencies).map(([code, name]) => ({ code, name })),
);

const saveUrls = computed(() =>
    Object.fromEntries(
        KINDS.map((kind) => [kind, route('admin.defaults.update', { kind })]),
    ),
);

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
            <h3 class="text-text-primary mb-1 text-sm font-semibold">
                Default bands
            </h3>
            <p
                class="text-text-muted mb-3 max-w-2xl text-xs"
                data-testid="defaults-bands-note"
            >
                Applied to assessments created from now on, and when an
                assessment's scale is reset to default. Existing assessments
                keep the bands they have, and changing a band here never changes
                an existing assessment's figures.
            </p>

            <p
                v-if="missingKinds.length > 0"
                class="border-danger-border bg-danger-bg text-danger-text mb-4 max-w-2xl rounded-lg border p-3 text-xs"
                data-testid="incomplete-set"
            >
                The default set is missing its
                {{ missingKinds.join(', ') }}
                {{ missingKinds.length === 1 ? 'scale' : 'scales' }}. Run the
                system scale seeder — an incomplete set cannot start an
                assessment.
            </p>

            <!--
                No `reset-urls`, and no `show-origin`: this is the default, so
                there is nothing above it to reset to, and every scale here is
                a system scale by definition — a chip saying so on all three
                would be noise.
            -->
            <ScaleCards
                :scales="scales"
                :currency="currencyForm.default_currency"
                :top-band-multiplier="topBandMultiplier"
                :save-urls="saveUrls"
                :errors="errors"
            >
                <template #footnote>
                    Changing a band changes every number derived from it in
                    every assessment still following this default. Assessments
                    with their own calibration are unaffected, and snapshots
                    keep the scale they were taken with.
                </template>
            </ScaleCards>
        </section>
    </AdminLayout>
</template>
