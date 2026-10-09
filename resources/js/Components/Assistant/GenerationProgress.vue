<script setup lang="ts">
/**
 * What a running generation is doing, and the button that stops it (W58).
 *
 * One component for all three jobs, because the thing a person needs to know
 * is the same in each: it is still going, this is how long it has been going,
 * and here is how to stop it. Only the numbers differ.
 *
 * **Elapsed time is the honest figure and it comes first.** The bar is drawn
 * against the configured token budget, which is an upper bound — most
 * generations finish well short of it — so it is labelled "up to" and never
 * reaches 100% while work is still running. Presenting an upper bound as a
 * prediction is how progress bars earn their reputation.
 */
import Button from '@/Components/UI/Button.vue';

defineProps<{
    elapsedLabel: string;
    percent: number;
    tokens: number;
    expected: number;
    /** Queued, but no tokens yet — the worker has not picked it up. */
    waiting?: boolean;
    cancelling?: boolean;
    label?: string;
}>();

defineEmits<{ cancel: [] }>();
</script>

<template>
    <div
        class="border-border-default bg-surface-1 rounded-md border p-3"
        data-testid="generation-progress"
        role="status"
        aria-live="polite"
    >
        <div class="flex items-center justify-between gap-3">
            <p class="text-text-primary text-sm">
                <span v-if="waiting" data-testid="generation-waiting">
                    Queued. Waiting for the assistant to start.
                </span>
                <span v-else>
                    {{ label ?? 'Working' }} —
                    <span data-testid="generation-elapsed">{{
                        elapsedLabel
                    }}</span>
                </span>
            </p>

            <Button
                variant="ghost"
                size="sm"
                :disabled="cancelling"
                data-testid="generation-cancel"
                @click="$emit('cancel')"
            >
                {{ cancelling ? 'Stopping…' : 'Stop' }}
            </Button>
        </div>

        <div
            class="bg-surface-2 mt-2 h-1.5 w-full overflow-hidden rounded-full"
        >
            <div
                class="bg-node-root-text h-full rounded-full transition-[width] duration-500"
                :style="{ width: percent + '%' }"
                data-testid="generation-bar"
            ></div>
        </div>

        <p v-if="!waiting" class="text-text-muted mt-1 text-xs">
            {{ tokens }} of up to {{ expected }} tokens. Most answers finish
            well before the limit, so the bar is a ceiling rather than a
            forecast.
        </p>
    </div>
</template>
