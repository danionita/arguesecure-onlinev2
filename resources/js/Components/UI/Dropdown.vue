<script setup lang="ts">
/**
 * Generic trigger + floating panel (assessment switcher, user menu,
 * per-node "more actions"). It replaced Breeze's own Dropdown in W10;
 * that file was deleted in W55, so this is now the only one.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        align?: 'left' | 'right';
        width?: 'auto' | '48' | '64' | '80';
    }>(),
    {
        align: 'left',
        width: 'auto',
    },
);

const open = ref(false);
const root = ref<HTMLElement | null>(null);

const widthClasses = computed(
    () =>
        ({
            auto: 'min-w-max',
            48: 'w-48',
            64: 'w-64',
            80: 'w-80',
        })[props.width],
);

const alignClasses = computed(() =>
    props.align === 'right'
        ? 'end-0 origin-top-right'
        : 'start-0 origin-top-left',
);

function onDocClick(event: MouseEvent) {
    if (
        open.value &&
        root.value &&
        !root.value.contains(event.target as Node)
    ) {
        open.value = false;
    }
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') open.value = false;
}

onMounted(() => {
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onKeydown);
});

defineExpose({ close: () => (open.value = false) });
</script>

<template>
    <div ref="root" class="relative inline-block text-left">
        <div @click="open = !open">
            <slot name="trigger" :open="open" />
        </div>

        <Transition
            enter-active-class="transition ease-out duration-100"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="open"
                class="border-border-default bg-surface-1 absolute z-40 mt-1.5 rounded-md border py-1 shadow-lg"
                :class="[widthClasses, alignClasses]"
                @click="open = false"
            >
                <slot name="content" :close="() => (open = false)" />
            </div>
        </Transition>
    </div>
</template>
