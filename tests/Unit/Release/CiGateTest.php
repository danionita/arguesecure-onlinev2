<?php

use Symfony\Component\Process\Process;

/*
 * The Release workflow tags a commit only when tools/release/ci-gate.sh says
 * that commit's newest CI run finished green. The script is the whole
 * decision, so each case is held to the specified exit code and message.
 */

const GATE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

function ciGate(string $fixture): array
{
    // Unit tests boot no application: no facades, no base_path().
    $root = dirname(__DIR__, 3);
    if (trim((string) shell_exec('command -v jq')) === '') {
        throw new RuntimeException('tools/release/ci-gate.sh needs jq on PATH; install it (apt install jq, or the checks image).');
    }
    $process = new Process(['bash', 'tools/release/ci-gate.sh', GATE_SHA], $root);
    $process->setInput(file_get_contents("{$root}/tests/fixtures/ci-gate/{$fixture}.json"));
    $process->run();

    return [$process->getExitCode(), trim($process->getOutput().$process->getErrorOutput())];
}

test('a green finished run lets the release through and names the run', function (string $fixture) {
    [$code, $message] = ciGate($fixture);

    expect($code)->toBe(0)
        ->and($message)->toBe('CI is green for '.GATE_SHA.' (https://example.test/run/4).');
})->with(['success', 'rerun-green', 'pull-request-run-ignored']);

test('no run for the commit refuses, even when another commit is green', function (string $fixture) {
    [$code, $message] = ciGate($fixture);

    expect($code)->toBe(1)->and($message)->toStartWith('No CI run found for '.GATE_SHA);
})->with(['none', 'other-commit-only']);

test('a run still going refuses, even when an older run was green', function (string $fixture, string $run) {
    [$code, $message] = ciGate($fixture);

    expect($code)->toBe(1)
        ->and($message)->toBe('CI is still in_progress for '.GATE_SHA." (https://example.test/run/{$run}). Wait for it to finish, then run Release again.");
})->with([['in-progress', '4'], ['newer-running', '5']]);

test('a failed or cancelled run refuses, and a newer failure beats an older success', function (string $fixture, string $conclusion, string $run) {
    [$code, $message] = ciGate($fixture);

    expect($code)->toBe(1)
        ->and($message)->toBe("CI concluded '{$conclusion}' for ".GATE_SHA." (https://example.test/run/{$run}). A release needs a green run.");
})->with([['failure', 'failure', '4'], ['cancelled', 'cancelled', '4'], ['newer-failure', 'failure', '5']]);
