<script setup lang="ts">
/**
 * Authentication (W133, D105-D108): the sign-in settings, in one place.
 *
 * Two switches that used to sit on Instance settings, and the OpenID Connect
 * provider that used to be reachable only through the server's environment.
 * A field the environment sets is shown read-only: it wins, and editing a
 * value that would be ignored is a control that appears to do something and
 * does not. The client secret is write-only; the page is only told whether
 * one is set.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Source = 'environment' | 'stored' | 'none';

interface Field<T> {
    value: T;
    source: Source;
}

const props = defineProps<{
    settings: { require_two_factor: boolean; sso_only: boolean };
    oidc: {
        issuer: Field<string>;
        client_id: Field<string>;
        button_label: Field<string>;
        allowed_domains: Field<string>;
        trust_email: Field<boolean>;
        client_secret: { set: boolean; source: Source };
    };
    enabled: boolean;
    redirectUri: string;
}>();

const form = useForm({
    require_two_factor: props.settings.require_two_factor,
    sso_only: props.settings.sso_only,
    oidc_issuer: props.oidc.issuer.value,
    oidc_client_id: props.oidc.client_id.value,
    oidc_client_secret: '',
    clear_client_secret: false,
    oidc_button_label:
        props.oidc.button_label.source === 'none'
            ? ''
            : props.oidc.button_label.value,
    oidc_allowed_domains: props.oidc.allowed_domains.value,
    oidc_trust_email: props.oidc.trust_email.value,
});

useUnsavedChanges(() => form.isDirty);

const fixed = (source: Source) => source === 'environment';
const secretFixed = computed(() => fixed(props.oidc.client_secret.source));

function submit() {
    form.put(route('admin.authentication.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.oidc_client_secret = '';
            form.clear_client_secret = false;
        },
    });
}

interface CheckResult {
    ok: boolean;
    message: string;
    endpoints?: { authorization: string; token: string; keys: string };
}

const checking = ref(false);
const result = ref<CheckResult | null>(null);

async function check() {
    checking.value = true;
    result.value = null;
    try {
        const response = await window.axios.post<CheckResult>(
            route('admin.authentication.check'),
        );
        result.value = response.data;
    } catch {
        result.value = {
            ok: false,
            message: 'The check could not be run. Try again in a minute.',
        };
    } finally {
        checking.value = false;
    }
}
</script>

<template>
    <Head title="Admin — Authentication" />

    <AdminLayout title="Authentication">
        <form class="max-w-xl space-y-8" @submit.prevent="submit">
            <section class="space-y-4">
                <h3 class="text-text-primary text-base font-semibold">
                    Sign-in rules
                </h3>

                <div>
                    <Toggle
                        v-model="form.require_two_factor"
                        label="Require two-factor authentication for password sign-in"
                    />
                    <p class="text-text-muted mt-1 text-xs">
                        Anyone without it is sent to set it up straight after
                        their password. Not asked after a single sign-on
                        sign-in: the provider's own second factor applies. Off
                        by default.
                    </p>
                </div>

                <div>
                    <Toggle
                        v-model="form.sso_only"
                        label="Sign in with SSO only"
                        :disabled="!enabled && !form.sso_only"
                    />
                    <p class="text-text-muted mt-1 text-xs">
                        Password sign-in stays available to administrators, as
                        the way in if the provider is down.
                        <template v-if="!enabled">
                            Needs a provider below first.
                        </template>
                    </p>
                    <p
                        v-if="form.errors.sso_only"
                        class="text-danger mt-1 text-xs"
                        role="alert"
                    >
                        {{ form.errors.sso_only }}
                    </p>
                </div>
            </section>

            <section class="space-y-4" data-testid="sso-section">
                <div>
                    <h3 class="text-text-primary text-base font-semibold">
                        Single sign-on (OpenID Connect)
                    </h3>
                    <p class="text-text-muted mt-1 text-xs">
                        Register this redirect URI at your provider:
                        <code
                            class="text-text-primary"
                            data-testid="redirect-uri"
                            >{{ redirectUri }}</code
                        >. The status is
                        <strong data-testid="sso-status">{{
                            enabled ? 'configured' : 'not configured'
                        }}</strong
                        >.
                    </p>
                </div>

                <div>
                    <Input
                        v-model="form.oidc_issuer"
                        label="Issuer"
                        placeholder="https://login.example.org/realms/main"
                        :disabled="fixed(oidc.issuer.source)"
                        :error="form.errors.oidc_issuer"
                    />
                    <p
                        v-if="fixed(oidc.issuer.source)"
                        class="text-text-muted mt-1 text-xs"
                        data-testid="env-issuer"
                    >
                        Set by the server's environment.
                    </p>
                </div>

                <div>
                    <Input
                        v-model="form.oidc_client_id"
                        label="Client ID"
                        :disabled="fixed(oidc.client_id.source)"
                        :error="form.errors.oidc_client_id"
                    />
                    <p
                        v-if="fixed(oidc.client_id.source)"
                        class="text-text-muted mt-1 text-xs"
                    >
                        Set by the server's environment.
                    </p>
                </div>

                <div>
                    <Input
                        v-model="form.oidc_client_secret"
                        type="password"
                        label="Client secret"
                        :placeholder="
                            oidc.client_secret.set
                                ? 'Set. Type to replace it.'
                                : 'Not set'
                        "
                        :disabled="secretFixed || form.clear_client_secret"
                        :error="form.errors.oidc_client_secret"
                    />
                    <p
                        v-if="secretFixed"
                        class="text-text-muted mt-1 text-xs"
                        data-testid="env-secret"
                    >
                        Set by the server's environment.
                    </p>
                    <p v-else class="text-text-muted mt-1 text-xs">
                        Stored encrypted and never shown again.
                    </p>
                    <div
                        v-if="!secretFixed && oidc.client_secret.set"
                        class="mt-2"
                    >
                        <Toggle
                            v-model="form.clear_client_secret"
                            label="Remove the stored secret"
                        />
                    </div>
                </div>

                <div>
                    <Input
                        v-model="form.oidc_button_label"
                        label="Sign-in button label"
                        placeholder="single sign-on"
                        :disabled="fixed(oidc.button_label.source)"
                        :error="form.errors.oidc_button_label"
                    />
                    <p
                        v-if="fixed(oidc.button_label.source)"
                        class="text-text-muted mt-1 text-xs"
                    >
                        Set by the server's environment.
                    </p>
                </div>

                <div>
                    <Input
                        v-model="form.oidc_allowed_domains"
                        label="Email domains that may create an account"
                        placeholder="example.org, example.com"
                        help-text="A first sign-in from one of these creates a non-admin account that sees nothing until something is shared with it. Empty creates none."
                        :disabled="fixed(oidc.allowed_domains.source)"
                        :error="form.errors.oidc_allowed_domains"
                    />
                    <p
                        v-if="fixed(oidc.allowed_domains.source)"
                        class="text-text-muted mt-1 text-xs"
                    >
                        Set by the server's environment.
                    </p>
                </div>

                <div>
                    <Toggle
                        v-model="form.oidc_trust_email"
                        label="Trust the provider's email address"
                        :disabled="fixed(oidc.trust_email.source)"
                    />
                    <p class="text-text-muted mt-1 text-xs">
                        Only for a provider you control that does not send
                        <code>email_verified</code>. Never for a multi-tenant
                        app.
                        <template v-if="fixed(oidc.trust_email.source)">
                            Set by the server's environment.
                        </template>
                    </p>
                </div>
            </section>

            <div class="flex items-center justify-end gap-3 pt-2">
                <Button
                    type="button"
                    variant="secondary"
                    :loading="checking"
                    data-testid="check-provider"
                    @click="check"
                    >Check provider</Button
                >
                <Button
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    >Save</Button
                >
            </div>

            <p
                v-if="result"
                class="text-sm"
                :class="result.ok ? 'text-success' : 'text-danger'"
                role="status"
                data-testid="check-result"
            >
                {{ result.message }}
                <span v-if="result.endpoints" class="text-text-muted block">
                    Checks the saved settings, not unsaved edits.
                </span>
            </p>
        </form>
    </AdminLayout>
</template>
