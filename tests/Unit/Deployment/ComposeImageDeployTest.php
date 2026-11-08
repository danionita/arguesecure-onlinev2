<?php

use Symfony\Component\Yaml\Yaml;

/*
 * Published images (deployment.md §2.4): a deployment is docker-compose.yml plus a
 * .env, pulling published images, with no checkout beside it. The image
 * workflow proves that by booting it on every release; these assertions keep
 * the compose file from drifting back into needing a clone between releases,
 * which nothing else would notice until the next tag.
 */

function composeFile(): array
{
    return Yaml::parseFile(dirname(__DIR__, 3).'/docker-compose.yml');
}

const ARGUESECURE_IMAGE = '${ARGUESECURE_IMAGE:-ghcr.io/danionita/arguesecure}:${ARGUESECURE_VERSION:-latest}';
const ASSISTANT_IMAGE = '${ARGUESECURE_IMAGE:-ghcr.io/danionita/arguesecure}-assistant:${ARGUESECURE_VERSION:-latest}';

test('the four PHP services run the one published application image', function () {
    $services = composeFile()['services'];

    foreach (['app', 'reverb', 'queue', 'scheduler'] as $name) {
        expect($services[$name]['image'] ?? null)->toBe(ARGUESECURE_IMAGE, $name);
    }
});

test('the assistant and its installer run the published assistant image', function () {
    $services = composeFile()['services'];

    expect($services['assistant']['image'] ?? null)->toBe(ASSISTANT_IMAGE)
        ->and($services['assistant-install']['image'] ?? null)->toBe(ASSISTANT_IMAGE);
});

test('every service that can be built from source also names an image to pull', function () {
    foreach (composeFile()['services'] as $name => $service) {
        if (array_key_exists('build', $service)) {
            expect($service)->toHaveKey('image', message: "{$name} builds but has no image to pull");
        }
    }
});

test('no service bind-mounts a path from the checkout', function () {
    // Every host path must come from a variable (DATA_PATH, ASSISTANT_MODEL_PATH)
    // that a deployment sets. A literal ./docker/... mount is what tied the
    // assistant to a clone before images were published, and on a deployment without one
    // Docker creates the directory empty and hides the image's own files.
    foreach (composeFile()['services'] as $name => $service) {
        foreach ($service['volumes'] ?? [] as $volume) {
            $host = explode(':', (string) $volume, 2)[0];

            expect($host)->toStartWith('${', "{$name} mounts {$volume}");
        }
    }
});

test('the assistant image is built on a digest-pinned llama.cpp', function () {
    // Pinning by digest means "which build is this instance running" is
    // answered by looking.
    $dockerfile = file_get_contents(dirname(__DIR__, 3).'/docker/assistant/Dockerfile');

    expect($dockerfile)->toMatch('/^FROM ghcr\.io\/ggml-org\/llama\.cpp:server-b\d+@sha256:[0-9a-f]{64}$/m')
        ->and($dockerfile)->toContain('COPY assistant.sh models.conf /opt/arguesecure/');
});

test('the deploy-only file is the full file minus everything that builds', function () {
    $root = dirname(__DIR__, 3);
    $full = composeFile();
    $deploy = Yaml::parseFile($root.'/docker-compose.deploy.yml');

    // Nothing in it can build from a checkout, and nothing declares a
    // build-time secret.
    expect($deploy)->not->toHaveKey('secrets');
    foreach ($deploy['services'] as $name => $service) {
        expect($service)->not->toHaveKey('build', "{$name} would build from source");
    }

    // Everything else is identical, so the two cannot drift: same top-level
    // keys, same services, same values. Key order is not part of the contract.
    $expectedTop = $full;
    unset($expectedTop['secrets']);
    expect(array_keys($deploy))->toEqualCanonicalizing(array_keys($expectedTop))
        ->and(array_keys($deploy['services']))->toEqualCanonicalizing(array_keys($full['services']));

    foreach ($full['services'] as $name => $service) {
        unset($service['build']);

        expect($deploy['services'][$name])->toEqual($service, $name);
    }
    expect($deploy['networks'])->toEqual($full['networks'])
        ->and($deploy['x-arguesecure-image'])->toEqual($full['x-arguesecure-image']);
});
