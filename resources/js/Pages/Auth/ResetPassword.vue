<script setup lang="ts">
/**
 * The page a reset link opens. Showing it spends nothing; the form does. A
 * spent, expired, replaced or unknown link all show the same message.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{ valid: boolean; token: string }>();

const form = useForm({ password: '', password_confirmation: '' });

function submit() {
    form.post(route('password.reset.store', props.token), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <GuestLayout>
        <Head title="Choose a new password" />

        <template v-if="valid">
            <p class="text-text-secondary mb-4 text-sm">
                Choose a new password. You will be signed out everywhere else,
                and this link works once.
            </p>

            <form class="space-y-4" @submit.prevent="submit">
                <Input
                    v-model="form.password"
                    type="password"
                    label="New password"
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
                        >Save password</Button
                    >
                </div>
            </form>
        </template>

        <div v-else data-testid="invalid-link">
            <p class="text-text-secondary text-sm">
                This link has been used, has expired, or was replaced by a newer
                one.
                <Link :href="route('password.request')" class="underline"
                    >Ask for a new one</Link
                >
                or
                <Link :href="route('login')" class="underline">sign in</Link>.
            </p>
        </div>
    </GuestLayout>
</template>
