<script setup lang="ts">
/**
 * Assignees tab (W46, item 16; PLAN-2.2.md D13).
 *
 * Self-fetching, like ReferencesTab and CommentsTab: assignments are not part
 * of `assessmentStore`'s state, because nothing outside this tab reads them.
 * Assignees are deliberately **not** on chart or list cards — a card says what
 * a node is and what it is worth, and W36/W37 spent two packages taking things
 * off it.
 *
 * ## Sharing is a separate, named step (D13)
 *
 * The picker lists people who are already on the assessment. Naming anyone
 * else is refused by the server, and the only way through is to ask, in the
 * same request, to share the assessment with them at `viewer` — which needs
 * `manage`, not the `update` that assigning needs.
 *
 * This tab therefore never sends `share_as_viewer` on its own. Adding someone
 * new goes through the Share dialog, where the roles are explained and the
 * public/link settings are visible; a silent share is a data disclosure the
 * user did not ask for, and a dropdown that quietly grants read access to a
 * whole assessment is exactly that.
 */
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Select from '@/Components/UI/Select.vue';
import { useToast } from '@/Components/UI/useToast';
import type { AssessmentStore } from '@/stores/assessmentStore';
import { computed, onMounted, ref, watch } from 'vue';

interface Person {
    id: number;
    name: string;
}

const props = defineProps<{
    store: AssessmentStore;
    uuid: string;
}>();

const { push } = useToast();

const assignees = ref<Person[]>([]);
const candidates = ref<Person[]>([]);
const selected = ref<string>('');
const loading = ref(false);
const busy = ref(false);
const canAssign = ref(false);
const canShare = ref(false);

/** Everyone on the assessment who is not already on this node. */
const options = computed(() => {
    const taken = new Set(assignees.value.map((person) => person.id));

    return candidates.value
        .filter((person) => !taken.has(person.id))
        .map((person) => ({ value: String(person.id), label: person.name }));
});

async function load() {
    loading.value = true;
    try {
        const response = await window.axios.get(
            route('assessments.nodes.assignees.index', [
                props.store.assessment.uuid,
                props.uuid,
            ]),
        );
        assignees.value = response.data.assignees;
        canAssign.value = response.data.canAssign;
        canShare.value = response.data.canShare;

        // Only fetched when the caller can actually act on it: the endpoint
        // needs `update`, and asking for it as a viewer would be a guaranteed
        // 403 on every node they open.
        if (canAssign.value && candidates.value.length === 0) {
            const people = await window.axios.get(
                route(
                    'assessments.assignee-candidates.index',
                    props.store.assessment.uuid,
                ),
            );
            candidates.value = people.data.users;
        }
    } catch {
        push('Could not load assignees.', 'danger');
    } finally {
        loading.value = false;
    }
}

async function assign() {
    if (selected.value === '') return;

    busy.value = true;
    try {
        const response = await window.axios.post(
            route('assessments.nodes.assignees.store', [
                props.store.assessment.uuid,
                props.uuid,
            ]),
            // No `share_as_viewer`, deliberately. See the docblock: sharing
            // happens in the Share dialog, never as a side effect here.
            { user_id: Number(selected.value) },
        );
        assignees.value = response.data.assignees;
        selected.value = '';
    } catch (error) {
        const message =
            (error as { response?: { data?: { message?: string } } })?.response
                ?.data?.message ?? 'That assignment could not be saved.';
        push(message, 'danger');
    } finally {
        busy.value = false;
    }
}

async function unassign(person: Person) {
    busy.value = true;
    try {
        const response = await window.axios.delete(
            route('assessments.nodes.assignees.destroy', [
                props.store.assessment.uuid,
                props.uuid,
                person.id,
            ]),
        );
        assignees.value = response.data.assignees;
    } catch {
        push('That assignment could not be removed.', 'danger');
    } finally {
        busy.value = false;
    }
}

onMounted(load);
watch(() => props.uuid, load);
</script>

<template>
    <div class="space-y-4 p-4">
        <p v-if="loading" class="text-text-muted text-sm">Loading…</p>

        <template v-else>
            <ul v-if="assignees.length" class="space-y-2">
                <li
                    v-for="person in assignees"
                    :key="person.id"
                    class="border-border-subtle bg-surface-1 flex items-center justify-between gap-3 rounded-md border px-3 py-2"
                >
                    <span class="text-text-primary text-sm">{{
                        person.name
                    }}</span>
                    <IconButton
                        v-if="canAssign"
                        icon="x"
                        :label="`Unassign ${person.name}`"
                        :disabled="busy"
                        @click="unassign(person)"
                    />
                </li>
            </ul>

            <EmptyState
                v-else
                icon="user"
                title="Nobody is assigned"
                description="Assigning someone puts this node on their dashboard with what it still needs. It does not change what they are allowed to do."
            />

            <div v-if="canAssign" class="flex items-end gap-2">
                <Select
                    v-model="selected"
                    label="Assign someone"
                    class="flex-1"
                    :options="options"
                    placeholder="Choose a collaborator"
                    :disabled="busy || options.length === 0"
                />
                <Button
                    variant="secondary"
                    :disabled="busy || selected === ''"
                    @click="assign"
                >
                    Assign
                </Button>
            </div>

            <!--
                The one place D13's rule is explained rather than merely
                enforced. Someone looking for a colleague who is not in the
                list needs to know why, and where to go — otherwise the list
                just looks incomplete.
            -->
            <p v-if="canAssign" class="text-text-muted text-xs">
                Only people who are already on this assessment can be assigned.
                <template v-if="canShare">
                    To add someone, share it with them first — the Share dialog
                    explains what each role can do.
                </template>
                <template v-else>
                    To add someone, ask a manager or the owner to share it with
                    them first.
                </template>
            </p>
        </template>
    </div>
</template>
