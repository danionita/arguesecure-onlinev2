<script setup lang="ts">
/**
 * Text/number/currency input with a label, helper text and error slot in
 * one place. It replaced the Breeze TextInput + InputLabel + InputError
 * trio in W10; W55 deleted those three files, unreferenced since.
 */
import { computed, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string | number | null;
        label?: string;
        /** Shown under the label — e.g. "loss each time this occurs" needs this
         *  to distinguish per-occurrence impact from annual loss (ui.md §7). */
        helpText?: string;
        error?: string;
        type?: string;
        placeholder?: string;
        disabled?: boolean;
        required?: boolean;
        prefix?: string;
        suffix?: string;
        /**
         * Combobox ARIA, for a caller that hangs a suggestion list under this
         * field (W79, the Details tab's placeholder title). Declared as props
         * rather than left to attribute fallthrough because fallthrough lands
         * on the wrapper `<div>`, and a `role="combobox"` on the element that
         * is not the text box is a role no screen reader can act on.
         *
         * Both default to undefined, so every existing caller renders exactly
         * the markup it did before.
         */
        role?: string;
        expanded?: boolean;
    }>(),
    {
        label: undefined,
        helpText: undefined,
        error: undefined,
        type: 'text',
        placeholder: undefined,
        disabled: false,
        required: false,
        prefix: undefined,
        suffix: undefined,
        role: undefined,
        expanded: undefined,
    },
);

defineEmits<{ 'update:modelValue': [value: string] }>();

const id = useId();
const describedBy = computed(
    () =>
        [props.helpText && `${id}-help`, props.error && `${id}-error`]
            .filter(Boolean)
            .join(' ') || undefined,
);
</script>

<template>
    <div>
        <label
            v-if="label"
            :for="id"
            class="text-text-primary mb-1 block text-sm font-medium"
        >
            {{ label }}
            <span v-if="required" class="text-node-attack-text">*</span>
        </label>
        <p
            v-if="helpText"
            :id="`${id}-help`"
            class="text-text-muted mb-1 text-xs"
        >
            {{ helpText }}
        </p>

        <div class="relative flex items-center">
            <span
                v-if="prefix"
                class="text-text-muted absolute left-2.5 text-sm"
                >{{ prefix }}</span
            >
            <input
                :id="id"
                :type="type"
                :value="modelValue"
                :placeholder="placeholder"
                :disabled="disabled"
                :required="required"
                :role="role"
                :aria-autocomplete="role === 'combobox' ? 'list' : undefined"
                :aria-expanded="expanded"
                :aria-invalid="!!error"
                :aria-describedby="describedBy"
                class="border-border-default bg-surface-1 text-text-primary placeholder:text-text-muted focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md text-sm shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                :class="[
                    prefix && 'pl-6',
                    suffix && 'pr-8',
                    error && 'border-node-attack-border',
                ]"
                @input="
                    $emit(
                        'update:modelValue',
                        ($event.target as HTMLInputElement).value,
                    )
                "
            />
            <span
                v-if="suffix"
                class="text-text-muted absolute right-2.5 text-sm"
                >{{ suffix }}</span
            >
        </div>

        <p
            v-if="error"
            :id="`${id}-error`"
            class="text-node-attack-text mt-1 text-xs"
        >
            {{ error }}
        </p>
    </div>
</template>
