<script setup lang="ts">
/**
 * Zoom in/out/reset buttons over `useCanvas.ts` (ui.md §5). `Ctrl+scroll`
 * and `Ctrl +/-/0` are the other two ways to reach the same three
 * functions — this component only supplies the button affordance; the
 * wheel handler lives on the canvas element itself (useCanvas's
 * `handleWheel`) and the keyboard shortcuts are W14's to bind.
 */
import IconButton from '@/Components/UI/IconButton.vue';
import { CANVAS_ZOOM_MAX, CANVAS_ZOOM_MIN } from '@/composables/useCanvas';
import type { EditorViewMode } from '@/composables/useEditorViewMode';
import { computed } from 'vue';

const props = defineProps<{
    zoom: number;
    /**
     * Which view is being zoomed. The buttons are identical; what they do is
     * not (PLAN-2.2.md D9), so the control has to say which it is or a user
     * who zooms in the list and then switches to the chart will think it
     * broke.
     */
    viewMode: EditorViewMode;
}>();

const emit = defineEmits<{
    'zoom-in': [];
    'zoom-out': [];
    'zoom-reset': [];
}>();

const percentLabel = computed(() => `${Math.round(props.zoom * 100)}%`);
const atMin = computed(() => props.zoom <= CANVAS_ZOOM_MIN);
const atMax = computed(() => props.zoom >= CANVAS_ZOOM_MAX);

const what = computed(() =>
    props.viewMode === 'list' ? 'text size' : 'chart size',
);
const groupLabel = computed(() =>
    props.viewMode === 'list' ? 'Zoom text size' : 'Zoom chart',
);
</script>

<template>
    <div
        class="flex items-center gap-0.5"
        role="group"
        :aria-label="groupLabel"
    >
        <IconButton
            icon="minus"
            :label="`Decrease ${what}`"
            :disabled="atMin"
            @click="emit('zoom-out')"
        />
        <button
            type="button"
            class="text-text-secondary hover:text-text-primary w-12 shrink-0 text-center text-xs tabular-nums"
            :title="`Reset ${what} to 100%`"
            @click="emit('zoom-reset')"
        >
            {{ percentLabel }}
        </button>
        <IconButton
            icon="plus"
            :label="`Increase ${what}`"
            :disabled="atMax"
            @click="emit('zoom-in')"
        />
    </div>
</template>
