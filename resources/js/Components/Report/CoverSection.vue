<script setup lang="ts">
/**
 * Cover section (PLAN.md Phase 7 §1): title and target of assessment —
 * these live on the ROOT NODE, not the assessment row (CLAUDE.md "Domain
 * model in one screen") — owner, date, scale calibration used, and the
 * completeness statement.
 */
import Icon from '../UI/Icon.vue';
import ReportTotal from './ReportTotal.vue';
import type { ReportCover } from './types';

const props = defineProps<{
    cover: ReportCover;
    currency: string;
}>();

const kindLabels: Record<string, string> = {
    impact: 'Impact scale',
    likelihood: 'Likelihood scale',
    strength: 'Strength scale',
};

const generatedDate = new Date(props.cover.generatedAt).toLocaleDateString(
    'en-GB',
    { year: 'numeric', month: 'long', day: 'numeric' },
);
</script>

<template>
    <section
        class="report-section break-inside-avoid"
        aria-labelledby="cover-heading"
    >
        <h1 id="cover-heading" class="text-text-primary text-3xl font-semibold">
            {{ cover.title ?? 'Untitled assessment' }}
        </h1>
        <p v-if="cover.description" class="text-text-secondary mt-2 max-w-2xl">
            {{ cover.description }}
        </p>

        <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
            <div>
                <dt class="text-text-muted text-xs font-medium uppercase">
                    Owner
                </dt>
                <dd class="text-text-primary text-sm">
                    {{ cover.owner?.name ?? '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-text-muted text-xs font-medium uppercase">
                    Generated
                </dt>
                <dd class="text-text-primary text-sm">{{ generatedDate }}</dd>
            </div>
            <div>
                <dt class="text-text-muted text-xs font-medium uppercase">
                    Currency
                </dt>
                <dd class="text-text-primary text-sm">{{ currency }}</dd>
            </div>
        </dl>

        <div class="mt-6">
            <div class="text-text-muted text-xs font-medium uppercase">
                Current known risk
            </div>
            <ReportTotal
                :block="cover.completeness"
                :currency="currency"
                size="sm"
            />
        </div>

        <div class="mt-6">
            <h2 class="text-text-primary text-sm font-semibold">Scales used</h2>
            <div class="mt-2 grid gap-3 sm:grid-cols-3">
                <div
                    v-for="scale in cover.scaleCalibration"
                    :key="scale.kind"
                    class="border-border-subtle rounded-md border p-2.5 text-xs"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-text-primary font-medium">{{
                            kindLabels[scale.kind] ?? scale.kind
                        }}</span>
                        <span
                            v-if="scale.isSystem"
                            class="text-status-todo-text inline-flex items-center gap-1"
                        >
                            <Icon name="alert-circle" :size="11" />
                            shared bands
                        </span>
                    </div>
                    <ul class="text-text-secondary mt-1.5 space-y-0.5">
                        <li v-for="level in scale.levels" :key="level.code">
                            {{ level.label }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
</template>
