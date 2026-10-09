<script setup lang="ts">
/**
 * Thin adapter over W10's AvatarStack (ui.md §2, §6): this component owns
 * nothing visual of its own, only projecting usePresence.ts's live member
 * list into the presentational component W10 already built for exactly
 * this purpose (AvatarStack.vue's own docblock: "W16 owns pushing real
 * collaborator data in").
 */
import AvatarStack from '@/Components/UI/AvatarStack.vue';
import type { PresenceUser } from '@/Components/UI/types';

defineProps<{
    users: PresenceUser[];
}>();
</script>

<template>
    <AvatarStack v-if="users.length > 0" :users="users" :max="5" size="sm" />
</template>
