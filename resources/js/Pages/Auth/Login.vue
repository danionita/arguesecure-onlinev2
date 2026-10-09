<script setup lang="ts">
/**
 * Login only — no self-service registration exists anywhere in the app
 * (CLAUDE.md, security.md §4) and no self-service reset: accounts are
 * admin-managed, so an admin resets passwords instead.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <div
            v-if="status"
            class="text-status-implemented-text mb-4 text-sm font-medium"
        >
            {{ status }}
        </div>

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

            <Input
                v-model="form.password"
                type="password"
                label="Password"
                required
                autocomplete="current-password"
                :error="form.errors.password"
            />

            <Toggle v-model="form.remember" label="Remember me" />

            <div class="flex items-center justify-end pt-2">
                <Button
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                >
                    Log in
                </Button>
            </div>
        </form>
    </GuestLayout>
</template>
