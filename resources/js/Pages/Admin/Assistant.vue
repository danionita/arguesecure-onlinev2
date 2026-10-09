<script setup lang="ts">
/**
 * The admin **AI assistant** tab (2.5.1).
 *
 * The switches are W50's and W87's (D25, D48), moved here from the foot of
 * Instance settings unchanged. What changed is the not-installed state: the
 * old panel explained, in a paragraph, which environment variable to set and
 * which `docker compose --profile` command to run. That is installation, and
 * installation is for whoever holds the compose file — it is in the README.
 * Here an admin sees one line, "AI service not installed", and a tooltip
 * saying where to read how.
 *
 * Nothing on this page can make the assistant work where the runtime is
 * absent: the switches render only when it is configured, and the server
 * refuses a `true` from a deployment without one.
 *
 * 2.6 (PLAN-2.6.md D55): the model shown is the one the runtime reports it
 * loaded, matched to the two presets — not a label from `.env`, which could
 * name one model while the container ran another. The presets are listed
 * with the memory each needs, because that is the one fact an admin needs to
 * ask for the other one. How to install is still the README's job.
 */
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import Tooltip from '@/Components/UI/Tooltip.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

/** What the runtime reports it loaded. `preset` is null for a custom file. */
interface RunningModel {
    file: string;
    preset: string | null;
    label: string | null;
    memory_gib: number | null;
}

interface Preset {
    name: string;
    label: string;
    memory_gib: number;
    download_gb: number;
}

const props = defineProps<{
    settings: {
        assistant_enabled: boolean;
        assistant_help_enabled: boolean;
        assistant_suggestions_enabled: boolean;
        assistant_summary_enabled: boolean;
    };
    /** Facts about the deployment, not settings (D25). */
    assistant: {
        configured: boolean;
        state: string | null;
        /** Null when the runtime is not answering, so nothing is claimed. */
        model: RunningModel | null;
        presets: Preset[];
    };
}>();

const form = useForm({ ...props.settings });

function submit() {
    form.put(route('admin.assistant.update'));
}
</script>

<template>
    <Head title="Admin — AI assistant" />

    <AdminLayout title="AI assistant">
        <div class="max-w-lg space-y-4">
            <p class="text-text-secondary text-sm">
                An optional local model that answers questions on the help
                pages, suggests risks, attacks and defences, and drafts the
                report's executive summary. It runs on this server and sends
                nothing off the machine, and nothing it writes is used until a
                person accepts it.
            </p>

            <div
                v-if="!assistant.configured"
                class="border-border-subtle bg-surface-1 flex items-center gap-2 rounded-lg border p-4"
                data-testid="assistant-not-configured"
            >
                <Icon name="bot" :size="16" class="text-text-muted" />
                <span class="text-text-primary text-sm font-medium">
                    AI service not installed
                </span>
                <Tooltip
                    text="Installing it is optional. See the “AI assistant” section of README.md in the ArgueSecure repository for how to add it to this deployment."
                >
                    <span
                        class="text-text-muted inline-flex cursor-help"
                        tabindex="0"
                        role="img"
                        aria-label="How to install it: see the AI assistant section of README.md"
                        data-testid="assistant-install-hint"
                    >
                        <Icon name="info" :size="14" />
                    </span>
                </Tooltip>
            </div>

            <form
                v-else
                class="border-border-subtle bg-surface-1 space-y-3 rounded-lg border p-4"
                data-testid="assistant-panel"
                @submit.prevent="submit"
            >
                <Toggle
                    v-model="form.assistant_enabled"
                    label="Enable the assistant"
                />
                <div class="space-y-1 text-xs" data-testid="assistant-model">
                    <p v-if="assistant.model" class="text-text-secondary">
                        <span class="text-text-primary font-medium"
                            >Installed model:</span
                        >
                        {{ assistant.model.label ?? assistant.model.file }}
                        <span v-if="assistant.model.preset">
                            (the {{ assistant.model.preset }} preset).
                        </span>
                        <span v-else data-testid="assistant-model-custom">
                            (a custom model file, not one of the presets).
                        </span>
                    </p>
                    <p v-else class="text-text-muted">
                        Installed model: not known until the AI service
                        responds.
                    </p>

                    <ul
                        v-if="assistant.presets.length"
                        class="text-text-muted space-y-0.5"
                        data-testid="assistant-presets"
                    >
                        <li
                            v-for="preset in assistant.presets"
                            :key="preset.name"
                        >
                            <span class="text-text-secondary">{{
                                preset.name
                            }}</span>
                            — {{ preset.label }}; needs about
                            {{ preset.memory_gib }} GiB of memory, a
                            {{ preset.download_gb }} GB download<span
                                v-if="assistant.model?.preset === preset.name"
                                class="text-text-secondary"
                            >
                                · running</span
                            >
                        </li>
                    </ul>
                    <p
                        v-if="assistant.presets.length"
                        class="text-text-muted flex items-center gap-1"
                    >
                        Installing or switching models is one command for
                        whoever runs the server.
                        <Tooltip
                            text="See the “AI assistant” section of README.md in the ArgueSecure repository."
                        >
                            <span
                                class="inline-flex cursor-help"
                                tabindex="0"
                                role="img"
                                aria-label="How to install or switch models: see the AI assistant section of README.md"
                                data-testid="assistant-switch-hint"
                            >
                                <Icon name="info" :size="14" />
                            </span>
                        </Tooltip>
                    </p>
                </div>

                <p class="text-text-muted text-xs">
                    <span
                        v-if="
                            form.assistant_enabled &&
                            assistant.state === 'unreachable'
                        "
                        class="text-node-attack-text"
                        data-testid="assistant-unreachable"
                    >
                        The AI service is not responding.
                    </span>
                    <span
                        v-else-if="assistant.state === 'available'"
                        data-testid="assistant-available"
                    >
                        The AI service is responding.
                    </span>
                </p>

                <!--
                    One switch per feature (W87, item 20, D48). Each can only
                    subtract: disabled while the assistant itself is off,
                    because that is what they are — a narrowing of a feature
                    that is already on.
                -->
                <fieldset
                    class="border-border-subtle space-y-2 border-t pt-3"
                    :disabled="!form.assistant_enabled"
                    data-testid="assistant-features"
                >
                    <legend class="text-text-secondary text-xs font-medium">
                        Which features are offered
                    </legend>

                    <Toggle
                        v-model="form.assistant_help_enabled"
                        label="Answer questions on the help pages"
                    />
                    <Toggle
                        v-model="form.assistant_suggestions_enabled"
                        label="Suggest risks, attacks and defences"
                    />
                    <Toggle
                        v-model="form.assistant_summary_enabled"
                        label="Draft the report's executive summary"
                    />

                    <p class="text-text-muted text-xs">
                        On four cores of a recent server, the small model takes
                        about 20 seconds for a suggestion and two minutes for a
                        drafted summary; the large one a quarter to a half
                        longer. A help question is far cheaper once the help
                        pages have been read in.
                    </p>
                </fieldset>

                <div class="flex justify-end">
                    <Button
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        >Save</Button
                    >
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
