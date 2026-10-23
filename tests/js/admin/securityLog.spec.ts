/**
 * The admin **Security log** tab (W128, D96–D100): the download links carry
 * the filters, the table lists the rows, and the page says where application
 * logs are instead of offering them (D96).
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import SecurityLog from '../../../resources/js/Pages/Admin/SecurityLog.vue';

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        Head: { template: '<div><slot /></div>' },
        router: { get: vi.fn() },
    };
});

(globalThis as unknown as { route: unknown }).route = (
    name: string,
    params: Record<string, string> = {},
) => `/${name}?${new URLSearchParams(params).toString()}`;

function page(filters = { from: '', to: '', event: '' }, rows: unknown[] = []) {
    return mount(SecurityLog, {
        props: {
            filters,
            events: { families: ['auth'], names: ['auth.login'] },
            rows: rows as never[],
            retentionDays: 90,
            maxRows: 50000,
        },
        global: {
            stubs: { AdminLayout: { template: '<div><slot /></div>' } },
        },
    });
}

describe('Admin security log', () => {
    it('puts the filters in both download links', () => {
        const wrapper = page({
            from: '2026-03-01',
            to: '2026-03-02',
            event: 'auth',
        });

        const csv = wrapper
            .get('[data-testid="download-csv"]')
            .attributes('href');
        const json = wrapper
            .get('[data-testid="download-json"]')
            .attributes('href');

        expect(csv).toContain('format=csv');
        expect(csv).toContain('from=2026-03-01');
        expect(csv).toContain('to=2026-03-02');
        expect(csv).toContain('event=auth');
        expect(json).toContain('format=json');
    });

    it('lists the rows, or says none match', () => {
        expect(page().get('[data-testid="log-empty"]').text()).toBe(
            'No events match.',
        );

        const wrapper = page(undefined, [
            {
                id: 1,
                time: '2026-03-01T10:00:00Z',
                level: 'info',
                event: 'auth.login',
                actor: { id: 1, email: 'a@example.com' },
                target: null,
                ip: '127.0.0.1',
            },
        ]);
        expect(wrapper.get('[data-testid="log-table"]').text()).toContain(
            'auth.login',
        );
    });

    it('points to docker compose logs for application logs and offers no download of them', () => {
        const wrapper = page();

        expect(wrapper.get('[data-testid="app-log-note"]').text()).toContain(
            'docker compose logs app',
        );
        expect(wrapper.findAll('a').length).toBe(2);
    });
});
