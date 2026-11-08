<script setup lang="ts">
/**
 * Login. Self-registration is off unless an admin switches it on
 * (security.md §4); its link appears only then. "Forgot password?" appears
 * only while email is on; otherwise an administrator resets passwords.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    status?: string;
    canReset?: boolean;
    /** Self-registration is on (an admin switched it on and mail works). */
    canRegister?: boolean;
    /** The provider link, present only when one is configured. */
    sso?: { label: string; url: string; only: boolean } | null;
}>();

// A refused provider sign-in arrives as a redirect with the error in the page
// props, not as a response to this form, so `form.errors` never sees it.
const page = usePage();
const redirectError = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;
    return errors?.email;
});

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

        <p
            v-if="redirectError && !form.errors.email"
            class="text-node-attack-text mb-4 text-sm"
            role="alert"
            data-test="login-error"
        >
            {{ redirectError }}
        </p>

        <div v-if="sso" class="mb-4">
            <!--
                A plain anchor: a link, not a form (CSP form-action 'self',
                security.md), and not Button's `href`, which renders an Inertia <Link>
                that fetches the redirect by XHR and cannot follow it to
                another origin.
            -->
            <a
                :href="sso.url"
                data-test="sso-link"
                class="bg-node-root-text text-text-inverted focus-visible:outline-focus-ring inline-flex h-9 items-center justify-center gap-1.5 rounded-md border border-transparent px-3.5 text-sm font-medium transition-colors outline-none hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2"
            >
                Sign in with {{ sso.label }}
            </a>
            <p v-if="sso.only" class="text-text-muted mt-2 text-xs">
                Password sign-in is for administrators only.
            </p>
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

            <div class="flex items-center justify-between pt-2">
                <Link
                    v-if="canReset"
                    :href="route('password.request')"
                    class="text-sm underline"
                    data-test="forgot-password-link"
                    >Forgot password?</Link
                >
                <span v-else />
                <Button
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                >
                    Log in
                </Button>
            </div>
        </form>

        <p v-if="canRegister" class="mt-4 text-center text-sm">
            No account?
            <Link
                :href="route('register')"
                class="underline"
                data-test="register-link"
                >Create one</Link
            >
        </p>
    </GuestLayout>
</template>
