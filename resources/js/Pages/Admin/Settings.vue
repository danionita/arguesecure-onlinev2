<script setup lang="ts">
/**
 * Instance settings: facts about the deployment (2.5.1).
 *
 * Three things left this page. The default currency went to the Defaults tab
 * in W87 (D47); `default_scale_calibration` is gone — there is one default
 * scale set and the Defaults tab edits its bands directly; and the
 * assistant's switches moved to their own tab, where "not installed" is a
 * state of the page rather than a paragraph of docker commands at the bottom
 * of this one.
 */
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import AdminLayout from '@/Pages/Admin/AdminLayout.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    settings: {
        public_assessments_allowed: boolean;
        session_lifetime_minutes: number;
    };
}>();

const form = useForm({ ...props.settings });

useUnsavedChanges(() => form.isDirty);

function submit() {
    form.put(route('admin.settings.update'));
}
</script>

<template>
    <Head title="Admin — Instance settings" />

    <AdminLayout title="Instance settings">
        <form class="max-w-lg space-y-4" @submit.prevent="submit">
            <Toggle
                v-model="form.public_assessments_allowed"
                label="Allow public assessments"
            />

            <Input
                v-model.number="form.session_lifetime_minutes"
                type="number"
                label="Session lifetime (minutes)"
                :error="form.errors.session_lifetime_minutes"
            />

            <!--
                Require two-factor and SSO-only moved to the Authentication tab
                (W133, D105).
            -->

            <!--
                No "registration locked" control here: CLAUDE.md is explicit
                that there is no public registration, ever — accounts are
                admin-created — so there is nothing for such a toggle to
                gate. A control that looks like it does something and
                doesn't is worse than no control (W8b).
            -->

            <div class="flex justify-end pt-2">
                <Button
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    >Save</Button
                >
            </div>
        </form>
    </AdminLayout>
</template>
