<script setup lang="ts">
/**
 * Bulk import, as a dialog on the Users tab (2.5.1).
 *
 * It was a tab of its own beside Users, Instance settings and Defaults, which
 * put a one-off action at the same level as the places an admin works in. It
 * is now what the "Bulk import" button on the Users tab opens. The flow after
 * it is unchanged: submitting goes to the preview page, which shows exactly
 * which rows will be created and why any are rejected — nothing is created
 * from here.
 */
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{ show: boolean }>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({
    csv_text: '',
    csv_file: null as File | null,
});

const fileInput = ref<HTMLInputElement | null>(null);

function onFileChange(event: Event) {
    const target = event.target as HTMLInputElement;
    form.csv_file = target.files?.[0] ?? null;
}

function submit() {
    // preserveState so a rejected upload comes back to this dialog, still
    // open, with the error under the field — rather than re-mounting the
    // Users page with the dialog shut and the message nowhere.
    form.post(route('admin.users.import.preview'), { preserveState: true });
}
</script>

<template>
    <Modal :show="show" title="Bulk import" size="lg" @close="emit('close')">
        <div class="space-y-4 p-4" data-testid="bulk-import-dialog">
            <p class="text-text-secondary text-sm">
                Paste a CSV with <code>name</code> and
                <code>email</code> columns (an optional
                <code>admin</code> column accepts true/false), or upload a file.
                The next step shows exactly which rows will be created and why
                any are rejected — nothing is created here yet.
            </p>

            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label
                        class="text-text-primary mb-1 block text-sm font-medium"
                        for="csv_text"
                    >
                        Paste CSV
                    </label>
                    <textarea
                        id="csv_text"
                        v-model="form.csv_text"
                        rows="10"
                        placeholder="name,email,admin&#10;Dana Ionita,dana@example.com,false"
                        class="border-border-default bg-surface-1 text-text-primary focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md font-mono text-xs shadow-sm"
                    />
                    <p
                        v-if="form.errors.csv_text"
                        class="text-node-attack-text mt-1 text-xs"
                    >
                        {{ form.errors.csv_text }}
                    </p>
                </div>

                <div>
                    <label
                        class="text-text-primary mb-1 block text-sm font-medium"
                        for="csv_file"
                    >
                        Or upload a .csv file
                    </label>
                    <input
                        id="csv_file"
                        ref="fileInput"
                        type="file"
                        accept=".csv,text/csv"
                        class="text-text-secondary text-sm"
                        @change="onFileChange"
                    />
                    <p
                        v-if="form.errors.csv_file"
                        class="text-node-attack-text mt-1 text-xs"
                    >
                        {{ form.errors.csv_file }}
                    </p>
                </div>

                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="emit('close')"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                    >
                        Preview
                    </Button>
                </div>
            </form>
        </div>
    </Modal>
</template>
