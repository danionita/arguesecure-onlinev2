import type { ComponentCustomProperties } from 'vue';

/**
 * The `route()` stub a component test installs as a global property.
 *
 * Ziggy types `route` as its full overloaded signature, which a one-line
 * `(name) => string` stub cannot satisfy. The cast lives here, once, so a
 * spec never switches type-checking off at its own call site.
 */
export function routeProperties(
    route: (...args: never[]) => string,
): ComponentCustomProperties {
    return { route } as unknown as ComponentCustomProperties;
}
