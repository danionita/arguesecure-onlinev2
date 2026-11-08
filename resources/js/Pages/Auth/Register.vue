<script setup lang="ts">
/**
 * Self-registration (security.md §4). Reachable only while an admin has
 * switched it on and mail works. It asks for an address and nothing else;
 * name and password are chosen after the emailed link is followed. Submitting
 * never says whether the address already has an account: the same message
 * follows in every case.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    domains: string[];
    sent?: boolean;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('register.store'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Create an account" />

        <div v-if="sent" data-test="registration-sent" class="space-y-4">
            <p class="text-sm">
                If the address can be registered, a message with a confirmation
                link is on its way. You choose your password after you follow
                it.
            </p>
            <Link :href="route('login')" class="text-sm underline"
                >Back to log in</Link
            >
        </div>

        <form v-else class="space-y-4" @submit.prevent="submit">
            <p class="text-text-secondary text-sm">
                Enter your email address. We send you a link, and you choose
                your password after you follow it.
                <template v-if="domains.length"
                    >Open to addresses at {{ domains.join(', ') }}.</template
                >
            </p>

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
                    >Send the link</Button
                >
            </div>
        </form>
    </GuestLayout>
</template>
