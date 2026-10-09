<script setup lang="ts">
/**
 * The warning in front of a restore (W61).
 *
 * One component, used from both places a restore can be started — the version
 * list and the page for one version — because the two must not be able to
 * drift into saying different things about the same action. A dialog that
 * warns on one route and not the other is worse than no dialog: it teaches
 * people the action is safe.
 *
 * ## What it says, and why it says that
 *
 * The warning names the three things a restore actually does, in the order
 * they matter to someone about to press it: work made since this version is
 * removed, what does not come back with it, and that the current state is
 * saved first so this is undoable. The last line is as important as the first
 * two — a warning that only frightens gets clicked through, and this action
 * genuinely is recoverable.
 *
 * ## The middle line depends on the snapshot, not on the app
 *
 * W62 made labels and assignees part of the frozen shape, from `schemaVersion`
 * 2 onward. Snapshots taken before that still exist and still restore, and
 * they still cannot bring a deleted node's labels back. So this dialog reads
 * the version of the snapshot in front of it rather than stating one rule for
 * both: telling someone their labels are safe when they are looking at a v1
 * snapshot is the kind of reassurance that gets found out afterwards.
 *
 * The confirm button is destructive-styled and is **not** the default focus:
 * the reader should have to move to it.
 */
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    assessmentUuid: string;
    snapshot: {
        id: number;
        name: string;
        schemaVersion: number;
        createdAt: string | null;
    } | null;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

const working = ref(false);

const when = (iso: string | null) =>
    iso === null ? 'an unknown date' : new Date(iso).toLocaleString();

/** Kept in step with SnapshotRestorer::LABELS_FROM_VERSION. */
const LABELS_FROM_VERSION = 2;

const carriesLabels = computed(
    () => (props.snapshot?.schemaVersion ?? 0) >= LABELS_FROM_VERSION,
);

function confirm() {
    if (props.snapshot === null) return;

    working.value = true;

    router.post(
        route('assessments.snapshots.restore', {
            assessment: props.assessmentUuid,
            snapshot: props.snapshot.id,
        }),
        {},
        {
            // No preserveScroll: a successful restore lands on the assessment,
            // and a partly-scrolled arrival on a graph that has just changed
            // underneath the reader is disorienting.
            onFinish: () => {
                working.value = false;
                emit('close');
            },
        },
    );
}
</script>

<template>
    <Modal
        :show="snapshot !== null"
        :title="`Restore “${snapshot?.name ?? ''}”?`"
        @close="emit('close')"
    >
        <div>
            <p class="text-text-secondary text-sm">
                This replaces the assessment with the version saved on
                {{ when(snapshot?.createdAt ?? null) }}.
            </p>

            <ul class="text-text-secondary mt-4 space-y-2 text-sm">
                <li class="flex gap-2">
                    <span aria-hidden="true">•</span>
                    <span>
                        <strong class="text-text-primary"
                            >Anything added since then is removed</strong
                        >, for everyone working on this assessment — not just
                        for you.
                    </span>
                </li>
                <li v-if="carriesLabels" class="flex gap-2">
                    <span aria-hidden="true">•</span>
                    <span>
                        Labels and assignees are restored to what they were —
                        including being
                        <strong class="text-text-primary">removed</strong> where
                        this version did not have them.
                        <span class="text-text-muted"
                            >Comments are never part of a version and are left
                            alone.</span
                        >
                    </span>
                </li>
                <li
                    v-else
                    class="flex gap-2"
                    data-testid="legacy-label-warning"
                >
                    <span aria-hidden="true">•</span>
                    <span>
                        This version predates label history, so labels and
                        assignees
                        <strong class="text-text-primary"
                            >will not come back</strong
                        >
                        on nodes it restores. Nodes that still exist keep
                        theirs.
                    </span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true">•</span>
                    <span>
                        The current state is
                        <strong class="text-text-primary">saved first</strong>
                        as a new version, so this can be undone.
                    </span>
                </li>
            </ul>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="ghost" @click="emit('close')">Cancel</Button>
                <Button variant="danger" :loading="working" @click="confirm">
                    Restore this version
                </Button>
            </div>
        </template>
    </Modal>
</template>
