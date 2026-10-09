<script setup lang="ts">
/**
 * Zoom for the report body only (W83, D43). `Ctrl+scroll` and `Ctrl +/-/0`
 * are the editor's shortcuts (`useCanvas.ts`'s `handleWheel` and W14's
 * bindings) — this is a static document with no canvas element to attach a
 * wheel handler to, so the report offers the button affordance alone.
 *
 * Deliberately not `Components/Toolbar/ZoomControls.vue`: that component's
 * `viewMode` prop is typed `EditorViewMode` (`'chart' | 'list'`,
 * `composables/useEditorViewMode.ts`) because its two label sets — "chart
 * size" / "text size" — are the only two this application has needed until
 * now. `Toolbar/ZoomControls.vue` is not this package's to extend a third
 * label onto (`Components/Toolbar/**` is not in W83's file ownership), and
 * passing it `viewMode="list"` to borrow the "text size" wording would
 * describe a page this is not — the report has no list. This component
 * reuses the same layout and the same `IconButton`/`useCanvas.ts` contract,
 * with its own label.
 */
import IconButton from '@/Components/UI/IconButton.vue';
import { CANVAS_ZOOM_MAX, CANVAS_ZOOM_MIN } from '@/composables/useCanvas';
import { computed } from 'vue';

const props = defineProps<{
    zoom: number;
}>();

const emit = defineEmits<{
    'zoom-in': [];
    'zoom-out': [];
    'zoom-reset': [];
}>();

const percentLabel = computed(() => `${Math.round(props.zoom * 100)}%`);
const atMin = computed(() => props.zoom <= CANVAS_ZOOM_MIN);
const atMax = computed(() => props.zoom >= CANVAS_ZOOM_MAX);
</script>

<template>
    <div
        class="flex items-center gap-0.5"
        role="group"
        aria-label="Zoom report"
    >
        <IconButton
            icon="minus"
            label="Decrease report size"
            :disabled="atMin"
            @click="emit('zoom-out')"
        />
        <button
            type="button"
            class="text-text-secondary hover:text-text-primary w-12 shrink-0 text-center text-xs tabular-nums"
            title="Reset report size to 100%"
            @click="emit('zoom-reset')"
        >
            {{ percentLabel }}
        </button>
        <IconButton
            icon="plus"
            label="Increase report size"
            :disabled="atMax"
            @click="emit('zoom-in')"
        />
    </div>
</template>
