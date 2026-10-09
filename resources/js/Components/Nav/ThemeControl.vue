<script setup lang="ts">
/**
 * The three-way light / dark / system control at the bottom of the sidebar
 * (W41, item 4).
 *
 * Three options, not a two-state switch, because `system` is a real choice
 * and the default: it is the only one that keeps up when the machine changes
 * at sunset. A binary toggle silently pins the theme the first time it is
 * touched and gives no way back.
 *
 * A `radiogroup`, so a screen reader hears "System, selected, 3 of 3" rather
 * than three unrelated pressed buttons. In the rail it collapses to a single
 * button that cycles, because three targets do not fit in 48px and a cycling
 * button is honest about what it does as long as its name says the current
 * state.
 */
import Icon from '@/Components/UI/Icon.vue';
import Tooltip from '@/Components/UI/Tooltip.vue';
import type { IconName } from '@/Components/UI/icons.generated';
import { useTheme, type ThemePreference } from '@/Components/UI/useTheme';
import { computed } from 'vue';

defineProps<{ compact?: boolean }>();

const { preference, setTheme } = useTheme();

const OPTIONS: { value: ThemePreference; label: string; icon: IconName }[] = [
    { value: 'light', label: 'Light', icon: 'sun' },
    { value: 'dark', label: 'Dark', icon: 'moon' },
    // No dedicated glyph exists in the ported Lucide set, and `circle-dot`
    // reads as "the middle option" beside a sun and a moon.
    { value: 'system', label: 'System', icon: 'circle-dot' },
];

const active = computed(() =>
    OPTIONS.find((option) => option.value === preference.value)!,
);

/** Rail: one button, cycling in the order the row shows. */
function cycle(): void {
    const index = OPTIONS.findIndex(
        (option) => option.value === preference.value,
    );
    setTheme(OPTIONS[(index + 1) % OPTIONS.length].value);
}
</script>

<template>
    <Tooltip
        v-if="compact"
        :text="`Theme: ${active.label}`"
        placement="right"
        class="w-full"
    >
        <button
            type="button"
            :aria-label="`Theme: ${active.label}. Change theme.`"
            class="text-text-secondary hover:bg-surface-2 hover:text-text-primary focus-visible:outline-focus-ring flex w-full items-center justify-center rounded-md px-2 py-1.5 outline-none focus-visible:outline-2 focus-visible:-outline-offset-2"
            @click="cycle"
        >
            <Icon :name="active.icon" :size="16" />
        </button>
    </Tooltip>

    <div v-else role="radiogroup" aria-label="Theme" class="px-2 py-1">
        <div class="border-border-subtle flex rounded-md border p-0.5">
            <button
                v-for="option in OPTIONS"
                :key="option.value"
                type="button"
                role="radio"
                :aria-checked="preference === option.value"
                :aria-label="option.label"
                :title="option.label"
                class="focus-visible:outline-focus-ring flex flex-1 items-center justify-center rounded px-2 py-1 outline-none focus-visible:outline-2 focus-visible:-outline-offset-2"
                :class="
                    preference === option.value
                        ? 'bg-surface-2 text-text-primary'
                        : 'text-text-muted hover:text-text-primary'
                "
                @click="setTheme(option.value)"
            >
                <Icon :name="option.icon" :size="14" />
            </button>
        </div>
    </div>
</template>
