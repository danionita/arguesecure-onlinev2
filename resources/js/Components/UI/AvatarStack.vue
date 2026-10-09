<script setup lang="ts">
/**
 * Presentational presence-avatar row for the top bar (docs/specs/ui.md
 * §2, §6). This is the "slot", not the websocket — W16 owns pushing real
 * collaborator data in; this component only lays out whatever list it is
 * given and collapses the overflow into a "+N" chip.
 */
import { computed } from 'vue';
import Avatar from './Avatar.vue';
import type { PresenceUser } from './types';

const props = withDefaults(
    defineProps<{
        users: PresenceUser[];
        max?: number;
        size?: 'xs' | 'sm' | 'md';
    }>(),
    {
        max: 4,
        size: 'sm',
    },
);

const visible = computed(() => props.users.slice(0, props.max));
const overflow = computed(() => Math.max(0, props.users.length - props.max));
</script>

<template>
    <div class="flex items-center -space-x-2">
        <Avatar
            v-for="user in visible"
            :key="user.name"
            :name="user.name"
            :size="size"
            :ring-color="user.color"
        />
        <span
            v-if="overflow > 0"
            class="bg-surface-2 text-text-secondary ring-surface-1 inline-flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold ring-2"
        >
            +{{ overflow }}
        </span>
    </div>
</template>
