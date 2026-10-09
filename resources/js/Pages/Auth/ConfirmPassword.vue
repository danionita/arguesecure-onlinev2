<script setup lang="ts">
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirm Password" />

        <p class="text-text-secondary mb-4 text-sm">
            This is a secure area of the application. Please confirm your
            password before continuing.
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <Input
                v-model="form.password"
                type="password"
                label="Password"
                required
                autocomplete="current-password"
                autofocus
                :error="form.errors.password"
            />

            <div class="flex justify-end">
                <Button
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                >
                    Confirm
                </Button>
            </div>
        </form>
    </GuestLayout>
</template>
