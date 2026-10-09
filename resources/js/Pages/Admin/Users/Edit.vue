<script setup lang="ts">
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    targetUser: {
        id: number;
        name: string;
        email: string;
        is_admin: boolean;
        is_active: boolean;
    };
    ownedAssessmentCount: number;
}>();

const form = useForm({
    name: props.targetUser.name,
    email: props.targetUser.email,
    is_admin: props.targetUser.is_admin,
    is_active: props.targetUser.is_active,
});

function submit() {
    form.put(route('admin.users.update', props.targetUser.id));
}

const showResetPassword = ref(false);
const resetForm = useForm({ password: '', password_confirmation: '' });

function submitReset() {
    resetForm.post(route('admin.users.reset-password', props.targetUser.id), {
        onSuccess: () => {
            showResetPassword.value = false;
            resetForm.reset();
        },
    });
}
</script>

<template>
    <Head :title="`Admin — Edit ${targetUser.name}`" />

    <AdminLayout :title="`Edit ${targetUser.name}`">
        <form class="max-w-lg space-y-4" @submit.prevent="submit">
            <Input
                v-model="form.name"
                label="Name"
                required
                :error="form.errors.name"
            />
            <Input
                v-model="form.email"
                type="email"
                label="Email"
                required
                :error="form.errors.email"
            />

            <Toggle v-model="form.is_admin" label="Administrator access" />
            <Toggle v-model="form.is_active" label="Account active" />

            <p v-if="ownedAssessmentCount > 0" class="text-text-muted text-xs">
                Owns {{ ownedAssessmentCount }} assessment(s).
            </p>

            <div class="flex justify-end gap-2 pt-2">
                <Button variant="ghost" :href="route('admin.users.index')"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    >Save</Button
                >
            </div>
        </form>

        <div class="border-border-subtle mt-8 max-w-lg border-t pt-6">
            <Button icon="lock" @click="showResetPassword = true"
                >Set / reset password</Button
            >

            <form
                v-if="showResetPassword"
                class="mt-4 space-y-4"
                @submit.prevent="submitReset"
            >
                <Input
                    v-model="resetForm.password"
                    type="password"
                    label="New password"
                    help-text="Leave blank to generate one — it will be shown once after saving."
                    autocomplete="new-password"
                    :error="resetForm.errors.password"
                />
                <Input
                    v-model="resetForm.password_confirmation"
                    type="password"
                    label="Confirm new password"
                    autocomplete="new-password"
                />
                <div class="flex gap-2">
                    <Button variant="ghost" @click="showResetPassword = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        variant="primary"
                        :loading="resetForm.processing"
                    >
                        Save password
                    </Button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
