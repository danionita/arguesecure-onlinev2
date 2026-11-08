<?php

/*
 * `checks.sh setup` points composer.lock at a scratch git repository for one
 * install in a cloud session and puts the lock back. A setup killed between
 * the two would leave a file:// source in the committed lock, which breaks
 * `composer install` for everyone else.
 */

function checksScript(): string
{
    return dirname(__DIR__, 3).'/tools/dev/checks/checks.sh';
}

/** Runs install_php_from_git against a copy of the lock, with git and composer stubbed. */
function runInstallFromGit(string $composerBody, string $signal = ''): array
{
    $dir = sys_get_temp_dir().'/lock-restore-'.bin2hex(random_bytes(4));
    mkdir($dir.'/bin', 0777, true);
    copy(dirname(__DIR__, 3).'/composer.lock', $dir.'/composer.lock');
    file_put_contents($dir.'/bin/git', "#!/bin/sh\nexit 0\n");
    file_put_contents($dir.'/bin/composer', "#!/bin/sh\n".$composerBody."\n");
    chmod($dir.'/bin/git', 0755);
    chmod($dir.'/bin/composer', 0755);

    $functions = [];
    preg_match('/^PHP_GIT_SCRATCH="".*?^install_php_from_git\(\) \{.*?^\}/ms', file_get_contents(checksScript()), $functions);
    file_put_contents($dir.'/run.sh', "set -uo pipefail\necho \$\$ > ".escapeshellarg($dir.'/pid')."\nROOT=".escapeshellarg($dir)."\n".$functions[0]."\ninstall_php_from_git\necho \"returned \$?\"\n");

    $output = shell_exec('PATH='.escapeshellarg($dir.'/bin').':$PATH bash '.escapeshellarg($dir.'/run.sh').' 2>&1');
    $lock = file_get_contents($dir.'/composer.lock');
    $original = file_get_contents(dirname(__DIR__, 3).'/composer.lock');
    shell_exec('rm -rf '.escapeshellarg($dir));

    return [(string) $output, $lock === $original];
}

test('the committed composer.lock has no file:// source', function () {
    expect(file_get_contents(dirname(__DIR__, 3).'/composer.lock'))->not->toContain('file://');
});

test('a failed install leaves composer.lock byte for byte as it was', function () {
    [$output, $restored] = runInstallFromGit('exit 1');

    expect($output)->toContain('returned 1')
        ->and($restored)->toBeTrue();
});

test('a setup killed by a signal during the install leaves composer.lock as it was', function () {
    // The stub composer terminates the script itself (run.sh records its pid),
    // as a timeout or Ctrl-C would.
    [$output, $restored] = runInstallFromGit('kill -TERM "$(cat "$(dirname "$0")/../pid")"; sleep 2');

    expect($output)->not->toContain('returned')
        ->and($restored)->toBeTrue();
})->skip(fn () => ! function_exists('shell_exec') || PHP_OS_FAMILY === 'Windows', 'needs a POSIX shell');
