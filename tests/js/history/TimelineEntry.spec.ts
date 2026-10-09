/**
 * One timeline entry (W48, item 21).
 *
 * Two things worth pinning. An **undone** operation stays in the timeline
 * rather than disappearing — history that edits itself is not history, and
 * "Dana deleted this, then undid it" is a more useful record than silence. And
 * a `null` old value prints as "not set", never as an empty string that reads
 * as blank: `calculation-engine.md`'s rule about missing values not rendering
 * as zero applies just as much to the record of how they got that way.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import TimelineEntry from '../../../resources/js/Components/History/TimelineEntry.vue';

vi.stubGlobal(
    'route',
    (name: string, params?: unknown) => `/${name}/${String(params)}`,
);

function entry(overrides: Record<string, unknown> = {}) {
    return {
        id: 'rev-1',
        source: 'revision' as const,
        action: 'field.update',
        summary: 'Changed 2 fields on Phishing',
        user: 'Dana Okonkwo',
        created_at: '2026-08-22T10:00:00.000Z',
        node_uuid: 'node-1',
        undone: false,
        changes: [
            {
                node_uuid: 'node-1',
                node_title: 'Phishing',
                field: 'title',
                from: 'Old',
                to: 'Phishing',
            },
            {
                node_uuid: 'node-1',
                node_title: 'Phishing',
                field: 'description',
                from: null,
                to: 'Written',
            },
        ],
        ...overrides,
    };
}

function render(overrides: Record<string, unknown> = {}) {
    return mount(TimelineEntry, {
        props: { entry: entry(overrides), assessmentUuid: 'a-1' },
    });
}

describe('TimelineEntry', () => {
    it('shows the summary and who made the change', () => {
        const wrapper = render();

        expect(wrapper.text()).toContain('Changed 2 fields on Phishing');
        expect(wrapper.text()).toContain('Dana Okonkwo');
    });

    it('names a removed account rather than showing a blank', () => {
        expect(render({ user: null }).text()).toContain('A removed account');
    });

    it('collapses the field detail until asked', async () => {
        const wrapper = render();

        expect(wrapper.find('dl').exists()).toBe(false);

        await wrapper.find('button').trigger('click');

        expect(wrapper.find('dl').exists()).toBe(true);
        expect(wrapper.text()).toContain('Old → Phishing');
    });

    it('prints a missing old value as "not set", never as a blank', async () => {
        const wrapper = render();
        await wrapper.find('button').trigger('click');

        expect(wrapper.text()).toContain('not set → Written');
    });

    it('marks an undone operation instead of hiding it', () => {
        const wrapper = render({
            source: 'operation',
            action: 'node.delete',
            summary: 'Dana deleted Phishing',
            undone: true,
            changes: [],
        });

        expect(wrapper.text()).toContain('undone');
        expect(wrapper.text()).toContain('Dana deleted Phishing');
    });

    it('links to the node it names, and offers no link when it names none', () => {
        expect(render().find('a').exists()).toBe(true);

        const structural = render({
            source: 'operation',
            action: 'subtree.clone',
            node_uuid: null,
            changes: [],
        });

        // A clone touches several nodes; a link to "the" node would be a guess.
        expect(structural.find('a').exists()).toBe(false);
    });

    it('offers no detail toggle when there is nothing to expand', () => {
        const wrapper = render({ source: 'operation', changes: [] });

        expect(wrapper.find('button').exists()).toBe(false);
    });
});
