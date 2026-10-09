<?php

namespace App\Domain\Snapshots;

/**
 * What a restore actually did (W61).
 *
 * Returned rather than logged, because every number here is something the
 * person who pressed the button needs to see afterwards: a restore is the most
 * destructive action in the application, and "restored" on its own does not
 * tell them whether it removed four nodes or forty.
 *
 * `unrestorableLevels`, `unlabelled` and `unassignable` are the three ways a
 * restore can be *incomplete* rather than wrong, and all three are surfaced on
 * the page instead of being swallowed. See SnapshotRestorer for what each one
 * means. `unlabelled` is always zero for a snapshot at schema v2 or above,
 * which is the version that started carrying labels (W62) — the field stays
 * because v1 snapshots do not stop existing when the format moves on.
 */
final readonly class RestoreOutcome
{
    public function __construct(
        public int $safetySnapshotId,
        public string $safetySnapshotName,
        public int $created,
        public int $updated,
        public int $deleted,
        public int $unrestorableLevels,
        public int $unlabelled,
        public int $unassignable = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'safetySnapshotId' => $this->safetySnapshotId,
            'safetySnapshotName' => $this->safetySnapshotName,
            'created' => $this->created,
            'updated' => $this->updated,
            'deleted' => $this->deleted,
            'unrestorableLevels' => $this->unrestorableLevels,
            'unlabelled' => $this->unlabelled,
            'unassignable' => $this->unassignable,
        ];
    }
}
