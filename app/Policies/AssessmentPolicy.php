<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Session;

/**
 * The single source of truth for assessment authorization (schema.md §5,
 * security.md §3). NodePolicy and CommentPolicy are thin delegates to this
 * class — no policy in this application ever grants access based on a
 * node's own attributes. That rule is what fixed legacy defect #5, where
 * per-type policies checked `$node->tree->public || owner` and let any
 * logged-in user edit or delete any public assessment's contents.
 *
 * Resolution order, matching security.md §3.1 exactly:
 * is_admin -> owner -> assessment_user.role -> is_public (view only) ->
 * a valid share token held in the session (view only).
 */
class AssessmentPolicy
{
    /** @var array<string, list<string>> abilities each role satisfies, ladder-inclusive */
    private const ROLE_ABILITIES = [
        'manager' => ['view', 'comment', 'update', 'manage', 'delete'],
        'editor' => ['view', 'comment', 'update'],
        'commenter' => ['view', 'comment'],
        'viewer' => ['view'],
    ];

    public function view(?User $user, Assessment $assessment): bool
    {
        return $this->isAdmin($user)
            || $this->isOwner($user, $assessment)
            || $this->roleGrants($user, $assessment, 'view')
            || $assessment->is_public
            || $this->hasValidShareToken($assessment);
    }

    /**
     * is_public grants READ ONLY. In v1 "public" meant world-writable
     * (security.md §3.1) — that was defect #4, not a feature, and every
     * ability below this point deliberately omits the is_public and
     * share-token checks.
     */
    public function comment(?User $user, Assessment $assessment): bool
    {
        return $this->isAdmin($user)
            || $this->isOwner($user, $assessment)
            || $this->roleGrants($user, $assessment, 'comment');
    }

    public function update(?User $user, Assessment $assessment): bool
    {
        return $this->isAdmin($user)
            || $this->isOwner($user, $assessment)
            || $this->roleGrants($user, $assessment, 'update');
    }

    public function manage(?User $user, Assessment $assessment): bool
    {
        return $this->isAdmin($user)
            || $this->isOwner($user, $assessment)
            || $this->roleGrants($user, $assessment, 'manage');
    }

    public function delete(?User $user, Assessment $assessment): bool
    {
        return $this->isAdmin($user)
            || $this->isOwner($user, $assessment)
            || $this->roleGrants($user, $assessment, 'delete');
    }

    /**
     * Publishing an assessment as a template (W45, D12; narrowed in 2.5.1).
     *
     * Admins only. A template is offered to every account on the instance —
     * anyone can create their own assessment from it, which is a full copy of
     * its risks, attacks, defences and values — so what the template library
     * contains is an instance-wide decision, the same kind an admin makes
     * about users and defaults. Through 2.5 the owner could publish too.
     *
     * It grants nothing on the reading side of the original: publishing does
     * not touch `is_public`, and being a template adds no write ability for
     * anyone — tests/Feature/Templates/TemplateSecurityTest.php asserts that
     * verb by verb rather than once.
     */
    public function saveAsTemplate(?User $user, Assessment $assessment): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Creating an assessment from a template (2.5.1): any signed-in account,
     * provided the assessment is one. Templates are published by an admin
     * precisely so everyone can start from them; through 2.5 this needed
     * `view` on the template, so the bundled ones — owned by the first admin
     * and not public — were invisible to everybody else.
     */
    public function useAsTemplate(?User $user, Assessment $assessment): bool
    {
        return $user !== null && (bool) $assessment->is_template;
    }

    /** Only the owner or an admin transfers ownership or hard-deletes (schema.md §3.7). */
    public function transferOwnership(?User $user, Assessment $assessment): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $assessment);
    }

    public function forceDelete(?User $user, Assessment $assessment): bool
    {
        return $this->transferOwnership($user, $assessment);
    }

    private function isAdmin(?User $user): bool
    {
        return $user !== null && $user->is_admin;
    }

    private function isOwner(?User $user, Assessment $assessment): bool
    {
        return $user !== null && $user->id === $assessment->owner_user_id;
    }

    private function roleGrants(?User $user, Assessment $assessment, string $ability): bool
    {
        if ($user === null) {
            return false;
        }

        $role = $this->roleFor($user, $assessment);

        return $role !== null && in_array($ability, self::ROLE_ABILITIES[$role] ?? [], true);
    }

    private function roleFor(User $user, Assessment $assessment): ?string
    {
        // Prefer an already-eager-loaded members collection to avoid an
        // extra query per policy check when the caller preloaded it.
        if ($assessment->relationLoaded('members')) {
            return $assessment->members->firstWhere('id', $user->id)?->pivot?->role;
        }

        return $assessment->members()->where('users.id', $user->id)->value('role');
    }

    /**
     * security.md §3.2: presenting a share token establishes a session flag
     * scoped to that assessment id; revocation invalidates existing sessions
     * by storing the token's created_at in the session and comparing it.
     * The flag is written by the share-link resolver (a later package) as
     * `share_token.{assessment_id} => ['token' => ..., 'created_at' => ...]`.
     */
    private function hasValidShareToken(Assessment $assessment): bool
    {
        if ($assessment->share_token === null || $assessment->share_token_created_at === null) {
            return false;
        }

        $flag = Session::get("share_token.{$assessment->id}");

        if (! is_array($flag) || ! isset($flag['token'], $flag['created_at'])) {
            return false;
        }

        if (! hash_equals($assessment->share_token, (string) $flag['token'])) {
            return false;
        }

        return Carbon::parse($flag['created_at'])->equalTo($assessment->share_token_created_at);
    }
}
