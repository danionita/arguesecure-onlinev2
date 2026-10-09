<script setup lang="ts">
/**
 * The chrome around a help page (2.5.1), chosen on `auth.user` exactly as
 * the report chooses its own (ui.md §9, D39).
 *
 * The help routes sit outside `auth` on purpose (routes/web.php's W23
 * block): a workshop guest or a share-link reader must be able to read them.
 * `AuthenticatedLayout` cannot render for that visitor — it has no
 * `{name, email}` user — so a guest gets the small standalone header these
 * pages have always had, with a way to log in.
 *
 * Someone signed in gets the sidebar every other page has. Through 2.5 they
 * got the guest header too, and its "Back to the app" link — always to the
 * dashboard, wherever they had come from — was the only way out of help
 * without the browser's own back button. The sidebar is the way out now, to
 * anywhere, so that link is gone rather than kept beside it.
 */
import Icon from '@/Components/UI/Icon.vue';
import { useTheme } from '@/Components/UI/useTheme';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));

// AuthenticatedLayout applies the theme itself; the guest branch has nothing
// else that would.
useTheme();
</script>

<template>
    <AuthenticatedLayout v-if="signedIn">
        <div data-testid="help-shell-app">
            <slot />
        </div>
    </AuthenticatedLayout>

    <div
        v-else
        class="bg-surface-0 min-h-screen"
        data-testid="help-shell-guest"
    >
        <header class="border-border-subtle bg-surface-1 border-b">
            <div
                class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-4 sm:px-6"
            >
                <Link
                    href="/"
                    class="text-text-primary flex items-center gap-2 text-lg font-semibold"
                >
                    <Icon
                        name="shield"
                        :size="24"
                        class="text-node-root-text"
                    />
                    ArgueSecure
                </Link>
                <Link
                    :href="route('login')"
                    class="text-text-secondary text-sm hover:underline"
                >
                    Log in
                </Link>
            </div>
        </header>

        <slot />
    </div>
</template>
