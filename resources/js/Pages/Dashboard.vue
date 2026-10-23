<script setup lang="ts">
/**
 * The arrival page (ui.md Â§8, W42 item 17).
 *
 * `/dashboard` used to render the assessment list â€” the same page as
 * `/assessments`, at a second URL, which meant Laravel's post-login
 * destination and the sidebar's home link both landed somewhere that answered
 * only half the questions someone has when they arrive.
 *
 * ## Two sections of tabs, not four lists (W73 item 21, D32)
 *
 * W42 stacked four sections: what needs *me*, who is waiting on me, what I
 * own, what I have been invited to. The order was right and the shape was
 * not. The two that answer *what needs me* and the two that answer *where do
 * I go* ended up half a screen apart, and neither pair fit above the fold â€”
 * so the page that exists to be read at a glance needed scrolling to read at
 * all. D32 pairs them: **Notifications** (Assigned to me Â· Mentions) and
 * **Assessments** (Mine Â· Shared with me).
 *
 * Work still comes before inventory: Notifications is the first section and
 * "Assigned to me" the first tab in it. A dashboard that opens with a list of
 * things you own has told you nothing you did not know.
 *
 * The counts stay on the tab labels rather than inside the panels. A count
 * you cannot see until you have clicked the tab is a notification you will
 * not see.
 *
 * ## Empty-state first
 *
 * Unchanged by D32, and deliberately so: the four empty states are the part
 * of W42 that was got right, and each one is as true inside a tab panel as it
 * was under a heading. Every one says what will appear there and why, in the
 * product's own words, and none of them mentions a work package â€” "Comments
 * that mention you will show up here" was true before W47 filled it and stays
 * true now.
 */
import AssessmentRow from '@/Components/Dashboard/AssessmentRow.vue';
import DashboardSection from '@/Components/Dashboard/DashboardSection.vue';
import DashboardTabs from '@/Components/Dashboard/DashboardTabs.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, useId } from 'vue';

export interface DashboardAssessment {
    uuid: string;
    title: string;
    description: string | null;
    updated_at: string | null;
    owner_name?: string | null;
}

export interface AssignedNode {
    assessment_uuid: string;
    assessment_title: string;
    node_uuid: string;
    type: string;
    title: string;
    missing: string | null;
}

export interface Mention {
    assessment_uuid: string;
    assessment_title: string;
    node_uuid: string;
    node_title: string;
    excerpt: string;
    author: string;
    resolved: boolean;
    created_at: string;
}

const props = defineProps<{
    assignedToMe: AssignedNode[];
    mentions: Mention[];
    myAssessments: DashboardAssessment[];
    sharedWithMe: DashboardAssessment[];
    counts: { mine: number; shared: number };
}>();

const notificationsHeadingId = useId();
const assessmentsHeadingId = useId();

/**
 * "Unresolved" for mentions is the live `resolved` flag, not the count of
 * notifications: a thread someone has since closed is not waiting for you,
 * and counting it would make the badge a number you cannot act on.
 * Assignments have no equivalent flag, so their count is simply how many
 * there are.
 */
const notificationTabs = computed(() => [
    {
        key: 'assigned',
        label: 'Assigned to me',
        count: props.assignedToMe.length,
        countLabel: 'assigned to you',
    },
    {
        key: 'mentions',
        label: 'Mentions',
        count: props.mentions.filter((mention) => !mention.resolved).length,
        countLabel: 'unresolved',
    },
]);

