<script setup lang="ts">
/**
 * The page an address-verification link opens. The button spends the link,
 * not the page: mail scanners fetch links and must not use them up.
 */
import Button from '@/Components/UI/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{ valid: boolean; token: string }>();

const form = useForm({});

function confirm() {
    form.post(route('verification.store', props.token));
}
</script>

<template>
    <GuestLayout>
        <Head title="Confirm your email address" />

        <template v-if="valid">
            <p class="text-text-secondary mb-4 text-sm">
                Confirm that this is your email address. This link works once.
            </p>
            <div class="flex justify-end">
                <Button
                    variant="primary"
                    :loading="form.processing"
                    data-testid="confirm"
                    @click="confirm"
                    >Confirm my address</Button
                >
            </div>
        </template>

        <p
            v-else
            class="text-text-secondary text-sm"
            data-testid="invalid-link"
        >
            This link has been used or has expired.
            <Link :href="route('login')" class="underline">Sign in</Link>
        </p>
    </GuestLayout>
</template>
