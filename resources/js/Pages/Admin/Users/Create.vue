<script setup lang="ts">
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    is_admin: false,
});

function submit() {
    form.post(route('admin.users.store'));
}
</script>

<template>
    <Head title="Admin — New user" />

    <AdminLayout title="New user">
        <form class="max-w-lg space-y-4" @submit.prevent="submit">
            <Input
                v-model="form.name"
                label="Name"
                required
                autofocus
                :error="form.errors.name"
            />
            <Input
                v-model="form.email"
                type="email"
                label="Email"
                required
                :error="form.errors.email"
            />

            <Input
                v-model="form.password"
                type="password"
                label="Password"
                help-text="Leave blank to generate one — it will be shown once after creation."
                autocomplete="new-password"
                :error="form.errors.password"
            />
            <Input
                v-model="form.password_confirmation"
                type="password"
                label="Confirm password"
                autocomplete="new-password"
            />

            <Toggle
                v-model="form.is_admin"
                label="Grant administrator access"
            />

            <div class="flex justify-end gap-2 pt-2">
                <Button variant="ghost" :href="route('admin.users.index')"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    >Create user</Button
                >
            </div>
        </form>
    </AdminLayout>
</template>