const assessmentTabs = computed(() => [
    { key: 'mine', label: 'Mine', count: props.counts.mine },
    {
        key: 'shared',
        label: 'Shared with me',
        count: props.counts.shared,
    },
]);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Dashboard" />

        <div class="mx-auto max-w-5xl px-4 py-8">
            <h1 class="text-text-primary mb-6 text-xl font-semibold">
                Dashboard
            </h1>

            <div class="space-y-8">
                <!--
                    Work first, inventory second â€” the one thing about W42's
                    ordering that D32 keeps exactly. Each section is a real
                    `<h2>` inside a real `<section>`, so both are reachable as
                    landmarks, and the tablist repeats the heading as its own
                    accessible name so its tabs are announced as belonging to
                    it rather than floating on the page.
                -->
                <section :aria-labelledby="notificationsHeadingId">
                    <h2
                        :id="notificationsHeadingId"
                        class="text-text-primary mb-2 text-sm font-semibold tracking-wide uppercase"
                    >
                        Notifications
                    </h2>

                    <DashboardTabs
                        label="Notifications"
                        :tabs="notificationTabs"
                    >
                        <template #assigned>
                            <DashboardSection
                                variant="panel"
                                title="Assigned to me"
                                :count="assignedToMe.length"
                                empty-icon="circle-check"
                                empty-title="Nothing assigned to you"
                                empty-description="When someone assigns a risk, attack or defence to you, it will appear here with what it still needs."
                            >
                                <li
                                    v-for="node in assignedToMe"
                                    :key="node.node_uuid"
                                    class="border-border-subtle bg-surface-1 rounded-lg border p-3"
                                >
                                    <a
                                        :href="`${route('assessments.show', node.assessment_uuid)}?node=${node.node_uuid}`"
                                        class="text-text-primary font-medium hover:underline"
                                    >
                                        {{ node.title }}
                                    </a>
                                    <p class="text-text-muted mt-0.5 text-sm">
                                        {{ node.type }} in
                                        {{ node.assessment_title }}
                                        <span v-if="node.missing"
                                            >â€” {{ node.missing }}</span
                                        >
                                    </p>
                                </li>
                            </DashboardSection>
                        </template>

                        <template #mentions>
                            <DashboardSection
                                variant="panel"
                                title="Mentions"
                                :count="mentions.length"
                                empty-icon="message-square"
                                empty-title="No one has mentioned you"
                                empty-description="Comments that mention you by name will show up here, unresolved ones first."
                            >
                                <li
                                    v-for="mention in mentions"
                                    :key="`${mention.node_uuid}-${mention.created_at}`"
                                    class="border-border-subtle bg-surface-1 rounded-lg border p-3"
                                >
                                    <a
                                        :href="`${route('assessments.show', mention.assessment_uuid)}?node=${mention.node_uuid}&tab=comments`"
                                        class="text-text-primary font-medium hover:underline"
                                    >
                                        {{ mention.node_title }}
                                    </a>
                                    <p
                                        class="text-text-secondary mt-0.5 text-sm"
                                    >
                                        {{ mention.excerpt }}
                                    </p>
                                    <p class="text-text-muted mt-0.5 text-xs">
                                        {{ mention.author }} in
                                        {{ mention.assessment_title }}
                                    </p>
                                </li>
                            </DashboardSection>
                        </template>
                    </DashboardTabs>
                </section>

                <section :aria-labelledby="assessmentsHeadingId">
                    <h2
                        :id="assessmentsHeadingId"
                        class="text-text-primary mb-2 text-sm font-semibold tracking-wide uppercase"
                    >
                        Assessments
                    </h2>

                    <DashboardTabs label="Assessments" :tabs="assessmentTabs">
                        <template #mine>
                            <DashboardSection
                                variant="panel"
                                title="My assessments"
                                :count="myAssessments.length"
                                :see-all-href="route('assessments.index')"
                                empty-icon="shield"
                                empty-title="No assessments yet"
                                empty-description="Create one to start mapping risks, attacks and defences."
                            >
                                <AssessmentRow
                                    v-for="assessment in myAssessments"
                                    :key="assessment.uuid"
                                    :assessment="assessment"
                                />
                            </DashboardSection>
                        </template>

                        <template #shared>
                            <!--
                                "See all" goes to the shared page now (D33),
                                not to `/assessments` â€” a list that does not
                                distinguish a shared row from an owned one,
                                which made the link a promise it could not
                                keep.
                            -->
                            <DashboardSection
                                variant="panel"
                                title="Shared with me"
                                :count="sharedWithMe.length"
                                :see-all-href="route('assessments.shared')"
                                empty-icon="users"
                                empty-title="Nothing shared with you"
                                empty-description="Assessments other people invite you to will appear here, with their name on every row."
                            >
                                <AssessmentRow
                                    v-for="assessment in sharedWithMe"
                                    :key="assessment.uuid"
                                    :assessment="assessment"
                                />
                            </DashboardSection>
                        </template>
                    </DashboardTabs>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
