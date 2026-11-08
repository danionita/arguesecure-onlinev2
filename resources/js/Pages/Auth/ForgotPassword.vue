<script setup lang="ts">
/**
 * Asks for an address. The answer is the same for every address, so this page
 * never says whether an account exists.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({ email: '' });

function submit() {
    form.post(route('password.email'));
}
</script>

<template>
    <GuestLayout>
        <Head title="Forgot password" />

        <p class="text-text-secondary mb-4 text-sm">
            Enter your email address and we will send a link to choose a new
            password, if the address has an account.
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <Input
                v-model="form.email"
                type="email"
                label="Email"
                required
                autofocus
                autocomplete="username"
                :error="form.errors.email"
            />
            <div class="flex items-center justify-between pt-2">
                <Link :href="route('login')" class="text-sm underline"
                    >Back to log in</Link
                >
                <Button
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    >Send link</Button
                >
            </div>
        </form>
    </GuestLayout>
</template>
