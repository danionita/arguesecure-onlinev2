/**
 * Which view the editor opens in (W32). The default is the assertion that
 * matters: the chart is the product's front door, and a regression that
 * quietly restored the list would change what every new user sees first
 * without changing a single visible pixel of either view.
 */
import { describe, expect, it, vi } from 'vitest';
import {
    EDITOR_VIEW_MODE_DEFAULT,
    useEditorViewMode,
} from '../../../resources/js/composables/useEditorViewMode';

function memoryStorage(seed: Record<string, string> = {}) {
    const data = { ...seed };
    return {
        getItem: (key: string) => data[key] ?? null,
        setItem: (key: string, value: string) => {
            data[key] = value;
        },
        read: () => data,
    };
}

const KEY = 'arguesecure:viewmode:7:assessment-uuid';

describe('useEditorViewMode', () => {
    it('opens in the chart', () => {
        const state = useEditorViewMode({
            assessmentUuid: 'assessment-uuid',
            userId: 7,
            storage: memoryStorage(),
        });

        expect(state.mode.value).toBe('chart');
        expect(EDITOR_VIEW_MODE_DEFAULT).toBe('chart');
    });

    it('remembers the list per user per assessment once chosen', () => {
        const storage = memoryStorage();
        const state = useEditorViewMode({
            assessmentUuid: 'assessment-uuid',
            userId: 7,
            storage,
        });

        state.set('list');

        expect(state.mode.value).toBe('list');
        expect(storage.read()[KEY]).toBe('list');
    });

    it('restores a stored choice', () => {
        const state = useEditorViewMode({
            assessmentUuid: 'assessment-uuid',
            userId: 7,
            storage: memoryStorage({ [KEY]: 'list' }),
        });

        expect(state.mode.value).toBe('list');
    });

    it('falls back to the chart when the stored value is not a view', () => {
        const state = useEditorViewMode({
            assessmentUuid: 'assessment-uuid',
            userId: 7,
            storage: memoryStorage({ [KEY]: 'kanban' }),
        });

        expect(state.mode.value).toBe('chart');
    });

    it('survives storage that throws', () => {
        const throwing = {
            getItem: vi.fn(() => {
                throw new Error('denied');
            }),
            setItem: vi.fn(() => {
                throw new Error('denied');
            }),
        };

        const state = useEditorViewMode({
            assessmentUuid: 'assessment-uuid',
            userId: 7,
            storage: throwing,
        });

        expect(state.mode.value).toBe('chart');
        expect(() => state.set('list')).not.toThrow();
        expect(state.mode.value).toBe('list');
    });
});
