<script setup lang="ts">
/**
 * Mount once, e.g. in AuthenticatedLayout.vue. `aria-live="polite"` so
 * screen readers announce toasts without interrupting (docs/specs/ui.md
 * §11 "Live regions announce ... save state, politely").
 */
import Toast from './Toast.vue';
import { useToast } from './useToast';

const { toasts, dismiss } = useToast();
</script>

<template>
    <Teleport to="body">
        <div
            aria-live="polite"
            class="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4"
        >
            <TransitionGroup
                enter-active-class="transition ease-out duration-150"
                enter-from-class="opacity-0 translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-100"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-for="toast in toasts"
                    :key="toast.id"
                    class="pointer-events-auto w-full max-w-sm"
                >
                    <Toast :toast="toast" @dismiss="dismiss" />
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
