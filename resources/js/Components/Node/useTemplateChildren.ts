import { useLinkMutations } from '@/Components/NodeModal/useLinkMutations';
import type { AssessmentStore, NodeType } from '@/stores/assessmentStore';
import { computed, reactive, ref, type ComputedRef } from 'vue';

/**
 * Add children from a template (2.5.1).
 *
 * The same shape as `useAssistantChildren.ts` on purpose — a trigger on the
 * card, a panel under it, one Add per item — because it answers the same
 * question ("what could go under this?") from a different source: a vetted
 * template instead of a model. First the person picks a template, then sees
 * every node of the right type in it and adds as many as they want.
 *
 * ## What is copied
 *
 * `title`, `description` and, for a defence, `is_transfer` — through
 * `addChild`, the same validated write a typed node takes. Never the
 * template's values: they are its example's calibration, not this
 * assessment's (see app/Domain/Templates/TemplateNodes.php).
 *
 * ## Link, don't duplicate (W40)
 *
 * A template node whose title already exists here, as the same type, is
 * offered as **Link** rather than Add — or as "Already here" when it is under
 * this very parent. Adding "A.5.15 Access control" under a second attack
 * would otherwise make two controls with one name, and every figure computed
 * for either would be half the story. Risks cannot be linked (a risk has one
 * parent, the root), so an existing risk is always "Already here".
 */

export interface TemplateOption {
    uuid: string;
    title: string;
    counts: Record<'risk' | 'attack' | 'defence', number>;
}

export interface TemplateNodeItem {
    uuid: string;
    title: string;
    description: string | null;
    is_transfer: boolean;
    parents: string[];
}

export type TemplateItemState = 'add' | 'link' | 'here' | 'added';

type ParentType = 'root' | 'risk' | 'attack';
type ChildType = 'risk' | 'attack' | 'defence';

const CHILD_OF: Record<ParentType, ChildType> = {
    root: 'risk',
    risk: 'attack',
    attack: 'defence',
};

/**
 * One fetch of the template list per page, shared by every card: the list is
 * instance-wide and changes only when an admin publishes one, and a board of
 * forty cards should not ask forty times.
 */
let templatesRequest: Promise<TemplateOption[]> | null = null;

export function loadTemplateOptions(): Promise<TemplateOption[]> {
    templatesRequest ??= window.axios
        .get<{ templates: TemplateOption[] }>(route('templates.options'))
        .then((response) => response.data.templates)
        .catch((e: unknown) => {
            // Not cached, so opening the picker again retries.
            templatesRequest = null;
            throw e;
        });

    return templatesRequest;
}

/** For tests: forget the shared list. */
export function resetTemplateOptionsCache(): void {
    templatesRequest = null;
}

export async function loadTemplateNodes(
    templateUuid: string,
    type: ChildType,
): Promise<TemplateNodeItem[]> {
    const response = await window.axios.get<{ nodes: TemplateNodeItem[] }>(
        route('templates.nodes', { assessment: templateUuid, type }),
    );

    return response.data.nodes;
}

function normalise(title: string): string {
    return title.trim().toLocaleLowerCase();
}

