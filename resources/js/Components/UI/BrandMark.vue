<script setup lang="ts">
/**
 * The ArgueSecure mark: a shield with an argument inside it, a claim that
 * branches into two counter-claims. The same drawing as the website's logo
 * (viewBox 44), coloured from `--as-brand-outline` / `--as-brand-accent` so
 * it follows the app's theme toggle and not only the OS setting. Decorative:
 * the name beside it carries the meaning, so it is hidden from screen readers.
 */
withDefaults(defineProps<{ size?: number }>(), { size: 24 });
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 44 44"
        :width="size"
        :height="size"
        aria-hidden="true"
        focusable="false"
        data-test="brand-mark"
    >
        <path
            d="M22 3 L39 9 V21 C39 31 31.5 38 22 41 C12.5 38 5 31 5 21 V9 Z"
            fill="none"
            stroke="var(--as-brand-outline)"
            stroke-width="2.5"
            stroke-linejoin="round"
        />
        <path
            d="M22 12 V30 M22 19 L15 25 M22 19 L29 25"
            fill="none"
            stroke="var(--as-brand-accent)"
            stroke-width="2.5"
            stroke-linecap="round"
        />
        <circle cx="22" cy="12" r="2.6" fill="var(--as-brand-accent)" />
        <circle cx="15" cy="25" r="2.6" fill="var(--as-brand-accent)" />
        <circle cx="29" cy="25" r="2.6" fill="var(--as-brand-accent)" />
    </svg>
</template>
