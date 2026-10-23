<?php

use App\Domain\Release\ReleaseInfo;

/*
 * W124 (D90): the label and link the sidebar shows. The value comes from the
 * image build, but it ends up in a URL, so what matters is the specified
 * output for each shape and that nothing else reaches the path.
 */

const RELEASE_LIST = 'https://github.com/danionita/arguesecure-onlinev2/releases';

test('a release tag links to its notes file at that tag in the public repository', function () {
    expect(ReleaseInfo::for('2.7.0'))->toBe([
        'label' => 'v2.7.0',
        'notesUrl' => 'https://github.com/danionita/arguesecure-onlinev2/blob/v2.7.0/docs/RELEASE-2.7.0.md',
    ]);
});

test('a pre-release shows its version and links to the releases list', function () {
    expect(ReleaseInfo::for('2.7.0-rc1'))->toBe(['label' => 'v2.7.0-rc1', 'notesUrl' => RELEASE_LIST]);
});

test('a manual image run shows edge and the short commit', function () {
    expect(ReleaseInfo::for('sha-0123456789abcdef'))
        ->toBe(['label' => 'edge · 0123456', 'notesUrl' => RELEASE_LIST]);
});

test('surrounding whitespace is ignored', function () {
    expect(ReleaseInfo::for("2.7.0\n")['label'])->toBe('v2.7.0');
});

test('an unset, empty or default value reads as dev', function (?string $value) {
    expect(ReleaseInfo::for($value))->toBe(['label' => 'dev', 'notesUrl' => RELEASE_LIST]);
})->with([null, '', 'dev', '   ']);

test('a value that is not a version never reaches a URL path', function (string $value) {
    expect(ReleaseInfo::for($value))->toBe(['label' => 'dev', 'notesUrl' => RELEASE_LIST]);
})->with([
    'v-prefixed' => 'v2.7.0',
    'traversal' => '2.7.0/../..',
    'space' => '2.7.0 x',
    'quote' => '2.7.0"onmouseover=1',
    'query' => '2.7.0?x=1',
    'two parts' => '2.7',
    'upper-case sha' => 'sha-ABCDEF1',
    'short sha' => 'sha-abc12',
    'prefixed sha' => 'xsha-abc1234',
    'newline then more' => "2.7.0\nx",
]);
