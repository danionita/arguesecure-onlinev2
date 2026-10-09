import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';

/**
 * The fallback is the product's name, not Breeze's placeholder.
 * `VITE_APP_NAME` is read at *build* time, and the Docker build does not set
 * it — so every page in every deployed instance was titled "… - Laravel",
 * including the report, whose title becomes the suggested PDF filename.
 */
const appName = import.meta.env.VITE_APP_NAME || 'ArgueSecure';

createInertiaApp({
    /**
     * A page that has already named itself is left alone. The report builds
     * its own title deliberately — "ArgueSecure — <assessment> — <date>",
     * which is what the browser offers as the filename when someone saves it
     * as a PDF — and appending the brand a second time made a filename with
     * the product's name at both ends.
     */
    title: (title) =>
        title.startsWith(appName) ? title : `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
