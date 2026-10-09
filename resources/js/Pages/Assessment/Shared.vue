<script setup lang="ts">
/**
 * *Assessments shared with me* (ui.md §8, W73 item 20, D33; 2.5.1).
 *
 * Its own page, because "nobody has shared anything with you" is a different
 * statement from "you have no assessments". Since 2.5.1 it renders the same
 * list as *My assessments* — the same filter, the same row, the same icon
 * actions, each still gated on the permission the server computed for that
 * row — instead of the dashboard's action-less rows. Every row names its
 * owner, which is the point of the page (display name only: an address on a
 * page about other people's work is exactly where one leaks).
 */
import AssessmentList, {
    type AssessmentSummary,
} from '@/Components/Dashboard/AssessmentList.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{ assessments: AssessmentSummary[] }>();

const heading = 'Assessments shared with me';
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="heading" />

        <div class="mx-auto max-w-5xl px-4 py-8">
            <div class="mb-6">
                <h1 class="text-text-primary text-xl font-semibold">
                    {{ heading }}
                </h1>
                <p class="text-text-muted text-sm">
                    Assessments other people have invited you to.
                </p>
            </div>

            <AssessmentList
                :assessments="assessments"
                empty-icon="users"
                empty-title="Nothing shared with you"
                empty-description="Assessments other people invite you to will appear here, with their name on every row."
            />
        </div>
    </AuthenticatedLayout>
</template>
