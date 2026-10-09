<script setup lang="ts">
/**
 * Save as template, inside the Share dialog (W45, PLAN-2.2.md item 14).
 *
 * ## The confirmation is inline, not a nested modal
 *
 * The brief asks for a confirm dialog. `ConfirmDialog` is a `Modal`, and
 * `Modal` installs a document-level `keydown` handler that traps Tab and
 * closes on Escape. Two of them mounted at once means two handlers both
 * calling `preventDefault()` on Tab — the focus trap fights itself and a
 * keyboard user is stuck between two rings — and one Escape dismissing both
 * the confirmation and the Share dialog behind it. `Modal.vue` belongs to
 * W10, not to this package, so the fix is not to nest rather than to change
 * it.
 *
 * What is here instead is the same contract: the copy the brief specifies,
 * shown in full before anything happens, and a second deliberate click on a
 * button that is not the one first offered. Nothing is published on one
 * click.
 *
 * ## Why the copy is this long
 *
 * It is the only place an admin is told that publishing lets everyone with an
 * account copy the assessment — every risk, attack, defence and every number
 * on them. That is the sentence someone needs before the click, not in a
 * changelog after it.
 *
 * Since 2.5.1 only an admin sees the controls (`canSaveAsTemplate`), and
 * publishing no longer makes the assessment itself public: the template list
 * and "use this template" are open to every account on their own.
 */
import Button from '@/Components/UI/Button.vue';
import { ref, watch } from 'vue';
import type { TemplateStatus } from './types';

const props = defineProps<{
    status: TemplateStatus;
    busy: boolean;
}>();

const emit = defineEmits<{ publish: []; unpublish: [] }>();

const confirming = ref<'publish' | 'unpublish' | null>(null);

// A completed mutation retires the prompt. Without this the panel would
// still be asking about a decision the server has already acted on.
watch(
    () => props.status.is_template,
    () => {
        confirming.value = null;
    },
);
</script>

<template>
    <div>
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-text-primary text-sm font-medium">Template</p>
                <p class="text-text-muted text-xs">
                    <template v-if="status.is_template">
                        This assessment is published as a template. Anyone with
                        an account can create their own assessment from it.
                    </template>
                    <template v-else-if="status.canSaveAsTemplate">
                        Publish this assessment so other people can start their
                        own from it.
                    </template>
                    <template v-else>
                        Only an administrator can publish an assessment as a
                        template.
                    </template>
                </p>
            </div>

            <Button
                v-if="status.canSaveAsTemplate"
                :variant="status.is_template ? 'ghost' : 'secondary'"
                :disabled="busy || confirming !== null"
                @click="
                    confirming = status.is_template ? 'unpublish' : 'publish'
                "
            >
                {{ status.is_template ? 'Unpublish' : 'Save as template' }}
            </Button>
        </div>

        <!--
            The wording of the publish prompt is the brief's, verbatim. It is
            the security content of this package expressed to the one person
            who can act on it.
        -->
        <div
            v-if="confirming === 'publish'"
            data-testid="template-confirm-publish"
            class="border-border-default bg-surface-2 mt-3 rounded-md border p-3"
        >
            <p class="text-text-secondary text-xs">
                Saving as a template lets
                <strong class="text-text-primary"
                    >everyone with an account</strong
                >
                create their own assessment from it — a full copy of its risks,
                attacks, defences and values. It does not make this assessment
                itself public, and nobody gains the right to edit it. You can
                unpublish it at any time.
            </p>
            <div class="mt-3 flex justify-end gap-2">
                <Button
                    variant="ghost"
                    :disabled="busy"
                    @click="confirming = null"
                >
                    Cancel
                </Button>
                <Button
                    variant="primary"
                    :loading="busy"
                    autofocus
                    @click="emit('publish')"
                >
                    Save as template
                </Button>
            </div>
        </div>

        <div
            v-if="confirming === 'unpublish'"
            data-testid="template-confirm-unpublish"
            class="border-border-default bg-surface-2 mt-3 rounded-md border p-3"
        >
            <p class="text-text-secondary text-xs">
                Unpublishing removes this assessment from the template list.
                Assessments already created from it are copies and are not
                affected.
            </p>
            <div class="mt-3 flex justify-end gap-2">
                <Button
                    variant="ghost"
                    :disabled="busy"
                    @click="confirming = null"
                >
                    Cancel
                </Button>
                <Button
                    variant="danger"
                    :loading="busy"
                    autofocus
                    @click="emit('unpublish')"
                >
                    Unpublish
                </Button>
            </div>
        </div>
    </div>
</template>
