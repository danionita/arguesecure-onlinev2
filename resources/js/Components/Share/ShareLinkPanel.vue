<script setup lang="ts">
/**
 * The revocable read-only link (PLAN.md Phase 3 item 5 / Phase 8 item 6).
 * Clearly badged read-only, per the brief, so no one mistakes it for an
 * edit invite — that badge is the whole point of the StatusPill below.
 */
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import { useToast } from '@/Components/UI/useToast';
import { ref } from 'vue';

defineProps<{
    shareLink: string | null;
    busy: boolean;
}>();

const emit = defineEmits<{
    generate: [];
    revoke: [];
}>();

const { push } = useToast();
const copied = ref(false);

async function copy(link: string) {
    await navigator.clipboard.writeText(link);
    copied.value = true;
    push('Link copied.', 'success', 2000);
    window.setTimeout(() => (copied.value = false), 2000);
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center gap-2">
            <p class="text-text-primary text-sm font-medium">Read-only link</p>
            <Badge tone="neutral">read only</Badge>
        </div>

        <div v-if="shareLink" class="flex items-center gap-2">
            <input
                :value="shareLink"
                readonly
                class="border-border-default bg-surface-2 text-text-secondary block w-full rounded-md text-sm shadow-sm"
                @focus="($event.target as HTMLInputElement).select()"
            />
            <Button icon="copy" :disabled="busy" @click="copy(shareLink)">{{
                copied ? 'Copied' : 'Copy'
            }}</Button>
            <Button
                variant="danger"
                icon="trash-2"
                :disabled="busy"
                @click="emit('revoke')"
                >Revoke</Button
            >
        </div>
        <div v-else class="flex items-center justify-between">
            <p class="text-text-muted text-xs">
                No link has been generated. Anyone with the link can view this
                assessment without an account.
            </p>
            <Button icon="link-2" :disabled="busy" @click="emit('generate')"
                >Generate link</Button
            >
        </div>
    </div>
</template>
