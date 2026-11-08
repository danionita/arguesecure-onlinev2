<script setup lang="ts">
/**
 * The page an invitation's link opens. Showing it spends nothing; the form
 * does. A spent, expired, replaced or unknown link all show the same message.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{ valid: boolean; token: string }>();

const form = useForm({ password: '', password_confirmation: '' });

function submit() {
    form.post(route('invitation.store', props.token), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <GuestLayout>
        <Head title="Choose your password" />

        <template v-if="valid">
            <p class="text-text-secondary mb-4 text-sm">
                Choose a password for your account. This link works once.
            </p>

            <form class="space-y-4" @submit.prevent="submit">
                <Input
                    v-model="form.password"
                    type="password"
                    label="Password"
                    help-text="At least 12 characters."
                    required
                    autofocus
                    autocomplete="new-password"
                    :error="form.errors.password"
                />
                <Input
                    v-model="form.password_confirmation"
                    type="password"
                    label="Confirm password"
                    required
                    autocomplete="new-password"
                />
                <div class="flex justify-end pt-2">
                    <Button
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        >Save password and sign in</Button
                    >
                </div>
            </form>
        </template>

        <div v-else data-testid="invalid-link">
            <p class="text-text-secondary text-sm">
                This link has been used, has expired, or was replaced by a newer
                one. Ask an administrator to send a new invitation, or
                <Link :href="route('login')" class="underline">sign in</Link>
                if you already have a password.
            </p>
        </div>
    </GuestLayout>
</template>
