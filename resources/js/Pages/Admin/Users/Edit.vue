<script setup lang="ts">
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    targetUser: {
        id: number;
        name: string;
        email: string;
        is_admin: boolean;
        is_active: boolean;
        two_factor_enabled: boolean;
    };
    ownedAssessmentCount: number;
    identity?: { issuer: string; last_used_at: string | null } | null;
}>();

const form = useForm({
    name: props.targetUser.name,
    email: props.targetUser.email,
    is_admin: props.targetUser.is_admin,
    is_active: props.targetUser.is_active,
});

useUnsavedChanges(() => form.isDirty);

function submit() {
    form.put(route('admin.users.update', props.targetUser.id));
}

// W116: a lost phone. The account signs in on its password again and enrols
// afresh; its other sessions end.
const showRemoveTwoFactor = ref(false);
const removeTwoFactorForm = useForm({});

function removeTwoFactor() {
    removeTwoFactorForm.delete(
        route('admin.users.two-factor.destroy', props.targetUser.id),
        { onSuccess: () => (showRemoveTwoFactor.value = false) },
    );
}

// W117: unlink from the identity provider.
const unlinkForm = useForm({});

function unlink() {
    unlinkForm.delete(
        route('admin.users.identity.destroy', props.targetUser.id),
    );
}

const showResetPassword = ref(false);
const resetForm = useForm({ password: '', password_confirmation: '' });
useUnsavedChanges(() => resetForm.isDirty);

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
            <p
                class="text-text-primary mb-2 text-sm"
                data-test="two-factor-state"
            >
                Two-factor authentication:
                {{ targetUser.two_factor_enabled ? 'on' : 'off' }}
            </p>
            <template v-if="targetUser.two_factor_enabled">
                <Button
                    v-if="!showRemoveTwoFactor"
                    variant="secondary"
                    size="sm"
                    @click="showRemoveTwoFactor = true"
                >
                    Remove second factor
                </Button>
                <div v-else class="space-y-2">
                    <p class="text-text-muted text-xs">
                        For a lost phone. Their other sessions end, and they
                        sign in with their password and set it up again.
                    </p>
                    <div class="flex gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="showRemoveTwoFactor = false"
                            >Cancel</Button
                        >
                        <Button
                            variant="danger"
                            size="sm"
                            :loading="removeTwoFactorForm.processing"
                            @click="removeTwoFactor"
                            >Remove</Button
                        >
                    </div>
                </div>
            </template>
        </div>

        <div
            v-if="identity"
            class="border-border-subtle mt-6 max-w-lg border-t pt-6"
            data-test="identity"
        >
            <p class="text-text-primary mb-2 text-sm">
                Linked to {{ identity.issuer }}
                <span v-if="identity.last_used_at" class="text-text-muted">
                    · last signed in {{ identity.last_used_at }}</span
                >
            </p>
            <Button
                variant="secondary"
                size="sm"
                :loading="unlinkForm.processing"
                @click="unlink"
                >Unlink</Button
            >
        </div>

        <div class="border-border-subtle mt-6 max-w-lg border-t pt-6">
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
                    <Button
                        variant="ghost"
                        @click="
                            showResetPassword = false;
                            resetForm.reset();
                        "
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
