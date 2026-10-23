<?php

/*
 * W124 (D90): the sidebar's version is the release tag, carried into the
 * image by a build argument. If any link of that chain is cut, a release
 * image silently says `dev`, which no other test would notice.
 */

function buildRoot(string $path): string
{
    return file_get_contents(dirname(__DIR__, 3).'/'.$path);
}

test('the Dockerfile accepts the build version and exposes it to the app, in the runtime stage', function () {
    $stage = preg_split('/^FROM \S+ AS runtime$/m', buildRoot('Dockerfile'))[1] ?? '';

    expect($stage)->toMatch('/^ARG ARGUESECURE_BUILD_VERSION=$/m')
        ->and($stage)->toMatch('/^ENV ARGUESECURE_BUILD_VERSION=\$ARGUESECURE_BUILD_VERSION$/m');
});

test('the image workflow passes the tag-derived version to the application build', function () {
    $workflow = buildRoot('.github/workflows/image.yml');

    expect($workflow)->toContain('--build-arg "ARGUESECURE_BUILD_VERSION=$VERSION"');
    // ...on the application image's build, not only somewhere in the file.
    expect($workflow)->toMatch('/--build-arg "ARGUESECURE_BUILD_VERSION=\$VERSION"(?:[^\n]*\n){1,3}[^\n]*-t "\$IMAGE:\$VERSION" \./');
});

test('the config reads the build version, then the VERSION file, then dev', function () {
    $config = buildRoot('config/arguesecure.php');

    expect($config)->toContain("env('ARGUESECURE_BUILD_VERSION')")
        ->and($config)->toContain("base_path('VERSION')")
        ->and($config)->toContain(": 'dev'");
});

test('VERSION names a release whose notes file exists', function () {
    // The sidebar links to docs/RELEASE-<version>.md at that tag (W124). A
    // VERSION with no notes file is a link that 404s.
    $version = trim(buildRoot('VERSION'));

    expect($version)->toMatch('/^\d+\.\d+\.\d+$/')
        ->and(file_exists(dirname(__DIR__, 3)."/docs/RELEASE-{$version}.md"))->toBeTrue();
});
