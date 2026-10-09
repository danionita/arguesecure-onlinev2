<script setup lang="ts">
/**
 * "You dropped this somewhere else — did you mean move, or link?" (W38,
 * PLAN-2.2.md D11).
 *
 * Not a `ConfirmDialog` with an extra button. A confirm dialog asks one
 * question with a safe default and a destructive alternative; this asks the
 * user to *choose between two intended outcomes*, neither of which is the
 * default and only one of which is destructive. Reusing ConfirmDialog would
 * have meant one of the two real answers wearing the Cancel slot, and Cancel
 * has to keep meaning "nothing happened" — a drag is easy to start by
 * accident on a projector.
 *
 * Both options say what they do in the assessment's own words rather than in
 * graph vocabulary: a facilitator mid-workshop should not have to know the
 * word "edge" to answer.
 */
import Button from './Button.vue';
import Modal from './Modal.vue';

defineProps<{
    show: boolean;
    childTitle: string;
    toParentTitle: string;
    /** False when the node already sits under the target: linking again would write a duplicate edge. */
    canLink: boolean;
}>();

const emit = defineEmits<{
    move: [];
    link: [];
    cancel: [];
}>();
</script>

<template>
    <Modal :show="show" title="Move or link?" size="sm" @close="emit('cancel')">
        <!--
            The padding is here, not on `Modal`'s body (W71 item 3). Every
            other consumer of Modal pads its own content, so padding the body
            would double-pad all four — and NodeModal in particular wants its
            tab strip flush to the dialog's edges.
        -->
        <div class="space-y-4 p-4">
            <p class="text-text-secondary text-sm">
                <strong class="text-text-primary">{{ childTitle }}</strong>
                was dropped on
                <strong class="text-text-primary">{{ toParentTitle }}</strong
                >.
            </p>

            <div class="space-y-2">
                <button
                    type="button"
                    class="border-border-default hover:bg-surface-2 focus-visible:outline-focus-ring w-full rounded-md border p-3 text-left outline-none focus-visible:outline-2"
                    @click="emit('move')"
                >
                    <span class="text-text-primary block text-sm font-medium">
                        Move it here
                    </span>
                    <span class="text-text-muted block text-xs">
                        It leaves where it was and belongs only to
                        {{ toParentTitle }}.
                    </span>
                </button>

                <button
                    v-if="canLink"
                    type="button"
                    class="border-border-default hover:bg-surface-2 focus-visible:outline-focus-ring w-full rounded-md border p-3 text-left outline-none focus-visible:outline-2"
                    @click="emit('link')"
                >
                    <span class="text-text-primary block text-sm font-medium">
                        Link it here as well
                    </span>
                    <span class="text-text-muted block text-xs">
                        One node in both places. Edit it anywhere and it changes
                        everywhere.
                    </span>
                </button>

                <!--
                    Explained rather than hidden. A missing option with no
                    reason reads as a bug, and "it is already there" is the
                    kind of thing a user can check for themselves once told.
                -->
                <p v-else class="text-text-muted px-3 text-xs">
                    It already belongs to {{ toParentTitle }}, so there is
                    nothing to link.
                </p>
            </div>
        </div>

        <template #footer>
            <Button variant="ghost" size="sm" @click="emit('cancel')">
                Cancel
            </Button>
        </template>
    </Modal>
</template>
