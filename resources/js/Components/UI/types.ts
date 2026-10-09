/**
 * Shared prop/value types for the UI kit, kept in a plain .ts module (not
 * inside a `<script setup>` block) so any component can `import type`
 * them without relying on cross-.vue-file type-export resolution.
 */
import type { IconName } from './icons.generated';

export interface ComboboxOption {
    value: string;
    label: string;
    description?: string;
}

export interface SelectOption {
    value: string;
    label: string;
}

export interface PresenceUser {
    name: string;
    color?: string;
}

export interface TabItem {
    key: string;
    label: string;
    icon?: IconName;
    badge?: number | string;
}

export type DefenceStatus = 'implemented' | 'todo' | 'shelved';

export type ChipTone =
    | 'root'
    | 'risk'
    | 'attack'
    | 'defence'
    | 'transfer'
    | 'implemented'
    | 'todo'
    | 'shelved'
    | 'missing'
    | 'neutral';
