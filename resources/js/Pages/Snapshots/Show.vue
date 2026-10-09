<script setup lang="ts">
/**
 * Read-only view of one frozen snapshot (PLAN.md Phase 8 item 3 "View a
 * snapshot read-only"). Renders `results` exactly as stored — never
 * recomputes (CLAUDE.md: "never recompute a stored snapshot's figures on
 * read — a snapshot is frozen output, that is the whole point").
 */
import RestoreSnapshotDialog from '@/Components/Snapshots/RestoreSnapshotDialog.vue';
import SnapshotTotal from '@/Components/Snapshots/SnapshotTotal.vue';
import type { SnapshotDetail } from '@/Components/Snapshots/types';
import Button from '@/Components/UI/Button.vue';
import Chip from '@/Components/UI/Chip.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    assessment: { uuid: string; currency: string };
    snapshot: SnapshotDetail;
    canRestore: boolean;
}>();

// W61. The second of the two restore entry points — the one someone reaches
// after actually reading a version. Same dialog as the list's.
const restoring = ref(false);

const nodeCounts = computed(() => {
    const counts = { risk: 0, attack: 0, defence: 0 };
    for (const node of Object.values(props.snapshot.graph.nodes)) {
        if (
            node.type === 'risk' ||
            node.type === 'attack' ||
            node.type === 'defence'
        ) {
            counts[node.type]++;
        }
    }
    return counts;
});
</script>

<template>
    <Head :title="`Snapshot — ${snapshot.name}`" />

    <div class="bg-surface-0 min-h-screen">
        <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
            <Link
                :href="route('assessments.history.index', assessment.uuid)"
                class="text-text-muted hover:text-text-primary text-xs"
            >
                ← Back to history
            </Link>

            <div class="mt-1 flex items-center justify-between gap-3">
                <h1 class="text-text-primary text-xl font-semibold">
                    {{ snapshot.name }}
                </h1>
                <div class="flex shrink-0 items-center gap-2">
                    <Chip>Read-only</Chip>
                    <!-- W63. This page has the figures; that one has the graph
                         they came from. -->
                    <Link
                        :href="
                            route('assessments.snapshots.view', [
                                assessment.uuid,
                                snapshot.id,
                            ])
                        "
                        class="text-text-muted hover:text-text-primary text-xs underline"
                    >
                        View this version
                    </Link>
                    <Button
                        v-if="canRestore"
                        variant="secondary"
                        icon="history"
                        @click="restoring = true"
                    >
                        Restore this version
                    </Button>
                </div>
            </div>
            <p class="text-text-muted mt-1 text-xs">
                {{ snapshot.createdBy ?? 'Unknown' }} ·
                {{
                    snapshot.createdAt
                        ? new Date(snapshot.createdAt).toLocaleString()
                        : '—'
                }}
                · schema v{{ snapshot.schemaVersion }}
            </p>
            <p v-if="snapshot.note" class="text-text-secondary mt-3 text-sm">
                {{ snapshot.note }}
            </p>

            <div
                class="border-border-subtle bg-surface-1 mt-6 grid gap-6 rounded-lg border p-4 sm:grid-cols-3"
            >
                <SnapshotTotal
                    :result="snapshot.results.inherent"
                    :currency="assessment.currency"
                    label="Inherent"
                />
                <SnapshotTotal
                    :result="snapshot.results.current"
                    :currency="assessment.currency"
                    label="Current"
                />
                <SnapshotTotal
                    :result="snapshot.results.target"
                    :currency="assessment.currency"
                    label="Target"
                />
            </div>

            <div
                class="border-border-subtle bg-surface-1 mt-6 rounded-lg border p-4"
            >
                <h2 class="text-text-primary text-sm font-semibold">
                    Frozen graph
                </h2>
                <dl
                    class="text-text-secondary mt-2 grid grid-cols-3 gap-2 text-sm"
                >
                    <div>
                        <dt class="text-text-muted text-xs">Risks</dt>
                        <dd>{{ nodeCounts.risk }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted text-xs">Attacks</dt>
                        <dd>{{ nodeCounts.attack }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted text-xs">Defences</dt>
                        <dd>{{ nodeCounts.defence }}</dd>
                    </div>
                </dl>
            </div>

            <div
                v-if="snapshot.results.current.warnings.length > 0"
                class="border-border-subtle bg-surface-1 mt-6 rounded-lg border p-4"
            >
                <h2 class="text-text-primary text-sm font-semibold">
                    Warnings (as of this version)
                </h2>
                <ul class="text-text-secondary mt-2 space-y-1 text-sm">
                    <li
                        v-for="(warning, index) in snapshot.results.current
                            .warnings"
                        :key="index"
                    >
                        {{ warning.message }}
                    </li>
                </ul>
            </div>
        </div>

        <RestoreSnapshotDialog
            :assessment-uuid="assessment.uuid"
            :snapshot="restoring ? snapshot : null"
            @close="restoring = false"
        />
    </div>
</template>
