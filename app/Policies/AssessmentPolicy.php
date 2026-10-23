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

    /**
     * A template has no owner and is not shared (W141, T2): every ability on
     * it is an admin's, whatever `is_public`, a share token or a stray
     * collaborator row says, except the two below. One hook for all of them, so
     * an ability added later cannot forget the rule and fall through to the
     * owner, role and public paths this closes.
     *
     * - `useAsTemplate` is the one any signed-in account holds: it falls through.
     * - `transferOwnership` is nobody's: there is no owner to hand it from.
     *
     * Erasing a template that reached the trash (`forceDelete`) is an admin's too.
     */
    public function before(?User $user, string $ability, mixed $assessment = null): ?bool
    {
        if (! $assessment instanceof Assessment || ! $assessment->is_template) {
            return null;
        }

        return match ($ability) {
            'useAsTemplate' => null,
            'transferOwnership' => false,
            default => $this->isAdmin($user),
        };
    }

    /** Creating a template from nothing, on the Templates page (W141). */
    public function createTemplate(?User $user): bool
    {
        return $this->isAdmin($user);
    }

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
     * Since W141 publishing copies the assessment into a separate template
     * and writes nothing to the original, so this gates the copy, not a flag.
     * A template itself is reachable only by admins (`view`, `update`,
     * `manage` and `delete` above), whatever `is_public` or a stray
     * collaborator row says, and has no owner to transfer to — asserted verb
     * by verb in tests/Feature/Templates/TemplateSecurityTest.php.
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

    /**
     * Only the owner or an admin transfers ownership or hard-deletes (schema.md §3.7).
     * A template has no owner to hand over, so nobody does, an admin included.
     */
    public function transferOwnership(?User $user, Assessment $assessment): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $assessment);
    }

    public function forceDelete(?User $user, Assessment $assessment): bool
    {
        // A template that ended up in the trash (a bundled one deleted before
        // the conversion, or through the generic delete route) has no owner to
        // erase it, so an admin does.
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
