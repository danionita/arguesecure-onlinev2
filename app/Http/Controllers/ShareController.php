<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Sharing\CollaboratorService;
use App\Domain\Sharing\OwnershipTransferService;
use App\Domain\Sharing\ShareTokenService;
use App\Http\Requests\Sharing\AddCollaboratorRequest;
use App\Http\Requests\Sharing\ManageShareTokenRequest;
use App\Http\Requests\Sharing\RemoveCollaboratorRequest;
use App\Http\Requests\Sharing\SearchUsersRequest;
use App\Http\Requests\Sharing\ShareIndexRequest;
use App\Http\Requests\Sharing\TransferOwnershipRequest;
use App\Http\Requests\Sharing\UpdateCollaboratorRequest;
use App\Http\Requests\Sharing\UpdatePublicRequest;
use App\Models\Assessment;
use App\Models\AssessmentUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;

/**
 * The sharing surface for a single assessment (PLAN.md Phase 3 item 5).
 * Every action-bearing method is fronted by a FormRequest whose
 * authorize() calls AssessmentPolicy — no exceptions, per CLAUDE.md.
 * `resolveToken` is the one public exception to "every route touching an
 * assessment goes through the policy" in spirit only: it doesn't call the
 * policy because it doesn't grant access itself — it writes the session
 * flag that AssessmentPolicy::view() (via hasValidShareToken()) reads on
 * every subsequent request. That check is what actually decides access.
 *
 * All mutating/read endpoints return JSON: there is no Assessment Inertia
 * page yet (W12 owns it), so this dialog is designed to be dropped into
 * that page later as a self-fetching Vue component rather than depending
 * on Inertia shared props that don't exist.
 */
class ShareController extends Controller
{
    public function index(ShareIndexRequest $request, Assessment $assessment): JsonResponse
    {
        return response()->json($this->payload($assessment));
    }

