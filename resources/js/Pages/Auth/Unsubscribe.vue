<script setup lang="ts">
/**
 * The page an "unsubscribe" link in an email opens. The button turns the kind
 * off; showing the page does nothing, so a mail scanner that fetches the link
 * unsubscribes nobody.
 */
import Button from '@/Components/UI/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{ label: string; action: string }>();

const form = useForm({});

function confirm() {
    form.post(props.action);
}
</script>

<template>
    <GuestLayout>
        <Head title="Stop these emails" />

        <p class="text-text-secondary mb-4 text-sm">
            Stop emails for: <strong>{{ label }}</strong
            >. You will still see these in the application, and you can turn the
            emails back on in your profile.
        </p>
        <div class="flex justify-end">
            <Button
                variant="primary"
                :loading="form.processing"
                data-testid="unsubscribe"
                @click="confirm"
                >Stop these emails</Button
            >
        </div>
    </GuestLayout>
</template>
