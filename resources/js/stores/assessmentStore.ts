/**
 * The tree editor's client-side store (docs/specs/ui.md §3, PLAN.md W12).
 *
 * ## The load-bearing decision: keying by (node uuid, path), not node alone
 *
 * schema.md is explicit that this is a DAG, not a tree: an attack can sit
 * under several risks, a defence under several attacks. ui.md §3.1 draws
 * the consequence out for the client: "the renderer therefore keys on
 * (node uuid, path), not on node uuid alone. The store holds nodes once;
 * the view projects them into every position they occupy."
 *
 * Concretely:
 *   - `nodes` is a flat `Record<uuid, NodeRecord>` — one entry per node,
 *     full stop. Editing a title mutates exactly one object, so every
 *     occurrence of a shared node reflects the edit instantly with no
 *     fan-out write.
 *   - The DAG's *shape* lives entirely in the edge lists — `riskAttackLinks`
 *     and `attackDefenceLinks` — each row carrying its own `position`
 *     (schema.md §3.5/§3.6: "ordering lives on the edge"), so the same
 *     attack can sit at position 0 under one risk and position 3 under
 *     another without any conflict.
 *   - Rendering walks those edge lists recursively (TreeNode.vue). Each
 *     recursive call carries a `path` — the array of uuids from the root
 *     down to that occurrence — and `path.join('/')` becomes both the Vue
 *     `:key` for that render and the key into `occurrenceState` below.
 *   - `occurrenceState` (expand/collapse, and later focus/selection — W14/
 *     W15) is keyed by that *path*, never by uuid. This is what ui.md §3.1
 *     means by "expansion, focus and selection state belong to the
 *     occurrence, not the node": collapsing one occurrence of a shared
 *     defence must not collapse its other occurrences, because they are
 *     different entries in this map even though they read the same
 *     `NodeRecord`.
 *
 * This shape is deliberately the thing W13 (node modal), W14 (keyboard,
 * drag-drop, undo) and W15 (canvas, search, focus mode, duplicate
 * highlighting) build on rather than replace: W13's modal opens against a
 * uuid (a node has one detail record regardless of how many places it
 * appears); W14/W15's per-occurrence UI (focus mode, duplicate outlines,
 * collapse-all) all key off the same path strings this store already
 * produces via `occurrencePath()`.
 */
// Relative, not '@/calc': vitest.config.ts (outside this package's
// ownership) has no '@' alias — only vite.config.js's laravel plugin
// provides one, for the production bundle — and resources/js/calc/**
// itself only ever imports relatively for exactly this reason (it must
// stay importable from tests/js/**). Matching that keeps this store
// testable by Vitest without needing a change to that config file.
import { computed, reactive, ref, type InjectionKey, type Ref } from 'vue';
import type {
    AttackData,
    AttackDefenceLink as CalcAttackDefenceLink,
    RiskAttackLink as CalcRiskAttackLink,
    Scale as CalcScale,
    ScaleLevel as CalcScaleLevel,
    CalculationResults,
    DefenceData,
    RiskData,
} from '../calc';
import { RiskCalculator } from '../calc';
// Relative for the same reason as the '../calc' import just above (this
// file must stay importable from Vitest, which has no '@' alias).
import { fetchUnresolvedCommentCounts } from '../Components/Comments/useComments';
// Re-exported: `results` is part of this store's own public shape, so
// consumers (Assessment/Show.vue, NodeCard.vue) import the type from here
// rather than reaching into '../calc' directly for it.
export type { CalculationResults } from '../calc';

export type NodeType = 'root' | 'risk' | 'attack' | 'defence';
export type ValueMode = 'quantitative' | 'qualitative';
export type DefenceStatus = 'implemented' | 'todo' | 'shelved';
export type ReductionTarget = 'likelihood' | 'impact';

/**
 * A label as the server sends it (W43). Assessment-scoped, so the same name
 * in two assessments is two rows with two ids — which is why nothing here
 * caches tags across assessments.
 */
export interface TagRecord {
    id: number;
    name: string;
    slug: string;
    color: string | null;
}

/**
 * The wire shape from AssessmentController::nodeToArray() — deliberately
 * snake_case, matching every other Inertia prop in this codebase (e.g.
 * Admin/Users/Index.vue's UserRow), rather than converting at the
 * boundary. Fields irrelevant to `type` are always present but null
 * (schema.md §3.4's CHECK constraints keep them that way server-side).
 */
