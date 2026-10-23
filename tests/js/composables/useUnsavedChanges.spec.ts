import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';

type Listener = (event: {
    detail: {
        visit: {
            method: string;
            url: URL;
            prefetch?: boolean;
            async?: boolean;
            replace?: boolean;
        };
    };
}) => boolean | void;

const listeners = new Set<Listener>();
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (_name: string, listener: Listener) => {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        visit: (url: string, options?: unknown) => {
            visit(url, options);
            // The real router re-fires 'before' for the replayed visit.
            const event = {
                detail: { visit: { method: 'get', url: new URL(url) } },
            };
            listeners.forEach((l) => l(event));
        },
    },
}));

import {
    answerLeaveRequest,
    hasUnsavedChanges,
    pendingLeaveRequest,
    useUnsavedChanges,
} from '../../../resources/js/composables/useUnsavedChanges';

const mounted: { unmount(): void }[] = [];

function mountGuard(dirty: { value: boolean }) {
    const wrapper = mount(
        defineComponent({
            setup() {
                useUnsavedChanges(() => dirty.value);
                return () => null;
            },
        }),
    );
    mounted.push(wrapper);
    return wrapper;
}

/** What Inertia hands the 'before' listeners; true means "not cancelled". */
function navigate(
    method: string,
    path: string,
    extra: Record<string, unknown> = {},
): boolean {
    const event = {
        detail: {
            visit: {
                method,
                url: new URL(path, window.location.href),
                ...extra,
            },
        },
    };
    return [...listeners].every((l) => l(event) !== false);
}

afterEach(() => {
    mounted.splice(0).forEach((w) => w.unmount());
});

beforeEach(() => {
    listeners.clear();
    visit.mockClear();
    answerLeaveRequest(false);
});

describe('useUnsavedChanges', () => {
    it('lets a clean page navigate', () => {
        mountGuard({ value: false });
        expect(navigate('get', '/elsewhere')).toBe(true);
        expect(pendingLeaveRequest().value).toBeNull();
    });

    it('cancels a GET visit from a dirty page and asks', () => {
        mountGuard({ value: true });
        expect(navigate('get', '/elsewhere')).toBe(false);
        expect(pendingLeaveRequest().value).not.toBeNull();
    });

    it('does not interrupt the form submit itself', () => {
        mountGuard({ value: true });
        expect(navigate('put', '/elsewhere')).toBe(true);
        expect(navigate('post', '/elsewhere')).toBe(true);
    });

    it('does not interrupt a visit to the same path', () => {
        mountGuard({ value: true });
        expect(navigate('get', window.location.pathname + '?q=1')).toBe(true);
    });

    it('stays put on Stay and replays the visit on Leave', async () => {
        mountGuard({ value: true });
        navigate('get', '/elsewhere');
        answerLeaveRequest(false);
        await nextTick();
        expect(visit).not.toHaveBeenCalled();

        navigate('get', '/elsewhere');
        answerLeaveRequest(true);
        await Promise.resolve();
        expect(visit).toHaveBeenCalledTimes(1);
        expect(visit.mock.calls[0][0]).toContain('/elsewhere');
        // Replayed once, not cancelled and asked again.
        expect(pendingLeaveRequest().value).toBeNull();
    });

    it('replays the visit with the options it was started with', async () => {
        mountGuard({ value: true });
        navigate('get', '/elsewhere', { replace: true });
        answerLeaveRequest(true);
        await Promise.resolve();
        expect(visit.mock.calls[0][1]).toMatchObject({ replace: true });
    });

    it('ignores prefetch and background visits', () => {
        mountGuard({ value: true });
        expect(navigate('get', '/elsewhere', { prefetch: true })).toBe(true);
        expect(navigate('get', '/elsewhere', { async: true })).toBe(true);
    });

    it('asks once however many guards are dirty', () => {
        mountGuard({ value: true });
        mountGuard({ value: true });
        expect(listeners.size).toBe(1);
        expect(navigate('get', '/elsewhere')).toBe(false);
    });

    it('counts only guards in the asked scope', () => {
        mountGuard({ value: true });
        expect(hasUnsavedChanges('elsewhere')).toBe(false);
        expect(hasUnsavedChanges()).toBe(true);
    });

    it('raises the browser prompt only while dirty', () => {
        const dirty = { value: false };
        mountGuard(dirty);
        const clean = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(clean);
        expect(clean.defaultPrevented).toBe(false);

        dirty.value = true;
        const unsaved = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unsaved);
        expect(unsaved.defaultPrevented).toBe(true);
    });

    it('removes every handler on unmount', () => {
        const wrapper = mountGuard({ value: true });
        expect(hasUnsavedChanges()).toBe(true);
        wrapper.unmount();
        expect(listeners.size).toBe(0);
        expect(hasUnsavedChanges()).toBe(false);
        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
    });
});
