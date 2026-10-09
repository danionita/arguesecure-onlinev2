<?php

namespace App\Domain\Comments;

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Finds `@name` mentions in a comment body (W47, item 15).
 *
 * ## Only collaborators, and that is the security content of this package
 *
 * The candidate set is the assessment's owner and its collaborators, never
 * `users`. Matching against every account would turn any comment box into an
 * account-existence oracle: type `@dana`, see whether a notification lands or
 * whether the name renders as a link, and you have learned that a Dana has an
 * account on this instance — from inside an assessment she has nothing to do
 * with. That is a disclosure with no feature behind it, so the query never
 * touches the users table unfiltered. `tests/Feature/Notifications` asserts it
 * directly rather than by inspection.
 *
 * ## What counts as a match
 *
 * For each candidate, in order:
 *
 *  1. `@Dana Okonkwo` — the full display name, spaces included.
 *  2. `@DanaOkonkwo` — the same name with its spaces removed, which is what
 *     people type when they expect an autocomplete that is not there yet.
 *  3. `@Dana` — the first word, **only when it is unique** among candidates.
 *
 * All three are case-insensitive, and a match must end at a word boundary so
 * `@Dan` does not reach Dana.
 *
 * ## Ambiguity notifies nobody
 *
 * Two collaborators called Dana make `@dana` unresolvable. Guessing sends the
 * message to the wrong person, and notifying both sends half of them a message
 * that was not for them — worse than a mention that quietly does nothing,
 * because both failures are invisible to the author. The full name still
 * works, and is the thing to type when there are two Danas in the room.
 */
class MentionParser
{
    /**
     * The collaborators named in this body.
     *
     * @return Collection<int, User> keyed by user id, so the same person named
     *                               twice in one comment is one mention
     */
    public function parse(Assessment $assessment, string $body): Collection
    {
        $candidates = $this->candidates($assessment);

        if ($candidates->isEmpty() || ! str_contains($body, '@')) {
            return collect();
        }

        $firstNameCounts = $candidates
            ->countBy(fn (User $user): string => $this->firstName($user->name));

        return $candidates
            ->filter(function (User $user) use ($body, $firstNameCounts): bool {
                $forms = [$user->name, str_replace(' ', '', $user->name)];

                $first = $this->firstName($user->name);
                if (($firstNameCounts[$first] ?? 0) === 1) {
                    $forms[] = $first;
                }

                foreach (array_unique($forms) as $form) {
                    if ($form !== '' && $this->mentions($body, $form)) {
                        return true;
                    }
                }

                return false;
            })
            ->keyBy(fn (User $user): int => $user->id);
    }

    /**
     * The owner and every collaborator, active accounts only.
     *
     * A deactivated account cannot read the assessment, so a notification for
     * it would sit unread forever while the author believes they reached
     * someone.
     *
     * @return Collection<int, User>
     */
    private function candidates(Assessment $assessment): Collection
    {
        return User::query()
            ->select(['id', 'name'])
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('id', $assessment->owner_user_id)
                ->orWhereHas('sharedAssessments', fn ($q) => $q->where('assessments.id', $assessment->id)))
            ->get();
    }

    /**
     * `@form` in the body, case-insensitively, ending at a word boundary.
     *
     * The form is quoted before it reaches the pattern: a display name is user
     * input and may contain regex metacharacters, and an unescaped `.` in a
     * name would silently widen every match this class makes.
     */
    private function mentions(string $body, string $form): bool
    {
        $pattern = '/@'.preg_quote($form, '/').'\b/iu';

        return preg_match($pattern, $body) === 1;
    }

    private function firstName(string $name): string
    {
        return explode(' ', trim($name))[0];
    }
}