export function useTemplateChildren(
    store: AssessmentStore,
    uuid: ComputedRef<string>,
    /** `undefined` when the node has not loaded yet, or cannot have children. */
    nodeType: ComputedRef<NodeType | undefined>,
) {
    const templateOpen = ref(false);
    const templates = ref<TemplateOption[] | null>(null);
    const templateUuid = ref<string | null>(null);
    const templateItems = ref<TemplateNodeItem[] | null>(null);
    const templateLoading = ref(false);
    const templateError = ref<string | null>(null);
    const templateBusy = ref<string | null>(null);
    /**
     * Template nodes taken since the panel opened, by template node uuid, so
     * their row reads "Added" rather than offering the same copy twice.
     * Cleared when the panel closes.
     */
    const templateAdded = reactive<Record<string, true>>({});

    const templateChildType = computed<ChildType>(() => {
        const type = nodeType.value;

        return type && type in CHILD_OF
            ? CHILD_OF[type as ParentType]
            : 'attack';
    });

    const linkMutations = useLinkMutations(store);

    function errorMessage(e: unknown, fallback: string): string {
        return (
            (e as { response?: { data?: { message?: string } } } | undefined)
                ?.response?.data?.message ?? fallback
        );
    }

    async function openTemplates() {
        templateOpen.value = true;
        templateError.value = null;
        if (templates.value !== null) return;

        templateLoading.value = true;
        try {
            templates.value = await loadTemplateOptions();
        } catch (e: unknown) {
            templateError.value = errorMessage(
                e,
                'The templates could not be loaded.',
            );
        } finally {
            templateLoading.value = false;
        }
    }

    async function chooseTemplate(template: string | null) {
        templateUuid.value = template;
        templateItems.value = null;
        templateError.value = null;
        if (template === null) return;

        templateLoading.value = true;
        try {
            templateItems.value = await loadTemplateNodes(
                template,
                templateChildType.value,
            );
        } catch (e: unknown) {
            templateError.value = errorMessage(
                e,
                'That template could not be read.',
            );
        } finally {
            templateLoading.value = false;
        }
    }

    function closeTemplates() {
        templateOpen.value = false;
        templateUuid.value = null;
        templateItems.value = null;
        templateError.value = null;
        for (const key of Object.keys(templateAdded)) delete templateAdded[key];
    }

    /** The node already in this assessment with this title and type, if any. */
    function existingMatch(item: TemplateNodeItem): string | null {
        const wanted = normalise(item.title);
        const match = Object.values(store.nodes).find(
            (node) =>
                node.type === templateChildType.value &&
                normalise(node.title) === wanted,
        );

        return match?.uuid ?? null;
    }

    function childrenOfParent(): string[] {
        const parent = uuid.value;
        switch (nodeType.value) {
            case 'root':
                return store.risksOrder();
            case 'risk':
                return store.attacksUnderRisk(parent);
            case 'attack':
                return store.defencesUnderAttack(parent);
            default:
                return [];
        }
    }

    function templateItemState(item: TemplateNodeItem): TemplateItemState {
        if (templateAdded[item.uuid]) return 'added';

        const existing = existingMatch(item);
        if (existing === null) return 'add';
        if (templateChildType.value === 'risk') return 'here';

        return childrenOfParent().includes(existing) ? 'here' : 'link';
    }

    async function takeTemplateItem(item: TemplateNodeItem) {
        const type = nodeType.value;
        if (type === undefined || !(type in CHILD_OF)) return;

        const state = templateItemState(item);
        if (state === 'here' || state === 'added') return;

        templateBusy.value = item.uuid;
        templateError.value = null;
        try {
            if (state === 'link') {
                const existing = existingMatch(item) as string;
                if (templateChildType.value === 'attack') {
                    await linkMutations.linkRiskToAttack(uuid.value, existing);
                } else {
                    await linkMutations.linkAttackToDefence(
                        uuid.value,
                        existing,
                    );
                }
            } else {
                await store.addChild(type as ParentType, uuid.value, {
                    title: item.title,
                    description: item.description,
                    ...(templateChildType.value === 'defence'
                        ? { is_transfer: item.is_transfer }
                        : {}),
                });
            }
            templateAdded[item.uuid] = true;
        } catch (e: unknown) {
            templateError.value = errorMessage(
                e,
                `Could not add '${item.title}'.`,
            );
        } finally {
            templateBusy.value = null;
        }
    }

    return {
        templateOpen,
        templates,
        templateUuid,
        templateItems,
        templateLoading,
        templateError,
        templateBusy,
        templateChildType,
        templateItemState,
        openTemplates,
        chooseTemplate,
        closeTemplates,
        takeTemplateItem,
    };
}
