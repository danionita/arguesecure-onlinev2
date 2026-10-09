<?php

namespace App\Http\Controllers;

use App\Domain\Locking\LockManager;
use App\Models\Assessment;
use App\Models\Node;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Field-level locking (PLAN.md Phase 6 item 3, security.md). No FormRequest
 * class here (this package owns only this controller, not
 * app/Http/Requests/**) — authorization and the same cross-assessment IDOR
 * check NodeController's own docblock describes are done inline instead,
 * matching the pattern the W12/W14 `reorder`/`move` closures in
 * routes/web.php already use for the same reason.
 *
 * Every ability check here is `update`, never `view`: is_public and a
 * share-token session both grant `view` only (CLAUDE.md, AssessmentPolicy),
 * and a lock is a write-intent signal — a viewer or a read-only share-link
 * guest may watch a field light up as locked, but must never be able to
 * acquire one themselves.
 */
class LockController extends Controller
{
    /**
     * Deliberately a fixed allow-list, not "whatever string the route
     * accepts" — the same "no dynamic column names from request input"
     * lesson security.md draws from the legacy generic field/value
     * endpoint (defect #7), applied here even though `$field` only ever
     * becomes part of a Redis key, never a SQL identifier: an attacker
     * naming an arbitrary field is still writing arbitrary keys into the
     * same Redis keyspace this app uses for other things.
     *
     * @var list<string>
     */
    /**
     * The whole node, held by the node modal while it is open (2.5.1) so a
     * second person opening it gets it read-only. NodeController::update
     * refuses writes from anyone but its holder.
     */
    public const NODE_LOCK = 'node';

    private const ALLOWED_FIELDS = [
        self::NODE_LOCK,
        'title',
        'description',
        'impact_amount',
        'impact_level_id',
        'likelihood_aro',
        'likelihood_level_id',
        'status',
        'capex_amount',
        'opex_annual_amount',
    ];

    public function store(Request $request, Assessment $assessment, Node $node, string $field, LockManager $locks): JsonResponse
    {
        $user = $this->authorizeField($request, $assessment, $node, $field);

        $holder = $locks->acquire($assessment->uuid, $node->uuid, $field, $user->id, $user->name);

        if ($holder['user_id'] !== $user->id) {
            return response()->json(['message' => 'Locked by someone else.', 'holder' => $holder], 409);
        }

        return response()->json(['holder' => $holder], 201);
    }

    /**
     * The 15s client heartbeat (ui.md §6, PLAN.md Phase 6 item 3). Despite
     * PLAN.md calling this a "whisper", it is a normal authenticated HTTP
     * request through this controller, not a Pusher/Reverb client event —
     * see resources/js/composables/useRealtime.ts's docblock for why a real
     * client event (relayed peer-to-peer by Reverb, never touching this
     * app) cannot be the thing a refresh's authorization depends on.
     */
    public function update(Request $request, Assessment $assessment, Node $node, string $field, LockManager $locks): JsonResponse
    {
        $user = $this->authorizeField($request, $assessment, $node, $field);

        if (! $locks->refresh($assessment->uuid, $node->uuid, $field, $user->id)) {
            return response()->json(['message' => 'Lock expired or held by someone else.'], 409);
        }

        return response()->json(['ok' => true]);
    }

    /** Blur/save/navigate (ui.md §6, PLAN.md Phase 6 item 3). A no-op, not an error, if the lock already expired or was never this user's. */
    public function destroy(Request $request, Assessment $assessment, Node $node, string $field, LockManager $locks): JsonResponse
    {
        $user = $this->authorizeField($request, $assessment, $node, $field);

        $locks->release($assessment->uuid, $node->uuid, $field, $user->id);

        return response()->json(status: 204);
    }

    private function authorizeField(Request $request, Assessment $assessment, Node $node, string $field): User
    {
        $user = $request->user();

        abort_unless($user !== null && $user->can('update', $assessment), 403);

        // IDOR check (security.md §3.3 pattern, NodeController's own
        // docblock): {node:uuid} is bound globally, not scoped to
        // {assessment:uuid}, so a node uuid from a different assessment
        // must be rejected explicitly rather than trusted from the URL.
        abort_if($node->assessment_id !== $assessment->id, 404);

        abort_unless(in_array($field, self::ALLOWED_FIELDS, true), 422, 'Unknown field.');

        return $user;
    }
}