    public function searchUsers(SearchUsersRequest $request, Assessment $assessment): JsonResponse
    {
        $validated = $request->validated();

        $users = CollaboratorService::searchAddable($assessment, $validated['query']);

        return response()->json([
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]),
        ]);
    }

    public function storeCollaborator(AddCollaboratorRequest $request, Assessment $assessment): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $target */
        $target = User::query()->findOrFail($validated['user_id']);
        /** @var User $invitedBy */
        $invitedBy = $request->user();

        CollaboratorService::add($assessment, $target, $validated['role'], $invitedBy);

        return response()->json($this->payload($assessment), 201);
    }

    public function updateCollaborator(UpdateCollaboratorRequest $request, Assessment $assessment, AssessmentUser $collaborator): JsonResponse
    {
        $validated = $request->validated();

        CollaboratorService::updateRole($assessment, $collaborator, $validated['role']);

        return response()->json($this->payload($assessment));
    }

    public function destroyCollaborator(RemoveCollaboratorRequest $request, Assessment $assessment, AssessmentUser $collaborator): JsonResponse
    {
        CollaboratorService::remove($assessment, $collaborator);

        return response()->json($this->payload($assessment));
    }

    public function transferOwnership(TransferOwnershipRequest $request, Assessment $assessment): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $newOwner */
        $newOwner = User::query()->findOrFail($validated['user_id']);

        OwnershipTransferService::transfer($assessment, $newOwner);

        return response()->json($this->payload($assessment->fresh()));
    }

    public function updatePublic(UpdatePublicRequest $request, Assessment $assessment): JsonResponse
    {
        $validated = $request->validated();

        // W8b: UpdatePublicRequest (app/Http/Requests/Sharing/**) is
        // outside this package's file ownership, so the instance-wide
        // rule lives here instead of in that FormRequest's rules(). An
        // instance that forbids public assessments must refuse the flip
        // server-side, not merely hide the toggle in the UI (W8b brief) —
        // turning an already-public assessment back to private is always
        // allowed, since that only ever narrows access.
        if ($validated['is_public'] && ! InstanceSettings::publicAssessmentsAllowed()) {
            return response()->json([
                'message' => 'This instance does not allow public assessments.',
            ], 422);
        }

        // is_public is fillable but this still goes through validated(),
        // never all() (security.md §7) — the FormRequest already rejected
        // anything but a real boolean.
        $assessment->update(['is_public' => $validated['is_public']]);

        return response()->json($this->payload($assessment));
    }

    public function storeToken(ManageShareTokenRequest $request, Assessment $assessment): JsonResponse
    {
        ShareTokenService::generate($assessment);

        return response()->json($this->payload($assessment));
    }

    public function destroyToken(ManageShareTokenRequest $request, Assessment $assessment): JsonResponse
    {
        ShareTokenService::revoke($assessment);

        return response()->json($this->payload($assessment));
    }

    /**
     * The read-only link's landing point (security.md §3.2). No auth
     * middleware — this must work for someone with no account at all.
     * Establishes a session flag scoped to the assessment id, then
     * redirects to a clean URL so the token never sits in browser
     * history, a Referer header, or an access log line past this one
     * request.
     */
    public function resolveToken(string $token): RedirectResponse
    {
        $assessment = ShareTokenService::resolve($token);

        if ($assessment === null) {
            abort(404);
        }

        Session::put("share_token.{$assessment->id}", [
            'token' => $assessment->share_token,
            // Carbon::make(), not a bare nullsafe call: Assessment (out of
            // this package's ownership) has no @property docblock, so
            // static analysis can't see through its `casts()` method to
            // know share_token_created_at is really a Carbon instance at
            // runtime — Carbon::make() re-establishes that statically
            // without touching app/Models/**.
            'created_at' => Carbon::make($assessment->share_token_created_at)?->toJSON(),
        ]);

        // W114: the editor, which renders read-only because the policy
        // grants a token holder `view` alone. This used to point at the
        // dashboard, which requires a login, so a holder without an
        // account landed on the sign-in page despite holding a valid grant.
        return redirect()->route('assessments.show', $assessment);
    }

    /**
     * @return array{
     *     owner: array{id: int, name: string, email: string},
     *     collaborators: list<array{id: int, user: array{id:int,name:string,email:string}, role: string, invited_by: ?array{id:int,name:string}, invited_at: ?string, accepted_at: ?string}>,
     *     is_public: bool,
     *     share_link: ?string,
     *     share_token_created_at: ?string,
     * }
     */
    private function payload(Assessment $assessment): array
    {
        $assessment->loadMissing('owner');

        // Queried directly against the pivot model rather than through
        // Assessment::members() — a BelongsToMany's dot-notation eager
        // loading reaches relations on the *related* model (User), not on
        // the pivot itself, and invitedBy() lives on AssessmentUser.
        $collaborators = AssessmentUser::query()
            ->where('assessment_id', $assessment->id)
            ->with(['user', 'invitedBy'])
            ->orderBy('invited_at')
            ->get();

        return [
            'owner' => [
                'id' => $assessment->owner->id,
                'name' => $assessment->owner->name,
                'email' => $assessment->owner->email,
            ],
            'collaborators' => $collaborators
                ->map(fn (AssessmentUser $collaborator) => [
                    'id' => $collaborator->id,
                    'user' => [
                        'id' => $collaborator->user->id,
                        'name' => $collaborator->user->name,
                        'email' => $collaborator->user->email,
                    ],
                    'role' => $collaborator->role,
                    'invited_by' => $collaborator->invitedBy ? [
                        'id' => $collaborator->invitedBy->id,
                        'name' => $collaborator->invitedBy->name,
                    ] : null,
                    'invited_at' => $collaborator->invited_at->toJSON(),
                    'accepted_at' => $collaborator->accepted_at?->toJSON(),
                ])
                ->values()
                ->all(),
            'is_public' => (bool) $assessment->is_public,
            'share_link' => $assessment->share_token !== null
                ? route('share.resolve', $assessment->share_token)
                : null,
            'share_token_created_at' => Carbon::make($assessment->share_token_created_at)?->toJSON(),
        ];
    }
}