export interface NodeRecord {
    uuid: string;
    type: NodeType;
    title: string;
    description: string | null;
    position: number;
    impact_mode: ValueMode | null;
    impact_amount: number | null;
    impact_level_id: number | null;
    likelihood_mode: ValueMode | null;
    likelihood_aro: number | null;
    likelihood_level_id: number | null;
    is_transfer: boolean;
    status: DefenceStatus | null;
    capex_amount: number | null;
    opex_annual_amount: number | null;
    /**
     * W43. Eager-loaded with the graph, never fetched per node — that is the
     * N+1 CLAUDE.md forbids, and it is what closed useFilters.ts's "tag gap":
     * the filter has been fully implemented since W15 with nothing to filter.
     */
    tags: TagRecord[];
    /** The optimistic-concurrency token (ui.md §3.5). ISO 8601. */
    updated_at: string;
}

export interface RiskAttackLink {
    risk_uuid: string;
    attack_uuid: string;
    position: number;
}

export interface AttackDefenceLink {
    attack_uuid: string;
    defence_uuid: string;
    position: number;
    strength_mode: ValueMode | null;
    strength_fraction: number | null;
    strength_level_id: number | null;
    reduction_target: ReductionTarget | null;
    /**
     * Why this strength was chosen — displayed and edited on the Links tab
     * (W31). Not read by the engine; AssessmentGraphLoader carries it
     * through purely so the modal can show a saved rationale after a
     * reload, which it could not do while this field was absent here.
     */
    rationale: string | null;
}

export interface AssessmentSummary {
    uuid: string;
    currency: string;
    display_mode: string;
    amortization_years: number;
    /**
     * An annual-loss threshold to compare totals against (W44, item 12).
     * **Display only** — PLAN-2.2.md D14. It draws a marker and a badge and
     * never enters the calculation: `recomputeLocally()` does not read it,
     * and `calculation-engine.md` forbids band-mapping a computed figure into
     * low/medium/high on the strength of it. Null means none is set, which is
     * a different statement from a tolerance of zero.
     */
    risk_tolerance?: number | null;
    is_public: boolean;
}

/**
 * One assistant suggestion (W52).
 *
 * Exactly two fields, and that is the security content rather than a
 * convenience: `SuggestionParser` constructs these two keys and discards
 * everything else the model returned. The spike measured injected text adding
 * fields to the output on 3 of 3 candidates — including an `execute` key
 * holding a shell command — so a shape that cannot carry them is the fix that
 * does not depend on the model behaving.
 */
export interface AssistantSuggestion {
    title: string;
    description: string;
}

export interface Abilities {
    comment: boolean;
    update: boolean;
    manage: boolean;
    /** Whether the current user may transfer ownership (owner or admin) — AssessmentPolicy::transferOwnership(). */
    transfer: boolean;
}

/**
 * The wire shape of AssessmentController's `scaleLevels` prop — snake_case
 * like every other prop here, and a flat list rather than a map because
 * `array_values()` re-indexes it on the way out. `recomputeLocally()` keys
 * it by id, which is what the calculation engine's `AssessmentGraph`
 * requires.
 */
export interface ScaleLevelRecord {
    id: number;
    scale_id: number;
    kind: 'impact' | 'likelihood' | 'strength';
    unit: string;
    code: string;
    label: string;
    ordinal: number;
    lower_bound: number;
    upper_bound: number | null;
    representative: number;
}

export interface ScaleRecord {
    id: number;
    kind: 'impact' | 'likelihood' | 'strength';
    unit: string;
    is_system: boolean;
}

export interface AssessmentStoreInit {
    assessment: AssessmentSummary;
    nodes: NodeRecord[];
    riskAttackLinks: RiskAttackLink[];
    attackDefenceLinks: AttackDefenceLink[];
    /**
     * The assessment's scale calibration. Optional only so the stores built
     * by component tests keep compiling; a store without it cannot recompute
     * locally at all — see recomputeLocally().
     */
    scaleLevels?: ScaleLevelRecord[];
    scales?: ScaleRecord[];
    results: CalculationResults;
    abilities: Abilities;
    /**
     * W52: whether the assistant is usable right now — the deployment has a
     * runtime, an admin switched it on, and it is answering. Optional and
     * defaulting to false so every existing test that builds a store without
     * it simply gets an editor with no assistant actions, which is also the
     * state of the overwhelming majority of real deployments.
     */
    assistantAvailable?: boolean;
    /**
     * The assessment's whole label vocabulary (W43), which is a superset of
     * what the nodes carry: a label removed from its last node stays here so
     * the autocomplete can offer it again instead of watching someone retype
     * a near-duplicate. Optional for the same reason `scaleLevels` is — the
     * stores component tests build do not supply it.
     */
    tags?: TagRecord[];
    /**
     * W63: this store holds a **frozen** graph — a snapshot being read, not an
     * assessment being edited — and every method of it that would reach the
     * network returns without doing so.
     *
     * All-false `abilities` is not a substitute, and the difference is the
     * reason this flag exists. Abilities decide what the UI *offers*; they are
     * read at `v-if` sites, and the call sites stay wired behind them. Two
     * things then leak through: `loadUnresolvedCommentCounts()` fires from
     * `onMounted` with no ability check at all, which would decorate a frozen
     * version with today's comment counts — and the label ids on a frozen graph
     * are minted for the render (SnapshotViewPresenter::vocabulary()), so one
     * reaching a write would attach an unrelated live label to a node.
     *
     * So this is the structural half: a guard at the method rather than at the
     * button. Optional and defaulting to false, so every existing caller is an
     * ordinary editable store.
     */
    frozen?: boolean;
}

