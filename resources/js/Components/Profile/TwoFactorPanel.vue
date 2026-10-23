<script setup lang="ts">
/**
 * The profile page's *Two-factor authentication* panel (W116, D77, D78).
 *
 * Three states of one panel: off (with a password box to start), an
 * enrolment waiting for its first code (QR and the secret as text), and on
 * (recovery codes left, new codes, turn off). Every secret shown here comes
 * from the server for the current enrolment only; the stored one never
 * reaches the page.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

export interface TwoFactorState {
    enabled: boolean;
    recovery_codes_remaining: number;
    required: boolean;
    pending: { secret: string; qr: string } | null;
}

defineProps<{
    twoFactor: TwoFactorState;
    /** Plain-text recovery codes, flashed once by the action that made them. */
    recoveryCodes?: string[] | null;
}>();

const startForm = useForm({ password: '' });
const confirmForm = useForm({ code: '' });
const regenerateForm = useForm({ password: '', code: '' });
const disableForm = useForm({ password: '', code: '' });

const showRegenerate = ref(false);
const showDisable = ref(false);

function start() {
    startForm.post(route('profile.two-factor.store'), {
        preserveScroll: true,
        onFinish: () => startForm.reset(),
    });
}

function confirm() {
    confirmForm.post(route('profile.two-factor.confirm'), {
        preserveScroll: true,
        onSuccess: () => confirmForm.reset(),
    });
}

function cancel() {
    confirmForm.reset();
    confirmForm.delete(route('profile.two-factor.cancel'), {
        preserveScroll: true,
    });
}

function regenerate() {
    regenerateForm.post(route('profile.two-factor.recovery-codes'), {
        preserveScroll: true,
        onSuccess: () => {
            regenerateForm.reset();
            showRegenerate.value = false;
        },
        onError: () => regenerateForm.reset(),
    });
}

function disable() {
    disableForm.delete(route('profile.two-factor.destroy'), {
        preserveScroll: true,
        onSuccess: () => {
            disableForm.reset();
            showDisable.value = false;
        },
        onError: () => disableForm.reset(),
    });
}
</script>

<template>
    <section
        class="border-border-subtle bg-surface-1 rounded-lg border p-6"
        aria-labelledby="two-factor-heading"
    >
        <h3
            id="two-factor-heading"
            class="text-text-primary mb-2 text-base font-semibold"
        >
            Two-factor authentication
        </h3>

        <!-- Recovery codes: on the page once, straight after they are made. -->
        <div
            v-if="recoveryCodes && recoveryCodes.length"
            class="border-border-subtle bg-surface-2 mb-4 rounded-md border p-4"
            data-test="recovery-codes"
        >
            <p class="text-text-primary text-sm font-medium">
                Save these recovery codes now.
            </p>
            <p class="text-text-muted mb-3 text-xs">
                Each works once, in place of a code from your app. They are not
                shown again.
            </p>
            <ul
                class="text-text-primary grid grid-cols-2 gap-1 font-mono text-sm"
            >
                <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
            </ul>
        </div>

        <!-- On -->
        <template v-if="twoFactor.enabled">
            <p class="text-status-implemented-text mb-1 text-sm font-medium">
                On: signing in with a password also asks for a code from your
                authenticator app.
            </p>
            <p class="text-text-muted mb-4 text-sm" data-test="codes-left">
                {{ twoFactor.recovery_codes_remaining }} recovery code{{
                    twoFactor.recovery_codes_remaining === 1 ? '' : 's'
                }}
                left.
            </p>

            <div class="flex flex-wrap gap-2">
                <Button
                    variant="secondary"
                    size="sm"
                    @click="showRegenerate = !showRegenerate"
                >
                    New recovery codes
                </Button>
                <Button
                    variant="danger"
                    size="sm"
                    @click="showDisable = !showDisable"
                >
                    Turn off
                </Button>
            </div>

            <form
                v-if="showRegenerate"
                class="mt-4 space-y-3"
                @submit.prevent="regenerate"
            >
                <Input
                    v-model="regenerateForm.password"
                    type="password"
                    label="Current password"
                    help-text="The codes you have now stop working."
                    autocomplete="current-password"
                    :error="regenerateForm.errors.password"
                />
                <Input
                    v-model="regenerateForm.code"
                    label="Code from your app"
                    autocomplete="one-time-code"
                    :error="regenerateForm.errors.code"
                />
                <Button
                    type="submit"
                    variant="primary"
                    :loading="regenerateForm.processing"
                >
                    Make new codes
                </Button>
            </form>

            <form
                v-if="showDisable"
                class="mt-4 space-y-3"
                @submit.prevent="disable"
            >
                <Input
                    v-model="disableForm.password"
                    type="password"
                    label="Current password"
                    autocomplete="current-password"
                    :error="disableForm.errors.password"
                />
                <Input
                    v-model="disableForm.code"
                    label="Code from your app, or a recovery code"
                    autocomplete="one-time-code"
                    :error="disableForm.errors.code"
                />
                <p v-if="twoFactor.required" class="text-text-muted text-xs">
                    Your administrator requires two-factor authentication; you
                    will be asked to set it up again.
                </p>
                <Button
                    type="submit"
                    variant="danger"
                    :loading="disableForm.processing"
                >
                    Turn off two-factor authentication
                </Button>
            </form>
        </template>

        <!-- Enrolment in progress -->
        <template v-else-if="twoFactor.pending">
            <p class="text-text-muted mb-3 text-sm">
                Scan this with an authenticator app, or type the key into it,
                then enter the six-digit code it shows. Nothing is turned on
                until you do.
            </p>
            <div class="mb-4 flex flex-wrap items-center gap-4">
                <img
                    :src="twoFactor.pending.qr"
                    alt="QR code for your authenticator app"
                    width="192"
                    height="192"
                    class="rounded-md bg-white"
                />
                <div>
                    <p class="text-text-muted text-xs">Key</p>
                    <p
                        class="text-text-primary font-mono text-sm break-all"
                        data-test="secret"
                    >
                        {{ twoFactor.pending.secret }}
                    </p>
                </div>
            </div>
            <form class="space-y-3" @submit.prevent="confirm">
                <Input
                    v-model="confirmForm.code"
                    label="Six-digit code"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    :error="confirmForm.errors.code"
                />
                <div class="flex gap-2">
                    <Button
                        type="submit"
                        variant="primary"
                        :loading="confirmForm.processing"
                    >
                        Turn on
                    </Button>
                    <Button variant="ghost" @click="cancel">Cancel</Button>
                </div>
            </form>
        </template>

        <!-- Off -->
        <template v-else>
            <p v-if="twoFactor.required" class="text-text-primary mb-2 text-sm">
                Your administrator requires two-factor authentication for
                password sign-in. Set it up to continue.
            </p>
            <p class="text-text-muted mb-4 text-sm">
                Add a code from an authenticator app (any app that supports
                time-based one-time passwords) to your password. It works
                offline and needs no phone number or email.
            </p>
            <form class="space-y-3" @submit.prevent="start">
                <Input
                    v-model="startForm.password"
                    type="password"
                    label="Current password"
                    autocomplete="current-password"
                    :error="startForm.errors.password"
                />
                <Button
                    type="submit"
                    variant="primary"
                    :loading="startForm.processing"
                >
                    Set up
                </Button>
            </form>
        </template>
    </section>
</template>
