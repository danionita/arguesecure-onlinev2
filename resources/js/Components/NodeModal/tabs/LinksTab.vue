<script setup lang="ts">
/**
 * The **Strength** tab (W39, item 9) — still `LinksTab.vue`, still keyed
 * `links`, and reachable now only from a defence.
 *
 * Renaming the file and the key would break every `?tab=links` deep link that
 * has already been shared, to buy nothing a reader sees; the label is the
 * part users read, and the label is what changed. The risk and attack
 * branches below are unreachable from the modal today and are kept rather
 * than deleted: W40 needs exactly this "add an existing node" machinery for
 * its autocomplete, and the risk_attack detach it also implements moved to
 * DetailsTab, not away.
 *
 * ---
 *
 * Original W13/W31 docblock, still accurate for the defence branch:
 *
 * Links tab (ui.md §7): "For attacks: which risks, which defences, with
 * per-link strength and reduction target. For defences: which attacks. Add
 * and remove links here." A risk's own occurrence here is symmetric —
 * which attacks it contains — even though ui.md's own two bullets only
 * name attack/defence explicitly; the same edge (risk_attack) is edited
 * from either end, and a risk needs an "add existing attack" affordance
 * exactly like an attack needs "add existing risk", or a risk could only
 * ever gain attacks by creating brand new ones (store.addChild()), never
 * by reusing one that already exists elsewhere in the assessment.
 *
 * W31 finishes that symmetry for the attack_defence edge: the strength,
 * reduction target and rationale are edited from **both** ends through the
 * same LinkStrengthEditor. A defence's tab used to show a read-only
 * percentage and tell the reader to go open the attack instead, which is
 * the legacy indirection this rewrite exists to remove.
 */
import Combobox from '@/Components/UI/Combobox.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import type { AssessmentStore } from '@/stores/assessmentStore';
import { computed } from 'vue';
import LinkStrengthEditor from '../LinkStrengthEditor.vue';
import { useLinkMutations } from '../useLinkMutations';
import { useNodeModal } from '../useNodeModal';

const props = defineProps<{
    store: AssessmentStore;
    uuid: string;
}>();

const node = computed(() => props.store.nodes[props.uuid]);
// False while someone else has this node open (2.5.1, useNodeModal).
const { lockedBy } = useNodeModal();
const canEdit = computed(
    () => props.store.abilities.update && lockedBy.value === null,
);
const mutations = useLinkMutations(props.store);

function titleOf(uuid: string): string {
    return props.store.nodes[uuid]?.title || 'Untitled';
}

// --- risk <-> attack (either end) ------------------------------------------

const linkedAttackUuids = computed(() =>
    node.value?.type === 'risk' ? props.store.attacksUnderRisk(props.uuid) : [],
);
const linkedRiskUuids = computed(() =>
    node.value?.type === 'attack' ? props.store.risksForAttack(props.uuid) : [],
);

const availableAttacksForRisk = computed(() =>
    Object.values(props.store.nodes)
        .filter(
            (n) =>
                n.type === 'attack' &&
                !linkedAttackUuids.value.includes(n.uuid),
        )
        .map((n) => ({ value: n.uuid, label: n.title || 'Untitled attack' })),
);
const availableRisksForAttack = computed(() =>
    Object.values(props.store.nodes)
        .filter(
            (n) => n.type === 'risk' && !linkedRiskUuids.value.includes(n.uuid),
        )
        .map((n) => ({ value: n.uuid, label: n.title || 'Untitled risk' })),
);

async function addAttackToRisk(attackUuid: string) {
    await mutations.linkRiskToAttack(props.uuid, attackUuid);
}
async function removeAttackFromRisk(attackUuid: string) {
    await mutations.unlinkRiskFromAttack(props.uuid, attackUuid);
}
async function addRiskToAttack(riskUuid: string) {
    await mutations.linkRiskToAttack(riskUuid, props.uuid);
}
async function removeRiskFromAttack(riskUuid: string) {
    await mutations.unlinkRiskFromAttack(riskUuid, props.uuid);
}

// --- attack <-> defence (either end) ---------------------------------------

const linkedDefenceUuids = computed(() =>
    node.value?.type === 'attack'
        ? props.store.defencesUnderAttack(props.uuid)
        : [],
);
const linkedAttackUuidsForDefence = computed(() =>
    node.value?.type === 'defence'
        ? props.store.attacksForDefence(props.uuid)
        : [],
);

const availableDefencesForAttack = computed(() =>
    Object.values(props.store.nodes)
        .filter(
            (n) =>
                n.type === 'defence' &&
                !linkedDefenceUuids.value.includes(n.uuid),
        )
        .map((n) => ({ value: n.uuid, label: n.title || 'Untitled defence' })),
);
const availableAttacksForDefence = computed(() =>
    Object.values(props.store.nodes)
        .filter(
            (n) =>
                n.type === 'attack' &&
                !linkedAttackUuidsForDefence.value.includes(n.uuid),
        )
        .map((n) => ({ value: n.uuid, label: n.title || 'Untitled attack' })),
);

async function addDefenceToAttack(defenceUuid: string) {
    await mutations.linkAttackToDefence(props.uuid, defenceUuid);
}
async function removeDefenceFromAttack(defenceUuid: string) {
    await mutations.unlinkAttackFromDefence(props.uuid, defenceUuid);
}
async function addAttackToDefence(attackUuid: string) {
    await mutations.linkAttackToDefence(attackUuid, props.uuid);
}
async function removeAttackFromDefence(attackUuid: string) {
    await mutations.unlinkAttackFromDefence(attackUuid, props.uuid);
}
</script>

