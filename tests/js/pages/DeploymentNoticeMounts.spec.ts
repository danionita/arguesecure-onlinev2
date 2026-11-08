/**
 * the pages that build their own chrome (neither layout) each mount the
 * deployment notice. Shallow-mounted: this asserts the mount, and
 * `DeploymentNotice.spec.ts` asserts what the component renders.
 */
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import DeploymentNotice from '../../../resources/js/Components/Chrome/DeploymentNotice.vue';
import HelpShell from '../../../resources/js/Components/Help/HelpShell.vue';
import SnapshotShow from '../../../resources/js/Pages/Snapshots/Show.vue';
import Welcome from '../../../resources/js/Pages/Welcome.vue';
import { routeProperties } from '../support/routeProperties';

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    Link: { name: 'Link', props: ['href'], template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: null } }, url: '/' }),
}));

const route = (name: string) => `/${name}`;
const global = { config: { globalProperties: routeProperties(route) } };

describe('deployment notice mounts', () => {
    it('is on the Welcome page', () => {
        const wrapper = shallowMount(Welcome, { global });
        expect(wrapper.findComponent(DeploymentNotice).exists()).toBe(true);
    });

    it("is on the guest branch of the help shell, not the signed-in one's own", () => {
        const wrapper = shallowMount(HelpShell, { global });
        expect(wrapper.findComponent(DeploymentNotice).exists()).toBe(true);
    });

    it('is on the snapshot page', () => {
        const wrapper = shallowMount(SnapshotShow, {
            global,
            props: {
                assessment: { uuid: 'a1', currency: 'EUR' },
                snapshot: {
                    id: 1,
                    name: 'v1',
                    graph: { nodes: {} },
                    results: {
                        inherent: {},
                        current: { warnings: [] },
                        target: {},
                    },
                } as never,
                canRestore: false,
            },
        });
        expect(wrapper.findComponent(DeploymentNotice).exists()).toBe(true);
    });
});
