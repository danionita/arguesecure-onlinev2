<?php

namespace App\Domain\Templates;

use App\Domain\Structure\AssessmentCloner;
use App\Models\Assessment;
use Illuminate\Support\Facades\DB;

/**
 * Takes templates that are still somebody's assessment and makes them
 * ownerless (W141, T6). Run once by the migration that adds the CHECK.
 *
 * Two cases, because a published assessment is two things at once and only one
 * of them is the library's:
 *
 * - **A bundled template** (its root uuid is one of the fixtures') is
 *   converted in place. The seeder decides "already seeded" by that root uuid,
 *   so a copy with a new one would be seeded a second time on the next deploy.
 *   Nobody's working copy lives there: the first admin only ever held it.
 * - **Any other template** is copied to a new ownerless template and the
 *   original goes back to being an ordinary assessment, owner and collaborators
 *   untouched. Converting it in place would take a person's assessment away.
 *
 * Rerunning changes nothing: after a run no template has an owner, which is
 * what this selects on.
 */
final class TemplateMigrator
{
    public function __construct(private readonly AssessmentCloner $cloner) {}

    /**
     * All or nothing: the conversions share one transaction, so a failure
     * part-way (a copy that cannot be made) leaves every template as it was and
     * a retry cannot copy one twice.
     *
     * @return array{converted: int, copied: int, unflagged: int}
     */
    public function run(): array
    {
        return DB::transaction(fn (): array => $this->convertAll());
    }

    /** @return array{converted: int, copied: int, unflagged: int} */
    private function convertAll(): array
    {
        $bundled = array_flip($this->bundledRootUuids());
        $counts = ['converted' => 0, 'copied' => 0, 'unflagged' => 0];

        $templates = Assessment::query()->withTrashed()
            ->where('is_template', true)
            ->whereNotNull('owner_user_id')
            ->get();

        foreach ($templates as $template) {
            $rootUuid = DB::table('nodes')
                ->where('assessment_id', $template->id)->where('type', 'root')->value('uuid');

            if (isset($bundled[(string) $rootUuid])) {
                $this->convertInPlace($template);
                $counts['converted']++;
            } elseif ($template->trashed()) {
                // A deleted assessment is not worth a library entry.
                DB::table('assessments')->where('id', $template->id)->update(['is_template' => false]);
                $counts['unflagged']++;
            } else {
                $this->cloner->cloneAsTemplate($template);
                DB::table('assessments')->where('id', $template->id)->update(['is_template' => false]);
                $counts['copied']++;
            }
        }

        return $counts;
    }

    private function convertInPlace(Assessment $template): void
    {
        DB::transaction(function () use ($template): void {
            $nodeIds = DB::table('nodes')->where('assessment_id', $template->id)->pluck('id');

            DB::table('node_assignees')->whereIn('node_id', $nodeIds)->delete();
            DB::table('comments')->where('assessment_id', $template->id)->delete();
            DB::table('assessment_user')->where('assessment_id', $template->id)->delete();
            DB::table('assessments')->where('id', $template->id)->update([
                'owner_user_id' => null,
                'is_public' => false,
                'share_token' => null,
                'share_token_created_at' => null,
            ]);
        });
    }

    /** @return list<string> */
    private function bundledRootUuids(): array
    {
        $uuids = [];

        foreach (glob(database_path('seeders/templates/*.json')) ?: [] as $path) {
            $fixture = json_decode((string) file_get_contents($path), true);

            if (is_array($fixture) && ($fixture['kind'] ?? null) === 'assessment' && isset($fixture['root_uuid'])) {
                $uuids[] = (string) $fixture['root_uuid'];
            }
        }

        return $uuids;
    }
}