<template>
    <div v-if="node" class="flex flex-col gap-5 p-4">
        <!-- Risk: attacks under this risk -->
        <section v-if="node.type === 'risk'">
            <h3 class="text-text-primary mb-2 text-sm font-semibold">
                Attacks under this risk
            </h3>
            <ul v-if="linkedAttackUuids.length" class="mb-3 space-y-1">
                <li
                    v-for="attackUuid in linkedAttackUuids"
                    :key="attackUuid"
                    class="border-border-subtle flex items-center justify-between gap-2 rounded-md border px-2 py-1.5 text-sm"
                >
                    <span>{{ titleOf(attackUuid) }}</span>
                    <IconButton
                        v-if="canEdit"
                        icon="x"
                        label="Remove"
                        @click="removeAttackFromRisk(attackUuid)"
                    />
                </li>
            </ul>
            <EmptyState
                v-else
                icon="link-2"
                title="No attacks linked yet"
                description="Attacks contributing to this risk appear here."
            />
            <Combobox
                v-if="canEdit"
                :model-value="null"
                :options="availableAttacksForRisk"
                placeholder="Link an existing attack…"
                @update:model-value="addAttackToRisk"
            />
        </section>

        <!-- Attack: risks it belongs to -->
        <section v-if="node.type === 'attack'">
            <h3 class="text-text-primary mb-2 text-sm font-semibold">
                Risks this attack belongs to
            </h3>
            <ul v-if="linkedRiskUuids.length" class="mb-3 space-y-1">
                <li
                    v-for="riskUuid in linkedRiskUuids"
                    :key="riskUuid"
                    class="border-border-subtle flex items-center justify-between gap-2 rounded-md border px-2 py-1.5 text-sm"
                >
                    <span>{{ titleOf(riskUuid) }}</span>
                    <IconButton
                        v-if="canEdit"
                        icon="x"
                        label="Remove"
                        @click="removeRiskFromAttack(riskUuid)"
                    />
                </li>
            </ul>
            <EmptyState
                v-else
                icon="link-2"
                title="Not linked to any risk yet"
            />
            <Combobox
                v-if="canEdit"
                :model-value="null"
                :options="availableRisksForAttack"
                placeholder="Link under an existing risk…"
                @update:model-value="addRiskToAttack"
            />
        </section>

        <!-- Attack: defences applied to it, with per-link strength -->
        <section v-if="node.type === 'attack'">
            <h3 class="text-text-primary mb-2 text-sm font-semibold">
                Defences applied to this attack
            </h3>
            <p class="text-text-muted mb-2 text-xs">
                Strength and reduction target are set per link — the same
                defence can be rated differently against another attack.
            </p>
            <ul v-if="linkedDefenceUuids.length" class="mb-3 space-y-3">
                <li
                    v-for="defenceUuid in linkedDefenceUuids"
                    :key="defenceUuid"
                    class="border-border-subtle rounded-md border p-2"
                >
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="text-sm font-medium">{{
                            titleOf(defenceUuid)
                        }}</span>
                        <IconButton
                            v-if="canEdit"
                            icon="x"
                            label="Remove"
                            @click="removeDefenceFromAttack(defenceUuid)"
                        />
                    </div>

                    <LinkStrengthEditor
                        :store="store"
                        :attack-uuid="uuid"
                        :defence-uuid="defenceUuid"
                        :can-edit="canEdit"
                    />
                </li>
            </ul>
            <EmptyState v-else icon="shield" title="No defences linked yet" />
            <Combobox
                v-if="canEdit"
                :model-value="null"
                :options="availableDefencesForAttack"
                placeholder="Link an existing defence…"
                @update:model-value="addDefenceToAttack"
            />
        </section>

        <!-- Defence: attacks it applies to, with the same per-link editor -->
        <section v-if="node.type === 'defence'">
            <h3 class="text-text-primary mb-2 text-sm font-semibold">
                Attacks this defence applies to
            </h3>
            <p class="text-text-muted mb-2 text-xs">
                Strength is rated per attack: this defence can be 80% effective
                against one and 20% against another. Edit each here or from the
                attack's own Links tab — it is the same link.
            </p>
            <ul
                v-if="linkedAttackUuidsForDefence.length"
                class="mb-3 space-y-3"
            >
                <li
                    v-for="attackUuid in linkedAttackUuidsForDefence"
                    :key="attackUuid"
                    class="border-border-subtle rounded-md border p-2"
                >
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="text-sm font-medium">{{
                            titleOf(attackUuid)
                        }}</span>
                        <IconButton
                            v-if="canEdit"
                            icon="x"
                            label="Remove"
                            @click="removeAttackFromDefence(attackUuid)"
                        />
                    </div>

                    <LinkStrengthEditor
                        :store="store"
                        :attack-uuid="attackUuid"
                        :defence-uuid="uuid"
                        :can-edit="canEdit"
                    />
                </li>
            </ul>
            <EmptyState
                v-else
                icon="link-2"
                title="Not linked to any attack yet"
                description="This defence is a library item until it is linked."
            />
            <Combobox
                v-if="canEdit"
                :model-value="null"
                :options="availableAttacksForDefence"
                placeholder="Apply to an existing attack…"
                @update:model-value="addAttackToDefence"
            />
        </section>
    </div>
</template>
