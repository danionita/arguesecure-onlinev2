<script setup lang="ts">
/**
 * Publish as template, inside the Share dialog (W45; reshaped by W141).
 *
 * Publishing copies the assessment into a separate template on the Templates
 * page. It is a one-way action: the copy is not linked to this assessment, so
 * editing either changes only that one, and there is nothing to unpublish. A
 * template is removed by deleting it on the Templates page.
 *
 * ## The confirmation is inline, not a nested modal
 *
 * `ConfirmDialog` is a `Modal`, and `Modal` installs a document-level
 * `keydown` handler that traps Tab and closes on Escape. Two of them mounted
 * at once means two handlers both calling `preventDefault()` on Tab, and one
 * Escape dismissing both the confirmation and the Share dialog behind it. So
 * the copy is shown in full before anything happens and a second, deliberate
 * click on a different button does the work. Nothing is published on one click.
 *
 * ## Why the copy is this long
 *
 * It is the only place an admin is told that publishing lets everyone with an
 * account copy the assessment, every risk, attack, defence and every number on
 * them. That sentence belongs before the click, not in a changelog after it.
 */
import Button from '@/Components/UI/Button.vue';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    busy: boolean;
    /** Set once a copy was made in this session, to point at where it went. */
    published: boolean;
}>();

const emit = defineEmits<{ publish: [] }>();

const confirming = ref(false);
</script>

<template>
    <div>
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-text-primary text-sm font-medium">Template</p>
                <p v-if="published" class="text-text-muted text-xs">
                    Published. The copy is on the
                    <Link
                        :href="route('templates.index')"
                        class="text-accent underline"
                        >Templates page</Link
                    >, where any administrator can edit or delete it.
                </p>
                <p v-else class="text-text-muted text-xs">
                    Publish a copy of this assessment as a template, so other
                    people can start their own from it.
                </p>
            </div>

            <Button
                variant="secondary"
                :disabled="busy || confirming"
                @click="confirming = true"
            >
                {{ published ? 'Publish another copy' : 'Publish as template' }}
            </Button>
        </div>

        <div
            v-if="confirming"
            data-testid="template-confirm-publish"
            class="border-border-default bg-surface-2 mt-3 rounded-md border p-3"
        >
            <p class="text-text-secondary text-xs">
                This puts a copy of this assessment in the template list, where
                <strong class="text-text-primary"
                    >everyone with an account</strong
                >
                can create their own assessment from it: a full copy of its
                risks, attacks, defences and values. The template is separate
                from this assessment, so editing one never changes the other,
                and it cannot be unpublished. Any administrator can delete it
                from the Templates page.
            </p>
            <div class="mt-3 flex justify-end gap-2">
                <Button
                    variant="ghost"
                    :disabled="busy"
                    @click="confirming = false"
                >
                    Cancel
                </Button>
                <Button
                    variant="primary"
                    :loading="busy"
                    autofocus
                    @click="
                        emit('publish');
                        confirming = false;
                    "
                >
                    Publish as template
                </Button>
            </div>
        </div>
    </div>
</template>
