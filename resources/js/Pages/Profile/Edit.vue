<script setup lang="ts">
import Button from '@/Components/UI/Button.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Input from '@/Components/UI/Input.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface SessionRow {
    id: string;
    ip_address: string | null;
    user_agent: string | null;
    last_active_at: string;
    is_current: boolean;
}

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
    sessions: SessionRow[];
    ownedAssessmentCount: number;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

// Profile details ------------------------------------------------------
const profileForm = useForm({
    name: user.value.name,
    email: user.value.email,
    current_password: '',
});

const emailChanged = computed(() => profileForm.email !== user.value.email);

function submitProfile() {
    profileForm.patch(route('profile.update'), {
        preserveScroll: true,
        onSuccess: () => {
            profileForm.reset('current_password');
        },
    });
}

// Password ---------------------------------------------------------------
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submitPassword() {
    passwordForm.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        onError: () => {
            passwordForm.reset(
                'current_password',
                'password',
                'password_confirmation',
            );
        },
    });
}

// Sessions -----------------------------------------------------------
function revoke(sessionId: string) {
    router.delete(route('profile.sessions.destroy', sessionId), {
        preserveScroll: true,
    });
}

// Delete account ------------------------------------------------------
const showDeleteConfirm = ref(false);
const deleteForm = useForm({ password: '' });

function submitDelete() {
    deleteForm.delete(route('profile.destroy'), {
        onSuccess: () => {
            showDeleteConfirm.value = false;
        },
        onError: () => {
            showDeleteConfirm.value = false;
        },
    });
}
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-text-primary text-xl leading-tight font-semibold">
                Profile
            </h2>
        </template>

        <div class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
            <!-- Profile details -->
            <section
                class="border-border-subtle bg-surface-1 rounded-lg border p-6"
            >
                <h3 class="text-text-primary mb-4 text-base font-semibold">
                    Profile information
                </h3>
                <div
                    v-if="status"
                    class="text-status-implemented-text mb-3 text-sm"
                >
                    {{ status }}
                </div>

                <form class="space-y-4" @submit.prevent="submitProfile">
                    <Input
                        v-model="profileForm.name"
                        label="Name"
                        required
                        :error="profileForm.errors.name"
                    />
                    <Input
                        v-model="profileForm.email"
                        type="email"
                        label="Email"
                        required
                        :error="profileForm.errors.email"
                    />
                    <Input
                        v-if="emailChanged"
                        v-model="profileForm.current_password"
                        type="password"
                        label="Current password"
                        help-text="Required to change your email."
                        :error="profileForm.errors.current_password"
                    />

                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            variant="primary"
                            :loading="profileForm.processing"
                        >
                            Save
                        </Button>
                    </div>
                </form>
            </section>

            <!-- Password -->
            <section
                class="border-border-subtle bg-surface-1 rounded-lg border p-6"
            >
                <h3 class="text-text-primary mb-4 text-base font-semibold">
                    Change password
                </h3>

                <form class="space-y-4" @submit.prevent="submitPassword">
                    <Input
                        v-model="passwordForm.current_password"
                        type="password"
                        label="Current password"
                        required
                        autocomplete="current-password"
                        :error="passwordForm.errors.current_password"
                    />
                    <Input
                        v-model="passwordForm.password"
                        type="password"
                        label="New password"
                        help-text="At least 12 characters."
                        required
                        autocomplete="new-password"
                        :error="passwordForm.errors.password"
                    />
                    <Input
                        v-model="passwordForm.password_confirmation"
                        type="password"
                        label="Confirm new password"
                        required
                        autocomplete="new-password"
                    />

                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            variant="primary"
                            :loading="passwordForm.processing"
                        >
                            Update password
                        </Button>
                    </div>
                </form>
            </section>

            <!-- Sessions -->
            <section
                class="border-border-subtle bg-surface-1 rounded-lg border p-6"
            >
                <h3 class="text-text-primary mb-4 text-base font-semibold">
                    Active sessions
                </h3>
                <ul class="divide-border-subtle divide-y text-sm">
                    <li
                        v-for="session in sessions"
                        :key="session.id"
                        class="flex items-center justify-between py-2"
                    >
                        <div>
                            <p class="text-text-primary">
                                {{ session.ip_address ?? 'Unknown IP' }}
                                <span
                                    v-if="session.is_current"
                                    class="text-text-muted"
                                >
                                    — this device</span
                                >
                            </p>
                            <p class="text-text-muted text-xs">
                                {{ session.user_agent ?? 'Unknown device' }} ·
                                last active
                                {{ session.last_active_at }}
                            </p>
                        </div>
                        <Button
                            v-if="!session.is_current"
                            variant="ghost"
                            size="sm"
                            @click="revoke(session.id)"
                        >
                            Revoke
                        </Button>
                    </li>
                </ul>
            </section>

            <!-- Delete account -->
            <section
                class="border-node-attack-border bg-surface-1 rounded-lg border p-6"
            >
                <h3 class="text-text-primary mb-2 text-base font-semibold">
                    Delete account
                </h3>
                <p class="text-text-muted mb-4 text-sm">
                    This permanently deletes your account
                    <template v-if="ownedAssessmentCount > 0">
                        and the {{ ownedAssessmentCount }} assessment(s) you own
                        — they cannot be transferred from here; ask an
                        administrator first if you want them kept.
                    </template>
                    <template v-else> — you own no assessments. </template>
                </p>
                <Button variant="danger" @click="showDeleteConfirm = true"
                    >Delete account</Button
                >
            </section>
        </div>

        <ConfirmDialog
            :show="showDeleteConfirm"
            title="Delete your account"
            danger
            confirm-label="Delete permanently"
            :loading="deleteForm.processing"
            @cancel="showDeleteConfirm = false"
            @confirm="submitDelete"
        >
            <div class="space-y-3">
                <p>
                    This cannot be undone
                    <template v-if="ownedAssessmentCount > 0">
                        and will also delete the {{ ownedAssessmentCount }}
                        assessment(s) you own.
                    </template>
                </p>
                <Input
                    v-model="deleteForm.password"
                    type="password"
                    label="Confirm your password"
                    :error="deleteForm.errors.password"
                />
            </div>
        </ConfirmDialog>
    </AuthenticatedLayout>
</template>
