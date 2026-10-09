<?php

/*
 * Guards config/inertia.php against the defect it was written to fix: the
 * package default page path is `resource_path('js/pages')` (lowercase) while
 * this application's directory is `resources/js/Pages`.
 *
 * A plain is_dir()/file_exists() check cannot express this, because the
 * development machine here is case-insensitive — the wrong path resolves
 * perfectly locally and fails only on CI's Linux runner, which is exactly how
 * nine tests stayed red on every push from W12 onward without anything going
 * red on the machine they were written on. So these assertions compare against
 * the directory entry names the filesystem actually reports, which differ from
 * the requested string on every platform.
 */
test('every configured Inertia page path exists with exactly the case that is configured', function () {
    $paths = config('inertia.pages.paths');

    expect($paths)->toBeArray()->not->toBeEmpty();

    foreach ($paths as $path) {
        $parent = dirname($path);
        $name = basename($path);

        expect(is_dir($parent))->toBeTrue("Parent directory [{$parent}] does not exist.");

        // scandir() reports the name as stored on disk, so an entry that
        // matches only case-insensitively will not appear in this list.
        // toContain() treats every argument as a needle, so the explanation
        // goes in a preceding assertion rather than alongside the value.
        expect(in_array($name, scandir($parent), true))->toBeTrue(
            "Configured Inertia page path [{$path}] does not match the directory's real name on a case-sensitive filesystem."
        );
    }
});

test('the page component every Inertia assertion resolves through is findable by the view finder', function () {
    // The failure in CI surfaced through inertia.view-finder rather than
    // through the config directly, so assert against the finder itself: this
    // is the exact call AssertableInertia::component() makes.
    expect(fn () => app('inertia.view-finder')->find('Assessment/Show'))->not->toThrow(InvalidArgumentException::class);
});
