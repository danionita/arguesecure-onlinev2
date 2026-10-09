<script setup lang="ts">
/**
 * First-run setup (PLAN.md Phase 3 item 2). Only ever reachable once —
 * SetupController redirects to /login the instant a user row exists, and
 * this cannot be reopened by deactivating every account (that check is
 * "does any user row exist", not "does any active user exist").
 */
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('setup.store'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Set up ArgueSecure" />

        <div class="mb-4 flex items-start gap-2">
            <Icon
                name="shield"
                :size="20"
                class="text-node-root-text mt-0.5 shrink-0"
            />
            <p class="text-text-secondary text-sm">
                No account exists yet. Create the first administrator to start
                using ArgueSecure — this screen will not be reachable again once
                it is created.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <Input
                v-model="form.name"
                label="Name"
                required
                autofocus
                autocomplete="name"
                :error="form.errors.name"
            />

            <Input
                v-model="form.email"
                type="email"
                label="Email"
                required
                autocomplete="username"
                :error="form.errors.email"
            />

            <Input
                v-model="form.password"
                type="password"
                label="Password"
                help-text="At least 12 characters."
                required
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
                >
                    Create administrator
                </Button>
            </div>
        </form>
    </GuestLayout>
</template>
