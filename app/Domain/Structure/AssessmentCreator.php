<?php

namespace App\Domain\Structure;

use App\Domain\Scales\ScaleSets;
use App\Models\Assessment;
use App\Models\RootNode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates an assessment from nothing.
 *
 * This existed nowhere until W26. Every route in the application is
 * `assessments/{assessment}/...`, the only thing that could produce an
 * assessment was cloning an existing one (a template is itself an
 * assessment), and every test built them with `Assessment::factory()` — so
 * a fresh deployment could complete first-run setup, log in, and then had
 * no way to create its first assessment at all. docs/PLAN.md never
 * assigned this to a work package; it fell between W8 (auth/admin), W9
 * (sharing) and W12 (the tree editor, which renders an assessment it is
 * handed).
 *
 * Two invariants make this more than a `create()` call:
 *
 * 1. **The title lives on the root node, not on the assessment**
 *    (schema.md: "Assessment has no title column ... listings join `nodes`
 *    on `type = 'root'`"). So creating an assessment means creating two
 *    rows, and a failure between them would leave an assessment that
 *    renders with no title and violates `nodes_root_unique` on the next
 *    attempt — hence the transaction.
 * 2. **The three scale FKs are NOT NULL**, so an assessment cannot exist
 *    before the system scales do. Those are seeded by
 *    Database\Seeders\SystemScaleSeeder, which no deployment ran: the
 *    docker entrypoint migrated but never seeded, so a fresh instance had
 *    zero scales and this would have failed on a foreign key with nothing
 *    to suggest why. The entrypoint now seeds them; this class still
 *    checks, because a clear exception beats a constraint violation.
 *    **Which** three it uses stopped being "whatever is marked system" in
 *    W77, when a second set made that ambiguous; systemScales() below
 *    explains what changed and why the naive version was a trap.
 */
final class AssessmentCreator
{
    public function create(User $owner, string $title, ?string $description = null, ?string $scaleSetKey = null): Assessment
    {
        return $this->build($owner, $owner, $title, $description, $scaleSetKey);
    }

    /**
     * A template, from nothing (W141): ownerless, with the admin who made it
     * recorded as the author of its root node only. Everything else about it
     * is `create()`'s, which is the point: the same editor opens it.
     */
    public function createTemplate(User $author, string $title, ?string $description = null, ?string $scaleSetKey = null): Assessment
    {
        return $this->build(null, $author, $title, $description, $scaleSetKey);
    }

    private function build(?User $owner, User $author, string $title, ?string $description, ?string $scaleSetKey): Assessment
    {
        $key = $scaleSetKey ?? ScaleSets::DEFAULT_KEY;
        $scales = $this->systemScales($key);

        return DB::transaction(function () use ($owner, $author, $title, $description, $scales, $key): Assessment {
            $assessment = new Assessment;
            // Identity and configuration columns are deliberately absent
            // from Assessment::$fillable, so they are assigned rather than
            // mass-assigned — see CLAUDE.md's "never mass-assign an identity
            // column", which has broken three packages here already.
            $assessment->owner_user_id = $owner?->id;
            $assessment->is_template = $owner === null;
            $assessment->impact_scale_id = $scales['impact'];
            $assessment->likelihood_scale_id = $scales['likelihood'];
            $assessment->strength_scale_id = $scales['strength'];
            $assessment->scale_set_key = $key;
            $assessment->save();

            $root = new RootNode(['title' => $title, 'description' => $description]);
            $root->assessment_id = $assessment->id;
            $root->position = 0;
            $root->created_by = $author->id;
            $root->updated_by = $author->id;
            $root->save();

            return $assessment;
        });
    }

    /**
     * The three scales a new assessment starts from.
     *
     * **This used to be `pluck('id', 'kind')` over every system scale** — one
     * row per kind, which was correct only for as long as exactly one system
     * set existed. W77 seeds a second, at which point two rows share
     * `kind = 'impact'`, `pluck` keeps whichever the database returned last,
     * and every new assessment gets an arbitrary calibration with nothing
     * raised anywhere. W75 pinned that and confirmed it against a green
     * suite: no test failed while assessment creation was picking at random.
     * Selection is by **set**, and only by set.
     *
     * The set the person chose in the New assessment pop-up (W126), Standard
     * unless they chose another.
     *
     * @return array{impact: int, likelihood: int, strength: int}
     */
    private function systemScales(string $key): array
    {
        $scales = ScaleSets::resolve($key);

        if ($scales === null && $key !== ScaleSets::DEFAULT_KEY) {
            // A named scale that is not there is a request the form should
            // have refused (StoreAssessmentRequest); substituting Standard
            // would give the person a calibration they did not choose.
            throw new \InvalidArgumentException("No complete scale '{$key}' exists.");
        }

        if ($scales === null) {
            // Names the missing row rather than the missing set. "No complete
            // set exists" is true and useless to whoever has to fix it; the
            // state this actually fires in is a deployment that migrated and
            // never seeded, and a constraint violation would send them
            // looking in the wrong place entirely.
            $missing = ScaleSets::missingKinds(ScaleSets::DEFAULT_KEY)[0] ?? 'impact';

            throw new RuntimeException(
                "No system {$missing} scale exists in the '".ScaleSets::DEFAULT_KEY."' scale set, ".
                'so an assessment cannot be created. Run `php artisan db:seed --class=Database\\Seeders\\SystemScaleSeeder`; '.
                'a deployment does this from docker/entrypoint.sh.'
            );
        }

        return $scales;
    }
}
