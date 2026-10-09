<script setup lang="ts">
/**
 * One assessment on the dashboard (W42).
 *
 * Deliberately not the assessment *list*'s row: that one carries five actions
 * and a permission behind each of them, and a dashboard is for deciding where
 * to go, not for managing what is there. Anything that acts on an assessment
 * lives one click away on `/assessments`.
 *
 * The owner's name renders whenever there is one, which on the "Shared with
 * me" section is every row — "the thing that is missing today" (PLAN-2.2.md
 * W42). Knowing whose work you are about to open is most of what makes a
 * shared list usable.
 */
import type { DashboardAssessment } from '@/Pages/Dashboard.vue';

defineProps<{ assessment: DashboardAssessment }>();
</script>

<template>
    <li
        class="border-border-subtle bg-surface-1 rounded-lg border p-3"
        :data-assessment="assessment.uuid"
    >
        <a
            :href="route('assessments.show', assessment.uuid)"
            class="text-text-primary font-medium hover:underline"
        >
            {{ assessment.title }}
        </a>
        <p
            v-if="assessment.description"
            class="text-text-muted mt-0.5 line-clamp-1 text-sm"
        >
            {{ assessment.description }}
        </p>
        <p
            v-if="assessment.owner_name"
            class="text-text-secondary mt-1 text-xs"
        >
            Shared by {{ assessment.owner_name }}
        </p>
    </li>
</template>
