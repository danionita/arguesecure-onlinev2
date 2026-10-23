<script setup lang="ts">
/**
 * The second step of signing in with a second factor (W116, D77). One field:
 * a six-digit code from the authenticator app or, for a lost phone, one of the
 * recovery codes. Which it is, the server works out.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({ code: '' });

const submit = () => {
    form.post(route('two-factor.challenge.store'), {
        onFinish: () => form.reset('code'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Two-factor authentication" />

        <p class="text-text-muted mb-4 text-sm">
            Enter the six-digit code from your authenticator app, or one of your
            recovery codes.
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <Input
                v-model="form.code"
                label="Code"
                required
                autofocus
                autocomplete="one-time-code"
                inputmode="numeric"
                :error="form.errors.code"
            />

            <div class="flex items-center justify-between pt-2">
                <Link
                    :href="route('login')"
                    class="text-text-muted text-sm underline"
                >
                    Back to sign in
                </Link>
                <Button
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                >
                    Verify
                </Button>
            </div>
        </form>
    </GuestLayout>
</template>
