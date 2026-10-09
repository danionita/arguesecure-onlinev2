/**
 * The report's written executive summary and job C's draft (W54).
 *
 * Every assertion here is about one of two things: **who sees what**, and
 * **how many deliberate acts stand between a model's paragraph and a document
 * a client reads.** The answer to the second is two — open the draft, then
 * save it — and the tests below pin each of them, because both are the sort of
 * step a later "streamline the flow" change removes without noticing what it
 * was for.
 *
 * The measurement behind that: on a 73-node assessment, a string planted in a
 * node description came back inside the drafted summary prose on one of three
 * models, and the summary is attributed to the author.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SummaryNarrative from '../../../resources/js/Components/Assistant/SummaryNarrative.vue';

const put = vi.fn();
const post = vi.fn();
const del = vi.fn();
const get = vi.fn();

beforeEach(() => {
    put.mockReset();
    post.mockReset();
    del.mockReset();
    // W58: the component probes once on mount to find a draft that is already
    // running. Answering "nothing running" keeps every assertion below about
    // the thing it was written for.
    get.mockReset();
    get.mockResolvedValue({ data: { running: false, queued: false } });
    (window as unknown as { axios: unknown }).axios = {
        put,
        post,
        delete: del,
        get,
    };
    (globalThis as unknown as { route: unknown }).route = () => '/summary';
});

function panel(overrides: Record<string, unknown> = {}) {
    return mount(SummaryNarrative, {
        props: {
            assessmentUuid: 'a-uuid',
            narrative: null,
            generated: false,
            draft: null,
            canEdit: true,
            assistantAvailable: true,
            ...overrides,
        },
        global: {
            stubs: {
                Button: {
                    template:
                        '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
                    props: ['disabled', 'loading', 'variant'],
                },
            },
        },
    });
}

describe('the report summary narrative', () => {
    it('renders nothing at all for a reader when there is no summary', () => {
        // A public reader of an assessment nobody has written a summary for
        // should see no empty heading and no explanation of a control they do
        // not have.
        const wrapper = panel({ canEdit: false, assistantAvailable: false });

        expect(wrapper.find('[data-testid="summary-narrative"]').exists()).toBe(
            false,
        );
    });

    it('shows the summary to a reader, with the generated mark when it applies', () => {
        const wrapper = panel({
            narrative: 'Three paragraphs about a storefront.',
            generated: true,
            canEdit: false,
            assistantAvailable: false,
        });

        expect(wrapper.text()).toContain(
            'Three paragraphs about a storefront.',
        );
        // The disclosure prints. A reader holding the PDF is exactly who it is
        // for, and they never see any of the controls above.
        expect(wrapper.find('[data-testid="generated-mark"]').exists()).toBe(
            true,
        );
    });

    it('offers no draft action where no assistant exists', () => {
        const wrapper = panel({ assistantAvailable: false });

        // D25: on a deployment with no runtime the narrative is an ordinary
        // text field and nothing hints at a feature that is not there.
        expect(wrapper.find('[data-testid="request-draft"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-testid="edit-narrative"]').exists()).toBe(
            true,
        );
    });

    it('says the draft was queued rather than pretending to wait for it', async () => {
        post.mockResolvedValue({ data: { message: 'Drafting. This takes…' } });
        const wrapper = panel();

        await wrapper.find('[data-testid="request-draft"]').trigger('click');
        await flushPromises();

        // Minutes, not seconds. A spinner that ran for four minutes would be
        // indistinguishable from a hang, so the request returns at once and a
        // notification is what says it is ready.
        expect(wrapper.find('[data-testid="draft-queued"]').text()).toContain(
            'Drafting',
        );
    });

    it('never writes a draft into the report by itself', () => {
        const wrapper = panel({ draft: 'A model wrote this.' });

        expect(wrapper.find('[data-testid="summary-draft"]').text()).toContain(
            'not in the report yet',
        );
        // The narrative is still empty. Nothing was applied.
        expect(wrapper.find('[data-testid="narrative-empty"]').exists()).toBe(
            true,
        );
        expect(put).not.toHaveBeenCalled();
    });

    it('accepting a draft opens it in the editor rather than publishing it', async () => {
        const wrapper = panel({ draft: 'A model wrote this.' });

        await wrapper.find('[data-testid="use-draft"]').trigger('click');

        // The one assertion this whole component exists for. "Use" is not
        // "publish" — it puts the text somewhere the person has to read it,
        // and the save is a second, deliberate act.
        expect(wrapper.find('[data-testid="narrative-editor"]').exists()).toBe(
            true,
        );
        expect(
            wrapper.find('[data-testid="editing-draft-note"]').exists(),
        ).toBe(true);
        expect(
            (wrapper.find('textarea').element as HTMLTextAreaElement).value,
        ).toBe('A model wrote this.');
        expect(put).not.toHaveBeenCalled();
    });

    it('saving an accepted draft declares where it came from', async () => {
        put.mockResolvedValue({
            data: { narrative: 'Edited.', generated: true, draft: null },
        });
        const wrapper = panel({ draft: 'A model wrote this.' });

        await wrapper.find('[data-testid="use-draft"]').trigger('click');
        await wrapper.find('textarea').setValue('Edited.');
        await wrapper.find('[data-testid="save-narrative"]').trigger('click');
        await flushPromises();

        expect(put).toHaveBeenCalledWith('/summary', {
            narrative: 'Edited.',
            from_draft: true,
        });
        expect(wrapper.find('[data-testid="generated-mark"]').exists()).toBe(
            true,
        );
    });

    it('writing your own summary does not declare a model wrote it', async () => {
        put.mockResolvedValue({
            data: { narrative: 'Mine.', generated: false, draft: null },
        });
        const wrapper = panel();

        await wrapper.find('[data-testid="edit-narrative"]').trigger('click');
        await wrapper.find('textarea').setValue('Mine.');
        await wrapper.find('[data-testid="save-narrative"]').trigger('click');
        await flushPromises();

        expect(put).toHaveBeenCalledWith('/summary', {
            narrative: 'Mine.',
            from_draft: false,
        });
        expect(wrapper.find('[data-testid="generated-mark"]').exists()).toBe(
            false,
        );
    });

    it('discarding a draft costs one click and no typing', async () => {
        del.mockResolvedValue({ data: { draft: null } });
        const wrapper = panel({ draft: 'Not what I meant.' });

        await wrapper.find('[data-testid="discard-draft"]').trigger('click');
        await flushPromises();

        // Rejecting must be as cheap as accepting, or the easy path becomes
        // "accept it and tidy later" and the accept step decays into a
        // formality.
        expect(wrapper.find('[data-testid="summary-draft"]').exists()).toBe(
            false,
        );
    });

    it('renders the draft as text, never as markup', () => {
        const wrapper = panel({
            draft: '<img src=x onerror=alert(1)> and a summary.',
        });

        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
        expect(wrapper.find('img').exists()).toBe(false);
    });
});