export type SaveState = 'idle' | 'saving' | 'saved' | 'conflict';

export interface ConflictInfo {
    /** What the user was typing, kept so "Keep mine" can still be applied. */
    mine: Partial<NodeRecord>;
    /** The server's current row at the time of the 409. */
    theirs: NodeRecord;
}

/** Joins the uuid path from the root down to one occurrence — the occurrence key ui.md §3.1 requires. */
export function occurrencePath(...uuids: string[]): string {
    return uuids.join('/');
}

/**
 * Factory, not a singleton module export: Assessment/Show.vue creates one
 * instance per page visit and `provide()`s it, and Vitest creates fresh,
 * isolated instances per test (tests/js/stores/assessmentStore.spec.ts) —
 * neither would be possible with a module-level reactive singleton.
 */
export function createAssessmentStore(init: AssessmentStoreInit) {
    const assessment = reactive({ ...init.assessment });
    const abilities = reactive({ ...init.abilities });
    const assistantAvailable = init.assistantAvailable ?? false;
    // Not reactive, and not meant to be: a store is either an editor's or a
    // snapshot reader's for its whole life, and a flag that could be flipped
    // at runtime would be a way to turn a frozen page back into a live one.
    const frozen = init.frozen ?? false;

    const nodes = reactive<Record<string, NodeRecord>>({});
    for (const node of init.nodes) {
        nodes[node.uuid] = { ...node, tags: node.tags ?? [] };
    }

    /**
     * The label vocabulary (W43). Reactive and appended to as labels are
     * created, so a name invented on one node is offered by the autocomplete
     * on the next one without a reload — which is how a shared vocabulary
     * actually forms during a workshop.
     */
    const tags = reactive<TagRecord[]>([...(init.tags ?? [])]);

    const riskAttackLinks = reactive<RiskAttackLink[]>([
        ...init.riskAttackLinks,
    ]);
    const attackDefenceLinks = reactive<AttackDefenceLink[]>([
        ...init.attackDefenceLinks,
    ]);

    const results = ref(init.results) as Ref<CalculationResults>;

    // Immutable for the lifetime of the page — recalibrating a scale is a
    // full navigation, not an in-place edit — so these are converted to the
    // engine's camelCase, id-keyed shape once here rather than on every
    // keystroke inside recomputeLocally().
    const calcScaleLevels: Record<number, CalcScaleLevel> = {};
    for (const level of init.scaleLevels ?? []) {
        calcScaleLevels[level.id] = {
            id: level.id,
            scaleId: level.scale_id,
            kind: level.kind,
            unit: level.unit,
            code: level.code,
            label: level.label,
            ordinal: level.ordinal,
            lowerBound: level.lower_bound,
            upperBound: level.upper_bound,
            representative: level.representative,
        };
    }

    const calcScales: Record<number, CalcScale> = {};
    for (const scale of init.scales ?? []) {
        calcScales[scale.id] = {
            id: scale.id,
            kind: scale.kind,
            unit: scale.unit,
            isSystem: scale.is_system,
        };
    }

    /** Per-occurrence UI state (ui.md §3.1) — keyed by occurrencePath(), never by uuid. */
    const occurrenceExpanded = reactive<Record<string, boolean>>({});

    /**
     * The node card's unresolved-comment badge (ui.md §3.2, W18b). Keyed by
     * node uuid, not occurrence path — like `nodes` itself, a shared node's
     * comment count is one number regardless of how many places it
     * occurs. Populated by loadUnresolvedCommentCounts() below rather than
     * in this factory's body, so a store built in a test with a bare
     * `{ patch: vi.fn(), post: vi.fn(), delete: vi.fn() }` window.axios
     * mock (no `get`) never touches the network unless a caller — a real
     * NodeCard's onMounted — explicitly asks it to.
     */
    const unresolvedCommentCounts = reactive<Record<string, number>>({});
    let commentCountsRequested = false;

    const saveState = ref<SaveState>('idle');
    /** Conflicts are keyed by node uuid — a node being edited in only one place at a time is a safe simplification for W12 (locking/presence is W16). */
    const conflicts = reactive<Record<string, ConflictInfo>>({});

    function rootUuid(): string | null {
        for (const uuid in nodes) {
            if (nodes[uuid].type === 'root') return uuid;
        }
        return null;
    }

    function risksOrder(): string[] {
        return Object.values(nodes)
            .filter((n) => n.type === 'risk')
            .sort((a, b) => a.position - b.position)
            .map((n) => n.uuid);
    }

    function attacksUnderRisk(riskUuid: string): string[] {
        return riskAttackLinks
            .filter((l) => l.risk_uuid === riskUuid)
            .sort((a, b) => a.position - b.position)
            .map((l) => l.attack_uuid);
    }

    function defencesUnderAttack(attackUuid: string): string[] {
        return attackDefenceLinks
            .filter((l) => l.attack_uuid === attackUuid)
            .sort((a, b) => a.position - b.position)
            .map((l) => l.defence_uuid);
    }

    /** Every risk this attack is linked under — the "appears in N places" count for an attack. */
    function risksForAttack(attackUuid: string): string[] {
        return riskAttackLinks
            .filter((l) => l.attack_uuid === attackUuid)
            .map((l) => l.risk_uuid);
    }

    /** Every attack this defence is linked under — the "appears in N places" count for a defence. */
    function attacksForDefence(defenceUuid: string): string[] {
        return attackDefenceLinks
            .filter((l) => l.defence_uuid === defenceUuid)
            .map((l) => l.attack_uuid);
    }

    function occurrenceCount(uuid: string, type: NodeType): number {
        if (type === 'attack') return risksForAttack(uuid).length;
        if (type === 'defence') return attacksForDefence(uuid).length;
        return 1;
    }

    function isExpanded(path: string): boolean {
        return occurrenceExpanded[path] ?? true;
    }

    function toggleExpanded(path: string): void {
        occurrenceExpanded[path] = !isExpanded(path);
    }

    /**
     * Instant, optimistic recompute using W11's TypeScript mirror
     * (resources/js/calc/**, ui.md §3.5's "the numbers are always on
     * screen" applied to the moment right after an edit, before the
     * server round-trip returns). PHP's broadcast result — here, simply
     * whatever the PATCH/POST response would carry once W16 adds it — is
     * always what wins; this function only ever fills the gap between an
     * edit and that response landing.
     */
    function recomputeLocally(): void {
        const graph = {
            assessmentUuid: assessment.uuid,
            currency: assessment.currency,
            amortizationYears: assessment.amortization_years,
            rootTitle: nodes[rootUuid() ?? '']?.title ?? null,
            rootDescription: nodes[rootUuid() ?? '']?.description ?? null,
            risks: {} as Record<string, RiskData>,
            attacks: {} as Record<string, AttackData>,
            defences: {} as Record<string, DefenceData>,
            riskAttackLinks: riskAttackLinks.map((l): CalcRiskAttackLink => ({
                riskUuid: l.risk_uuid,
                attackUuid: l.attack_uuid,
                position: l.position,
            })),
            attackDefenceLinks: attackDefenceLinks.map(
                (l): CalcAttackDefenceLink => ({
                    attackUuid: l.attack_uuid,
                    defenceUuid: l.defence_uuid,
                    position: l.position,
                    strengthMode: l.strength_mode,
                    strengthFraction: l.strength_fraction,
                    strengthLevelId: l.strength_level_id,
                    reductionTarget: l.reduction_target,
                }),
            ),
            scaleLevels: calcScaleLevels,
            scales: calcScales,
        };

        for (const node of Object.values(nodes)) {
            if (node.type === 'risk') {
                graph.risks[node.uuid] = {
                    uuid: node.uuid,
                    title: node.title,
                    impactMode: node.impact_mode,
                    impactAmount: node.impact_amount,
                    impactLevelId: node.impact_level_id,
                };
            } else if (node.type === 'attack') {
                graph.attacks[node.uuid] = {
                    uuid: node.uuid,
                    title: node.title,
                    likelihoodMode: node.likelihood_mode,
                    likelihoodAro: node.likelihood_aro,
                    likelihoodLevelId: node.likelihood_level_id,
                };
            } else if (node.type === 'defence') {
                graph.defences[node.uuid] = {
                    uuid: node.uuid,
                    title: node.title,
                    isTransfer: node.is_transfer,
                    status: node.status,
                    capexAmount: node.capex_amount,
                    opexAnnualAmount: node.opex_annual_amount,
                };
            }
        }

        // W27 defect 2. This used to assemble `scaleLevels: {}` on the
        // reasoning that qualitative values could not be resolved without
        // shipping the scale definitions down — but AssessmentController
        // was already sending them and Show.vue simply dropped them. With
        // an empty calibration RiskCalculator throws "Cannot band-map a
        // value against an empty scale" on the *first* risk that has an
        // impact, quantitative or not, because the band code it derives for
        // display is computed unconditionally. A bare `catch {}` swallowed
        // that on every keystroke, so the docked panel never moved until a
        // reload — the numbers were right, just never immediate.
        if (Object.keys(calcScaleLevels).length === 0) {
            // A store built without a calibration (component tests) has
            // nothing to compute against. The server's figure stands.
            return;
        }

        try {
            results.value = new RiskCalculator().compute(graph);
        } catch (error) {
            // Narrowed to "report it and keep the last good figure on
            // screen". Never silent again: the silence is what let this
            // survive a whole release.
            console.error(
                'Local recompute failed; the totals panel is showing the last figure from the server.',
                error,
            );
        }
    }

    function nodeToPayload(node: NodeRecord): Record<string, unknown> {
        const base: Record<string, unknown> = {
            title: node.title,
            description: node.description,
            updated_at: node.updated_at,
        };

        if (node.type === 'risk') {
            base.impact_mode = node.impact_mode;
            base.impact_amount = node.impact_amount;
            base.impact_level_id = node.impact_level_id;
        } else if (node.type === 'attack') {
            base.likelihood_mode = node.likelihood_mode;
            base.likelihood_aro = node.likelihood_aro;
            base.likelihood_level_id = node.likelihood_level_id;
        } else if (node.type === 'defence') {
            base.is_transfer = node.is_transfer;
            base.status = node.status;
            base.capex_amount = node.capex_amount;
            base.opex_annual_amount = node.opex_annual_amount;
        }

        return base;
    }

    /**
     * Autosave with the 409 conflict path (ui.md §3.5). `patch` is applied
     * optimistically to the local node immediately (so typing feels
     * instant and the local calc mirror reacts), then sent to the server
     * carrying the node's `updated_at` at the moment the request is built.
     *
     * ## One write in flight per node (W88, items 21 and 22)
     *
     * Callers fire this per keystroke — `DetailsTab.vue` commits the title on
     * `@input`, and `RevisionRecorder`'s 120-second coalescing window is what
     * makes that cheap in the database (schema.md §3.9). Nothing made it cheap
     * over the wire, and unserialised it was wrong twice over. Both failures
     * were reproduced before this was written, in
     * `tests/js/stores/nodeWriteOverlap.spec.ts`:
     *
     * - **Item 21.** The second request was built from the node *before* the
     *   first response landed, so it carried an `updated_at` the server had
     *   already superseded — with its own first write. The 409 answer became
     *   "Someone changed this while you were editing". The someone was the
     *   same user, one keystroke earlier.
     * - **Item 22.** The first response echoes the row as saved — title "P" —
     *   and `Object.assign(node, …)` writes it over the record the open
     *   Details tab is bound to. A round trip after typing "Ph", the field
     *   reads "P" again.
     *
     * So writes to one node are serialised, and the keystrokes typed during a
     * round trip are merged into a single follow-up rather than one request
     * each. `baseUpdatedAt` is read at *send* time, after the previous
     * response has been assigned, which is the whole of the fix: a request's
     * own echo can no longer be mistaken for a third party's edit.
     *
     * This deliberately does **not** widen the conflict window, and must not:
     * a genuine second editor moves the server's row under a single write,
     * that write still comes back 409, and the banner still fires. Suppressing
     * 409s would satisfy items 21 and 22 and break ui.md §3.5 — which is why
     * the spec file asserts both sides.
     *
     * Keyed by uuid rather than one global chain, because two nodes edited in
     * the same second are independent rows and serialising them against each
     * other would only make saving feel slower.
     */
    const pendingWrites = new Map<string, Partial<NodeRecord>>();
    const activeWrites = new Map<string, Promise<void>>();

    function updateNode(
        uuid: string,
        patch: Partial<NodeRecord>,
    ): Promise<void> {
        if (frozen) return Promise.resolve();

        const node = nodes[uuid];
        if (!node) return Promise.resolve();

        Object.assign(node, patch);
        recomputeLocally();
        saveState.value = 'saving';

        // Merge into the patch waiting behind the in-flight request, or start
        // one. Merging rather than queueing is what keeps a burst of typing at
        // two requests — the one already going, and one carrying everything
        // since — instead of one per keystroke.
        //
        // Merge-or-set happens *before* the decision below, and the decision
        // is "is a drain running?" and nothing else. An earlier version made
        // the two one branch — a caller that merged returned whatever was in
        // `activeWrites` — and that deadlocked the node for the life of the
        // store as soon as a write failed for anything other than a 409: the
        // failed drain had already ended, so the merge returned
        // `Promise.resolve()` and started nothing, and every later keystroke
        // took the same dead branch. Autosave stopped for that node, silently,
        // and typing again did not recover it. The rule this shape enforces is
        // that a non-empty queue always has a drain behind it.
        const waiting = pendingWrites.get(uuid);
        if (waiting) Object.assign(waiting, patch);
        else pendingWrites.set(uuid, { ...patch });

        const active = activeWrites.get(uuid);
        if (active) return active;

        // Safe in this order: the queue is non-empty, so the loop below awaits
        // at least once before its finally can deregister anything.
        const run = drainWrites(uuid);
        activeWrites.set(uuid, run);

        return run;
    }

    /**
     * Sends whatever has accumulated for this node, one request at a time,
     * until nothing is left. The entry is taken off `pendingWrites` *before*
     * the request goes out, so a keystroke arriving mid-flight starts a fresh
     * pending patch instead of being merged into one already on the wire and
     * silently dropped.
     *
     * The `finally` deregisters this drain **synchronously with the loop
     * exiting**, which is why it is here and not a `.finally()` on the promise
     * the caller holds. Attached from outside, the deregistration is a
     * microtask queued after the promise settles, and in the window between
     * the two `activeWrites` still names a drain that has already stopped
     * looking at the queue — so a keystroke landing in that window would queue
     * a patch nothing would ever send. Inside, that window does not exist:
     * whenever `activeWrites` holds an entry, the loop behind it is still
     * running and will pick up whatever is queued.
     */
    async function drainWrites(uuid: string): Promise<void> {
        try {
            for (;;) {
                const patch = pendingWrites.get(uuid);
                if (!patch) return;
                pendingWrites.delete(uuid);

                saveState.value = 'saving';
                await sendNodeUpdate(uuid, patch);
            }
        } finally {
            activeWrites.delete(uuid);
        }
    }

    async function sendNodeUpdate(
        uuid: string,
        patch: Partial<NodeRecord>,
    ): Promise<void> {
        const node = nodes[uuid];
        // The node was deleted while this write was queued behind another one.
        // There is nothing to send and nothing in flight, so the indicator must
        // not be left reading "Saving…" for the rest of the session.
        if (!node) {
            saveState.value = 'idle';

            return;
        }

        const baseUpdatedAt = node.updated_at;

        try {
            const response = await window.axios.patch<{ node: NodeRecord }>(
                route('assessments.nodes.update', [assessment.uuid, uuid]),
                { ...nodeToPayload(node), updated_at: baseUpdatedAt },
            );
            Object.assign(node, response.data.node);
            // The server's row is authoritative for everything except what
            // the user has typed since this request went out. Without this
            // the echo would revert those keystrokes on screen (item 22) and
            // — worse — the next request, whose payload is built from the
            // node, would then send the reverted value back as if the user
            // had chosen it.
            const typedSince = pendingWrites.get(uuid);
            if (typedSince) Object.assign(node, typedSince);
            delete conflicts[uuid];
            saveState.value = 'saved';
        } catch (error) {
            if (isConflict(error)) {
                const theirs = error.response!.data.node;
                conflicts[uuid] = { mine: { ...patch }, theirs };
                saveState.value = 'conflict';
            } else {
                saveState.value = 'idle';
                throw error;
            }
        }
    }

    /** "Keep mine": re-send the local edit, now based on the server's latest updated_at. */
    async function resolveConflictKeepMine(uuid: string): Promise<void> {
        const conflict = conflicts[uuid];
        if (!conflict) return;
        const node = nodes[uuid];
        Object.assign(node, conflict.theirs, conflict.mine);
        delete conflicts[uuid];
        await updateNode(uuid, conflict.mine);
    }

    /** "Take theirs": discard the local edit and accept the server's row. */
    function resolveConflictTakeTheirs(uuid: string): void {
        const conflict = conflicts[uuid];
        if (!conflict) return;
        Object.assign(nodes[uuid], conflict.theirs);
        delete conflicts[uuid];
        saveState.value = 'saved';
        recomputeLocally();
    }

    /** "Cancel": same effect as taking theirs — the field reverts, nothing is sent. */
    function resolveConflictCancel(uuid: string): void {
        resolveConflictTakeTheirs(uuid);
    }

    /**
     * Creates a child under a parent, with its content, and returns the new
     * node's uuid.
     *
     * Until 2.5.1 this was called the moment "Add child" was pressed, with an
     * empty title, so the node existed — and was broadcast to every
     * collaborator — before anyone had named it (ui.md §3.3's old "insert
     * immediately" rule). It is now called only once a person has confirmed a
     * draft in the node modal (useNodeModal's `NodeDraft`), or accepted an
     * assistant suggestion, so it always carries a title.
     */
    async function addChild(
        parentType: 'root' | 'risk' | 'attack',
        parentUuid: string,
        /**
         * The confirmed draft, or an accepted assistant suggestion (W52): both
         * go through this one write path rather than growing a second one.
         */
        content?: {
            title: string;
            description?: string | null;
            status?: DefenceStatus | null;
            is_transfer?: boolean;
        },
    ): Promise<string> {
        const type =
            parentType === 'root'
                ? 'risk'
                : parentType === 'risk'
                  ? 'attack'
                  : 'defence';

        const body: Record<string, unknown> = {
            type,
            title: content?.title ?? '',
        };
        if (content?.description) body.description = content.description;
        if (type === 'defence' && content?.status) body.status = content.status;
        if (type === 'defence' && content?.is_transfer) body.is_transfer = true;
        if (type === 'attack') body.risk_uuid = parentUuid;
        if (type === 'defence') body.attack_uuid = parentUuid;

        const response = await window.axios.post<{ node: NodeRecord }>(
            route('assessments.nodes.store', assessment.uuid),
            body,
        );
        const node = response.data.node;
        nodes[node.uuid] = node;

        if (type === 'attack') {
            riskAttackLinks.push({
                risk_uuid: parentUuid,
                attack_uuid: node.uuid,
                position: attacksUnderRisk(parentUuid).length - 1,
            });
        } else if (type === 'defence') {
            attackDefenceLinks.push({
                attack_uuid: parentUuid,
                defence_uuid: node.uuid,
                position: defencesUnderAttack(parentUuid).length - 1,
                strength_mode: null,
                strength_fraction: null,
                strength_level_id: null,
                reduction_target: null,
                rationale: null,
            });
        }

        recomputeLocally();

        return node.uuid;
    }

    /**
     * Asks the assistant for children of a node (W52, job B).
     *
     * Returns text and changes nothing. Accepting a suggestion is a separate,
     * deliberate act by a person and goes through `addChild` — the same write
     * path a hand-typed node takes. That separation is the guardrail: the
     * spike measured injected text reaching a model's output on 3 of 3
     * candidates, and `title` is a field the accept path legitimately reads,
     * so no parser can tell an injected suggestion from a genuine one.
     */
    async function suggestChildren(
        parentUuid: string,
        count = 5,
    ): Promise<{
        childType: string;
        suggestions: AssistantSuggestion[];
        cancelled: boolean;
    }> {
        const response = await window.axios.post<{
            child_type?: string;
            suggestions?: AssistantSuggestion[];
            cancelled?: boolean;
        }>(route('assessments.assistant.suggestions', assessment.uuid), {
            parent_uuid: parentUuid,
            count,
        });

        // W58: a stopped generation answers 200 with `{cancelled: true}` and no
        // suggestions. It is not an error — the person asked for it — so it
        // must not travel the error path and produce a red message about the
        // assistant being unreachable.
        return {
            childType: response.data.child_type ?? 'attack',
            suggestions: response.data.suggestions ?? [],
            cancelled: response.data.cancelled === true,
        };
    }

    async function deleteNode(uuid: string): Promise<void> {
        if (frozen) return;

        const node = nodes[uuid];
        if (!node) return;

        await window.axios.delete(
            route('assessments.nodes.destroy', [assessment.uuid, uuid]),
        );

        delete nodes[uuid];
        for (let i = riskAttackLinks.length - 1; i >= 0; i--) {
            if (riskAttackLinks[i].attack_uuid === uuid)
                riskAttackLinks.splice(i, 1);
        }
        for (let i = attackDefenceLinks.length - 1; i >= 0; i--) {
            if (
                attackDefenceLinks[i].attack_uuid === uuid ||
                attackDefenceLinks[i].defence_uuid === uuid
            ) {
                attackDefenceLinks.splice(i, 1);
            }
        }
        recomputeLocally();
    }

    /**
     * Fetches the whole assessment's unresolved-comment counts once
     * (ui.md §3.2's node-card indicator, W18b) and caches them locally.
     * Idempotent and safe to call from every NodeCard occurrence's
     * onMounted — the same node can render many times in the DAG, but
     * this issues at most one request per store instance. On failure,
     * clears the guard so a later mount can retry rather than leaving the
     * badge permanently empty for the rest of the session.
     */
    async function loadUnresolvedCommentCounts(): Promise<void> {
        // The one abilities could never have stopped: it fires from onMounted
        // with no ability check, so a frozen page would decorate an old
        // version with today’s unresolved-comment counts.
        if (frozen) return;
        if (commentCountsRequested) return;
        commentCountsRequested = true;
        try {
            const counts = await fetchUnresolvedCommentCounts(assessment.uuid);
            Object.assign(unresolvedCommentCounts, counts);
        } catch {
            commentCountsRequested = false;
        }
    }

    /**
     * The label names on one node, which is exactly the shape
     * useFilters.ts's `tagsForNode` injection has been waiting for since
     * W15. Names, not ids: the filter chips are the vocabulary as a person
     * reads it, and a name is stable across the assessment while an id means
     * nothing outside it.
     */
    function tagNamesFor(uuid: string): string[] {
        return nodes[uuid]?.tags.map((tag) => tag.name) ?? [];
    }

    /** The vocabulary, in the order a reader scans a list: alphabetical. */
    const availableTagNames = computed(() =>
        [...tags].map((tag) => tag.name).sort((a, b) => a.localeCompare(b)),
    );

    /**
     * Labels a node, creating the label if this is its first use.
     *
     * Deliberately **not** optimistic, unlike `updateNode`. The server
     * decides what the label is called: typing "pci dss" where "PCI DSS"
     * already exists resolves to the existing one, and rendering the typed
     * spelling first would show a chip that changes its name a moment later
     * — or, worse, would not change and leave two people looking at the same
     * tag spelled two ways.
     */
    async function attachTag(uuid: string, name: string): Promise<void> {
        if (frozen) return;

        const node = nodes[uuid];
        if (!node || name.trim() === '') return;

        const response = await window.axios.post<{ tag: TagRecord }>(
            route('assessments.nodes.tags.store', [assessment.uuid, uuid]),
            { name },
        );
        const tag = response.data.tag;

        if (!node.tags.some((existing) => existing.id === tag.id)) {
            node.tags.push(tag);
        }
        if (!tags.some((existing) => existing.id === tag.id)) {
            tags.push(tag);
        }
    }

    /**
     * Unlabels a node. The tag stays in `tags`: it is still part of the
     * assessment's vocabulary, and dropping it from the autocomplete the
     * moment its last node loses it would make removing a label by mistake
     * expensive to undo.
     */
    async function detachTag(uuid: string, tagId: number): Promise<void> {
        if (frozen) return;

        const node = nodes[uuid];
        if (!node) return;

        await window.axios.delete(
            route('assessments.nodes.tags.destroy', [
                assessment.uuid,
                uuid,
                tagId,
            ]),
        );

        const index = node.tags.findIndex((tag) => tag.id === tagId);
        if (index !== -1) node.tags.splice(index, 1);
    }

    return {
        assessment,
        abilities,
        assistantAvailable,
        frozen,
        nodes,
        tags,
        availableTagNames,
        tagNamesFor,
        attachTag,
        detachTag,
        riskAttackLinks,
        attackDefenceLinks,
        results,
        saveState,
        conflicts,
        unresolvedCommentCounts,
        loadUnresolvedCommentCounts,
        rootUuid,
        risksOrder,
        attacksUnderRisk,
        defencesUnderAttack,
        risksForAttack,
        attacksForDefence,
        occurrenceCount,
        isExpanded,
        toggleExpanded,
        updateNode,
        addChild,
        suggestChildren,
        deleteNode,
        resolveConflictKeepMine,
        resolveConflictTakeTheirs,
        resolveConflictCancel,
        recomputeLocally,
    };
}

export type AssessmentStore = ReturnType<typeof createAssessmentStore>;

/**
 * provide()/inject() key: Assessment/Show.vue creates the store once and
 * provides it; every Tree/** component (TreeNode, NodeCard, and W13/W14/
 * W15's later additions) injects it rather than receiving it prop-drilled
 * through every recursion level.
 */
export const ASSESSMENT_STORE_KEY: InjectionKey<AssessmentStore> =
    Symbol('assessmentStore');

function isConflict(
    error: unknown,
): error is { response: { status: 409; data: { node: NodeRecord } } } {
    return (
        typeof error === 'object' &&
        error !== null &&
        'response' in error &&
        (error as { response?: { status?: number } }).response?.status === 409
    );
}
