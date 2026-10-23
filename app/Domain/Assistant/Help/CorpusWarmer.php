<?php

namespace App\Domain\Assistant\Help;

use App\Domain\Assistant\Availability;
use App\Domain\Assistant\Client;

/**
 * Reads the help corpus into a freshly started runtime, once
 * (PLAN-2.6.md D57).
 *
 * ## Why
 *
 * Job A sends the whole help corpus as a fixed prefix (D21), so every question
 * after the first reuses it from the runtime's cache — 6.5–17s instead of
 * 76–136s on the deployment host (docs/spikes/2.6-models.md). But the cache
 * lives in the runtime's memory, and a restart empties it: the first person to
 * ask afterwards pays the whole cost, with a spinner that looks like a hang.
 * Saving the cache to disk across the restart was measured and does not work
 * for either preset. Paying it at start-up, before anyone asks, does.
 *
 * ## Once per start, and how that is known
 *
 * llama.cpp's `/slots` lists each slot, and a slot that has never served a
 * request has no `id_task` (checked against the pinned build). So "freshly
 * started" is read off the runtime directly, not inferred from a timer or a
 * counter this class would have to keep. After the warm-up request the slot
 * has an `id_task`, and this does nothing again until the next restart.
 *
 * Other jobs do not undo it: the runtime keeps the corpus in its host-RAM
 * prompt cache when a suggestion or a summary takes the slot, and restores it
 * for the next question (measured; `--cache-ram`, D56).
 *
 * **Known gap, accepted:** if someone asks for a suggestion or a summary
 * before the first scheduler tick after a start, the slot has an `id_task`
 * and this never warms the corpus for that start — the next help question is
 * the slow one, as it was before 2.6. The runtime reports nothing that would
 * tell "has the help corpus been read since start" apart from "has anything
 * run since start", and guessing wrong in the other direction would read the
 * corpus on every tick. A known limit of this approach.
 */
class CorpusWarmer
{
    public const UNAVAILABLE = 'unavailable';

    public const ALREADY_USED = 'already used';

    public const BUSY = 'busy';

    public const UNKNOWN = 'unknown';

    public const WARMED = 'warmed';

    public function __construct(
        private readonly Availability $availability,
        private readonly HelpAnswerer $answerer,
        private readonly Client $client,
    ) {}

    /** One of the constants above, for the scheduler's log. */
    public function warmIfFresh(): string
    {
        // Every condition a real help question would face, feature switch
        // included: warming the corpus for a feature an admin switched off is
        // a minute and a half of CPU for nobody.
        if (! $this->availability->isAvailable(Availability::FEATURE_HELP)) {
            return self::UNAVAILABLE;
        }

        $slots = $this->slots();

        if ($slots === null) {
            // A runtime started with --no-slots, or one that did not answer.
            // Guessing "fresh" here would warm on every tick, forever.
            return self::UNKNOWN;
        }

        foreach ($slots as $slot) {
            if (($slot['is_processing'] ?? false) === true) {
                // Someone is already using it, and a queued warm-up would
                // only make them wait behind it.
                return self::BUSY;
            }

            if (array_key_exists('id_task', $slot)) {
                return self::ALREADY_USED;
            }
        }

        $this->answerer->warm();

        return self::WARMED;
    }

    /** @return list<array<string, mixed>>|null */
    private function slots(): ?array
    {
        $response = $this->client->probe('/slots');
        $slots = $response?->successful() ? $response->json() : null;

        return is_array($slots) && array_is_list($slots) && $slots !== [] ? $slots : null;
    }
}
