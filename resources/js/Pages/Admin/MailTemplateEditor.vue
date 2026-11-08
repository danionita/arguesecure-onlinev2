<script setup lang="ts">
/**
 * One message kind's wording: subject and body with {{placeholders}} from a
 * fixed list, a preview with sample values (nothing is sent), and a reset to
 * the shipped default. The footer is fixed and shown, not editable.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

export interface MailTemplate {
    kind: string;
    label: string;
    placeholders: Record<string, string>;
    footer: string;
    defaults: { subject: string; body: string };
    subject: string;
    body: string;
    customised: boolean;
}

const props = defineProps<{
    template: MailTemplate;
    limits: { subject: number; body: number };
}>();

/** How a placeholder is written in a template. */
const tag = (name: string) => '{{' + name + '}}';

const form = useForm({
    subject: props.template.subject,
    body: props.template.body,
});

useUnsavedChanges(() => form.isDirty);

function save() {
    form.put(route('admin.mail.templates.update', props.template.kind), {
        preserveScroll: true,
        // The server trims what it stores; show what was stored.
        onSuccess: () => {
            form.subject = props.template.subject;
            form.body = props.template.body;
            form.defaults();
        },
    });
}

function reset() {
    router.delete(route('admin.mail.templates.destroy', props.template.kind), {
        preserveScroll: true,
        onSuccess: () => {
            form.subject = props.template.defaults.subject;
            form.body = props.template.defaults.body;
            form.defaults();
        },
    });
}

interface Preview {
    subject: string;
    text: string;
}

const preview = ref<Preview | null>(null);
const previewError = ref('');
const previewing = ref(false);

async function showPreview() {
    previewing.value = true;
    previewError.value = '';
    preview.value = null;
    try {
        const response = await window.axios.post<Preview>(
            route('admin.mail.templates.preview', props.template.kind),
            { subject: form.subject, body: form.body },
        );
        preview.value = response.data;
    } catch (error) {
        const errors = (
            error as {
                response?: { data?: { errors?: Record<string, string[]> } };
            }
        ).response?.data?.errors;
        previewError.value =
            errors?.body?.[0] ??
            errors?.subject?.[0] ??
            'The preview could not be made.';
    } finally {
        previewing.value = false;
    }
}
</script>

<template>
    <details
        class="border-border-subtle rounded-md border p-4"
        :data-testid="`template-${template.kind}`"
    >
        <summary class="text-text-primary cursor-pointer text-sm font-medium">
            {{ template.label }}
            <span v-if="template.customised" class="text-text-muted text-xs"
                >(edited)</span
            >
        </summary>

        <form class="mt-4 space-y-4" @submit.prevent="save">
            <Input
                v-model="form.subject"
                label="Subject"
                :error="form.errors.subject"
            />

            <div>
                <label
                    class="text-text-primary block text-sm font-medium"
                    :for="`body-${template.kind}`"
                    >Message</label
                >
                <textarea
                    :id="`body-${template.kind}`"
                    v-model="form.body"
                    rows="9"
                    :maxlength="limits.body"
                    class="border-border-default bg-surface-raised mt-1 block w-full rounded-md border px-3 py-2 font-mono text-sm"
                />
                <p
                    v-if="form.errors.body"
                    class="text-danger mt-1 text-xs"
                    role="alert"
                >
                    {{ form.errors.body }}
                </p>
            </div>

            <div class="text-text-muted text-xs">
                Placeholders you can use:
                <ul class="mt-1 space-y-0.5">
                    <li
                        v-for="(meaning, name) in template.placeholders"
                        :key="name"
                    >
                        <code class="text-text-primary">{{ tag(name) }}</code>
                        {{ meaning }}
                    </li>
                </ul>
                Plain text only. A subject cannot use links or the summary,
                because subjects show on lock screens and in mail logs. Every
                message ends with this line, which cannot be removed:
                <em>{{ template.footer }}</em>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    >Save</Button
                >
                <Button
                    type="button"
                    variant="secondary"
                    :loading="previewing"
                    data-testid="preview"
                    @click="showPreview"
                    >Preview</Button
                >
                <Button
                    type="button"
                    variant="secondary"
                    :disabled="!template.customised"
                    data-testid="reset"
                    @click="reset"
                    >Reset to default</Button
                >
            </div>

            <p v-if="previewError" class="text-danger text-sm" role="alert">
                {{ previewError }}
            </p>
            <div
                v-if="preview"
                class="border-border-subtle rounded-md border p-3 text-sm"
                data-testid="preview-result"
            >
                <p class="text-text-primary font-medium">
                    {{ preview.subject }}
                </p>
                <pre
                    class="text-text-secondary mt-2 font-sans whitespace-pre-wrap"
                    >{{ preview.text }}</pre>
                <p class="text-text-muted mt-2 text-xs">
                    Sample values; nothing was sent.
                </p>
            </div>
        </form>
    </details>
</template>
