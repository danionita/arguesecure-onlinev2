<?php

namespace App\Domain\Tags;

use App\Domain\History\RevisionRecorder;
use App\Models\Assessment;
use App\Models\Node;
use App\Models\Tag;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Labels (W43, item 18; schema.md §3.11).
 *
 * The `tags` and `node_tag` tables have existed since W1 with nothing above
 * them: no controller, no store field, and `useFilters.ts` carrying a
 * documented "tag gap" where the filter it already implements had nothing to
 * filter. This is what fills it.
 *
 * ## Create on first use
 *
 * There is no separate "manage the tag vocabulary" screen and deliberately
 * so. A facilitator mid-workshop types "PCI" on a node and means it; asking
 * them to go and define PCI first, in another place, is the kind of step
 * that ends with the label not being applied at all. So naming a tag creates
 * it, and naming one that already exists reuses it.
 *
 * "Already exists" is decided by slug, not by the name as typed: "PCI DSS",
 * "pci dss" and "PCI  DSS" are one label, because a filter that offers all
 * three separately is worse than useless — it silently splits the very
 * grouping the user was building. The first spelling wins the display name,
 * which is why `resolve()` never renames an existing tag.
 */
class TagService
{
    /**
     * The column is `string(60)`. Enforced here as well as in the request so
     * the service is safe to call from a seeder or a console command, where
     * no FormRequest has run.
     */
    public const MAX_NAME_LENGTH = 60;

    public function __construct(private readonly RevisionRecorder $revisions) {}

    /**
     * Attaches a label to a node, creating it for this assessment if this is
     * the first time anyone has used it.
     */
    public function attach(Node $node, string $name): Tag
    {
        return DB::transaction(function () use ($node, $name): Tag {
            $tag = $this->resolve($node->assessment, $name);

            // syncWithoutDetaching, not attach(): `node_tag` has a composite
            // primary key, so attaching a tag the node already carries is a
            // duplicate-key error rather than a no-op. Applying a label
            // twice is something a user does by accident — two people in the
            // same workshop, or one impatient double-click — and it should
            // mean "it is labelled", not an error.
            $already = $node->tags()->where('tags.id', $tag->id)->exists();

            $node->tags()->syncWithoutDetaching([$tag->id]);

            // W56, closing the second of W48's history gaps. Recorded here
            // rather than in TagController so it holds whatever calls this —
            // a console command or a future bulk-label action would otherwise
            // reopen the gap silently. Only on a genuine change: the
            // double-click this method already tolerates should not also
            // produce two identical history entries.
            if (! $already) {
                $this->revisions->recordFieldChange($node, 'label', null, $tag->name, Auth::user());
            }

            return $tag;
        });
    }

    /**
     * Removes a label from a node. The tag itself survives: it may be on
     * twenty other nodes, and even if it is not, it stays in the
     * assessment's vocabulary where the autocomplete can offer it again.
     */
    public function detach(Node $node, Tag $tag): void
    {
        // detach() returns the number of rows removed, so a label that was
        // not on this node writes no revision without a second query.
        if ($node->tags()->detach($tag->id) > 0) {
            $this->revisions->recordFieldChange($node, 'label', $tag->name, null, Auth::user());
        }
    }

    /**
     * The existing tag with this slug in this assessment, or a new one.
     *
     * Assessment-scoped, which is the deliberate change from the legacy
     * global table (schema.md §3.11): one user's rename used to reach
     * everyone else's assessments.
     */
    public function resolve(Assessment $assessment, string $name): Tag
    {
        $name = $this->normaliseName($name);
        $slug = $this->slug($name);

        $existing = Tag::query()
            ->where('assessment_id', $assessment->id)
            ->where('slug', $slug)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // Direct property assignment, not ::create(): `assessment_id` is an
        // identity column and is deliberately absent from Tag::$fillable, so
        // a mass-assigned create() drops it silently at the model layer and
        // then dies on the NOT NULL constraint (CLAUDE.md). AssessmentCloner
        // documents the same reasoning at the point it was learned.
        $tag = new Tag(['name' => $name, 'slug' => $slug]);
        $tag->assessment_id = $assessment->id;
        $tag->save();

        return $tag;
    }

    private function normaliseName(string $name): string
    {
        // Collapse internal runs of whitespace as well as trimming the ends:
        // "PCI  DSS" and "PCI DSS" are the same label to a reader, and the
        // slug would already treat them as one, so the display name should
        // not preserve a difference the rest of the system has discarded.
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return Str::limit($name, self::MAX_NAME_LENGTH, '');
    }

    /**
     * `Str::slug` transliterates, which is exactly right for "PCI DSS" and
     * returns the **empty string** for a name with no latin characters at
     * all — "セキュリティ", "危険". An empty slug would collide with every
     * other such name in the assessment through the unique index, quietly
     * merging two unrelated labels into one. The lowercased name is the
     * fallback: it still makes case and spacing variants one tag, which is
     * the whole job of the slug here, and it fits the same 60-char column
     * because the name has already been limited to it.
     */
    private function slug(string $name): string
    {
        $slug = Str::slug($name);

        // Limited again rather than trusting the name's limit: transliteration
        // can lengthen a string — "ß" becomes "ss" — so a name that fits the
        // 60-char column can produce a slug that does not, and the column is
        // NOT NULL varchar(60) under STRICT_TRANS_TABLES.
        return Str::limit($slug !== '' ? $slug : Str::lower($name), 60, '');
    }
}
