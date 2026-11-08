<script setup lang="ts">
/**
 * Email: the SMTP server the application sends from, and a test message.
 *
 * Off by default, and the application works without it. A field the
 * environment sets is shown read-only: it wins, and editing a value that
 * would be ignored is a control that appears to do something and does not.
 * The password is write-only; the page is only told whether one is set.
 * Mail counts as on only after a test message has been delivered with the
 * settings as they are now, so changing any of them switches it off again.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import MailTemplateEditor, {
    type MailTemplate,
} from '@/Pages/Admin/MailTemplateEditor.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Source = 'environment' | 'stored' | 'none';

interface Field {
    value: string;
    source: Source;
}

const props = defineProps<{
    mail: {
        host: Field;
        port: Field;
        encryption: Field;
        username: Field;
        from_address: Field;
        from_name: Field;
        password: { set: boolean; source: Source };
    };
    notifications: { kind: string; label: string; on: boolean }[];
    notificationTitles: boolean;
    templates: MailTemplate[];
    limits: { subject: number; body: number };
    configured: boolean;
    enabled: boolean;
}>();

const form = useForm({
    mail_host: props.mail.host.value,
    mail_port: props.mail.port.value,
    mail_encryption: props.mail.encryption.value,
    mail_username: props.mail.username.value,
    mail_password: '',
    clear_password: false,
    mail_from_address: props.mail.from_address.value,
    mail_from_name: props.mail.from_name.value,
});

useUnsavedChanges(() => form.isDirty);

const notifyForm = useForm({
    kinds: Object.fromEntries(
        props.notifications.map((n) => [n.kind, n.on]),
    ) as Record<string, boolean>,
    titles: props.notificationTitles,
});

function saveNotifications() {
    notifyForm.put(route('admin.mail.notifications'), {
        preserveScroll: true,
    });
}

const fixed = (source: Source) => source === 'environment';
const passwordFixed = computed(() => fixed(props.mail.password.source));
const plainRemote = computed(
    () =>
        form.mail_encryption === 'none' &&
        !['localhost', '127.0.0.1'].includes(form.mail_host.trim()),
);

function submit() {
    form.put(route('admin.mail.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.mail_password = '';
            form.clear_password = false;
        },
    });
}

interface TestResult {
    ok: boolean;
    message: string;
}

const testing = ref(false);
const result = ref<TestResult | null>(null);

async function sendTest() {
    testing.value = true;
    result.value = null;
    try {
        const response = await window.axios.post<TestResult>(
            route('admin.mail.test'),
        );
        result.value = response.data;
    } catch {
        result.value = {
            ok: false,
            message: 'The test could not be run. Try again in a minute.',
        };
    } finally {
        testing.value = false;
    }
}
</script>

<template>
    <Head title="Admin — Email" />

    <AdminLayout title="Email">
        <form class="max-w-xl space-y-6" @submit.prevent="submit">
            <p class="text-text-muted text-xs">
                Status:
                <strong data-testid="mail-status">{{
                    enabled
                        ? 'on'
                        : configured
                          ? 'off, send a test message to turn it on'
                          : 'off, not configured'
                }}</strong
                >. The application works without email. Saving a change here
                switches it off until the next test message is delivered.
            </p>

            <div>
                <Input
                    v-model="form.mail_host"
                    label="SMTP server"
                    placeholder="smtp.example.org"
                    :disabled="fixed(mail.host.source)"
                    :error="form.errors.mail_host"
                />
                <p
                    v-if="fixed(mail.host.source)"
                    class="text-text-muted mt-1 text-xs"
                    data-testid="env-host"
                >
                    Set by the server's environment.
                </p>
            </div>

            <div>
                <Input
                    v-model="form.mail_port"
                    label="Port"
                    placeholder="587"
                    :disabled="fixed(mail.port.source)"
                    :error="form.errors.mail_port"
                />
                <p
                    v-if="fixed(mail.port.source)"
                    class="text-text-muted mt-1 text-xs"
                >
                    Set by the server's environment.
                </p>
            </div>

            <div>
                <label
                    class="text-text-primary block text-sm font-medium"
                    for="mail-encryption"
                    >Encryption</label
                >
                <select
                    id="mail-encryption"
                    v-model="form.mail_encryption"
                    class="border-border-default bg-surface-raised mt-1 block w-full rounded-md border px-3 py-2 text-sm"
                    :disabled="fixed(mail.encryption.source)"
                >
                    <option value="starttls">
                        STARTTLS (usually port 587)
                    </option>
                    <option value="tls">TLS (usually port 465)</option>
                    <option value="none">None</option>
                </select>
                <p
                    v-if="fixed(mail.encryption.source)"
                    class="text-text-muted mt-1 text-xs"
                >
                    Set by the server's environment.
                </p>
                <p
                    v-if="plainRemote"
                    class="text-danger mt-1 text-xs"
                    role="alert"
                    data-testid="plain-warning"
                >
                    With no encryption, the password and every message cross the
                    network readable. Use this only for a relay on the same
                    machine.
                </p>
            </div>

            <div>
                <Input
                    v-model="form.mail_username"
                    label="Username"
                    :disabled="fixed(mail.username.source)"
                    :error="form.errors.mail_username"
                />
                <p
                    v-if="fixed(mail.username.source)"
                    class="text-text-muted mt-1 text-xs"
                >
                    Set by the server's environment.
                </p>
            </div>

            <div>
                <Input
                    v-model="form.mail_password"
                    type="password"
                    label="Password"
                    :placeholder="
                        mail.password.set
                            ? 'Set. Type to replace it.'
                            : 'Not set'
                    "
                    :disabled="passwordFixed || form.clear_password"
                    :error="form.errors.mail_password"
                />
                <p
                    v-if="passwordFixed"
                    class="text-text-muted mt-1 text-xs"
                    data-testid="env-password"
                >
                    Set by the server's environment.
                </p>
                <p v-else class="text-text-muted mt-1 text-xs">
                    Stored encrypted and never shown again.
                </p>
                <div v-if="!passwordFixed && mail.password.set" class="mt-2">
                    <Toggle
                        v-model="form.clear_password"
                        label="Remove the stored password"
                    />
                </div>
            </div>

            <div>
                <Input
                    v-model="form.mail_from_address"
                    label="Sender address"
                    placeholder="argue@example.org"
                    :disabled="fixed(mail.from_address.source)"
                    :error="form.errors.mail_from_address"
                />
                <p
                    v-if="fixed(mail.from_address.source)"
                    class="text-text-muted mt-1 text-xs"
                >
                    Set by the server's environment.
                </p>
            </div>

            <div>
                <Input
                    v-model="form.mail_from_name"
                    label="Sender name"
                    placeholder="ArgueSecure"
                    :disabled="fixed(mail.from_name.source)"
                    :error="form.errors.mail_from_name"
                />
                <p
                    v-if="fixed(mail.from_name.source)"
                    class="text-text-muted mt-1 text-xs"
                >
                    Set by the server's environment.
                </p>
            </div>

            <p class="text-text-muted text-xs">
                The application connects to the server named here when you send
                a test message and whenever it sends mail. Only an administrator
                can change it. SPF, DKIM and DMARC for the sender address are
                set up at your mail provider.
            </p>

            <div class="flex items-center gap-3">
                <Button
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    >Save</Button
                >
                <Button
                    type="button"
                    variant="secondary"
                    :loading="testing"
                    :disabled="form.isDirty || !configured"
                    data-testid="send-test"
                    @click="sendTest"
                    >Send test email</Button
                >
            </div>
            <p class="text-text-muted text-xs">
                The test goes to your own address only. Save first.
            </p>
            <p
                v-if="result"
                :class="result.ok ? 'text-success' : 'text-danger'"
                class="text-sm"
                role="status"
                data-testid="test-result"
            >
                {{ result.message }}
            </p>
        </form>

        <form
            class="mt-10 max-w-xl space-y-3"
            data-testid="notification-switches"
            @submit.prevent="saveNotifications"
        >
            <h3 class="text-text-primary text-base font-semibold">
                Email for notifications
            </h3>
            <p class="text-text-muted text-xs">
                Off by default. A kind you switch on is emailed to everyone who
                has not turned it off in their profile. Messages gather for ten
                minutes and go out as one, and hold a sentence and a link, never
                comment or node text.
            </p>
            <div v-for="n in notifications" :key="n.kind">
                <Toggle v-model="notifyForm.kinds[n.kind]" :label="n.label" />
            </div>
            <div>
                <Toggle
                    v-model="notifyForm.titles"
                    label="Include assessment titles"
                />
                <p class="text-text-muted mt-1 text-xs">
                    Titles then pass through the mail server and sit in
                    mailboxes. Off keeps them out.
                </p>
            </div>
            <Button
                type="submit"
                variant="primary"
                :loading="notifyForm.processing"
                >Save</Button
            >
        </form>

        <section class="mt-10 max-w-xl space-y-3" data-testid="templates">
            <h3 class="text-text-primary text-base font-semibold">
                Message wording
            </h3>
            <p class="text-text-muted text-xs">
                What each kind of message says. These are used when the
                application sends that message; editing them sends nothing.
            </p>
            <MailTemplateEditor
                v-for="template in templates"
                :key="template.kind"
                :template="template"
                :limits="limits"
            />
        </section>
    </AdminLayout>
</template>
