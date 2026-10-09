<script setup lang="ts">
/**
 * Generic modal shell. The node modal (W13) is non-blocking-over-the-tree
 * per docs/specs/ui.md §7 — that just means the caller keeps the tree
 * mounted behind it; this component only owns the overlay/dialog mechanics
 * every modal needs: Escape to close, focus trap, and restoring focus to
 * the triggering element on close (docs/specs/ui.md §11).
 */
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import IconButton from './IconButton.vue';

const props = withDefaults(
    defineProps<{
        show: boolean;
        title?: string;
        size?: 'sm' | 'md' | 'lg' | 'xl';
        /** Set false for a dialog that must be resolved (e.g. ConfirmDialog). */
        closeable?: boolean;
    }>(),
    {
        title: undefined,
        size: 'md',
        closeable: true,
    },
);

const emit = defineEmits<{ close: [] }>();

const dialogRef = ref<HTMLElement | null>(null);
let triggerElement: HTMLElement | null = null;

const sizeClasses = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-lg',
    lg: 'sm:max-w-2xl',
    xl: 'sm:max-w-4xl',
};

function close() {
    if (props.closeable) {
        emit('close');
    }
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        close();
        return;
    }
    if (event.key === 'Tab' && dialogRef.value) {
        const focusable = dialogRef.value.querySelectorAll<HTMLElement>(
            'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
        );
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}

watch(
    () => props.show,
    async (show) => {
        if (show) {
            triggerElement = document.activeElement as HTMLElement | null;
            document.addEventListener('keydown', onKeydown);
            await nextTick();
            dialogRef.value?.querySelector<HTMLElement>('[autofocus]')?.focus();
            dialogRef.value?.focus();
        } else {
            document.removeEventListener('keydown', onKeydown);
            triggerElement?.focus();
        }
    },
);

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-150"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0"
                scroll-region
            >
                <div class="bg-surface-overlay fixed inset-0" @click="close" />

                <Transition
                    enter-active-class="transition ease-out duration-150"
                    enter-from-class="opacity-0 translate-y-2 sm:scale-95"
                    enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-active-class="transition ease-in duration-100"
                    leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-to-class="opacity-0 translate-y-2 sm:scale-95"
                >
                    <div
                        v-if="show"
                        ref="dialogRef"
                        role="dialog"
                        aria-modal="true"
                        :aria-label="title"
                        tabindex="-1"
                        class="border-border-default bg-surface-1 relative mx-auto mb-6 w-full overflow-hidden rounded-lg border shadow-xl outline-none"
                        :class="sizeClasses[size]"
                    >
                        <div
                            v-if="title || closeable || $slots.actions"
                            class="border-border-subtle flex items-center justify-between gap-2 border-b px-4 py-3"
                        >
                            <!--
                                `#title-prefix` exists for W71 item 24: the node
                                modal shows what type of node it is with a
                                tinted type icon here, where the eye lands
                                first, rather than as a chip over on the right
                                competing with Delete for the same corner.
                            -->
                            <div class="flex min-w-0 items-center gap-2">
                                <slot name="title-prefix" />
                                <h2
                                    class="text-text-primary truncate text-sm font-semibold"
                                >
                                    {{ title }}
                                </h2>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <slot name="actions" />
                                <IconButton
                                    v-if="closeable"
                                    icon="x"
                                    label="Close"
                                    @click="close"
                                />
                            </div>
                        </div>

                        <div class="max-h-[75vh] overflow-y-auto">
                            <slot />
                        </div>

                        <div
                            v-if="$slots.footer"
                            class="border-border-subtle border-t px-4 py-3"
                        >
                            <slot name="footer" />
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
