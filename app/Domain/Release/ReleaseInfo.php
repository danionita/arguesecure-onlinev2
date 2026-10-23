<?php

namespace App\Domain\Release;

/**
 * What the sidebar shows about the running build, and where its notes are
 * (W124, D90).
 *
 * Pure: a string in, two strings out. The input is `config('arguesecure.version')`,
 * which the image build sets from the release tag; it is operator-controlled
 * and still validated here, because the result becomes part of a URL.
 *
 *  - `2.7.0` → label `v2.7.0`, linked to that tag's release notes file
 *    (`docs/RELEASE-2.7.0.md`) in the public repository. The file exists once the tag does;
 *    a GitHub Release page would need a step someone can forget.
 *  - `2.7.0-rc1` → `v2.7.0-rc1`, linked to the releases list.
 *  - `sha-abc1234` (a manual image run) → `edge · abc1234`, linked to the
 *    releases list: a build that is not a release has no notes.
 *  - anything else, including empty and unset → `dev`, the releases list.
 *
 * A value that is not one of those shapes never reaches a URL path.
 */
final class ReleaseInfo
{
    public const REPOSITORY_URL = 'https://github.com/danionita/arguesecure-onlinev2';

    private const TAG = '/^\d+\.\d+\.\d+$/D';

    private const PRERELEASE = '/^\d+\.\d+\.\d+-[0-9A-Za-z.]+$/D';

    private const EDGE = '/^sha-([0-9a-f]{7,40})$/D';

    /**
     * @return array{label: string, notesUrl: string}
     */
    public static function for(?string $build): array
    {
        $build = trim((string) $build);
        $list = self::REPOSITORY_URL.'/releases';

        if (preg_match(self::TAG, $build) === 1) {
            return [
                'label' => "v{$build}",
                'notesUrl' => self::REPOSITORY_URL."/blob/v{$build}/docs/RELEASE-{$build}.md",
            ];
        }

        // A pre-release has a tag but no notes file of its own.
        if (preg_match(self::PRERELEASE, $build) === 1) {
            return ['label' => "v{$build}", 'notesUrl' => $list];
        }

        if (preg_match(self::EDGE, $build, $m) === 1) {
            return ['label' => 'edge · '.substr($m[1], 0, 7), 'notesUrl' => $list];
        }

        return ['label' => 'dev', 'notesUrl' => $list];
    }
}
