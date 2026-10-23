<?php

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityEventOccurred;
use App\Domain\Audit\SecurityLog;
use App\Models\User;

require_once __DIR__.'/Capture.php';

/*
 * W116 (MFA) and W117 (SSO) dispatch SecurityEventOccurred and write no log
 * line of their own (PLAN-2.7-identity.md); this is the list they build
 * against, and each case is checked end to end through the dispatcher. When
 * those packages land they add a test per flow that the right event is
 * dispatched; the recording is covered here.
 */
dataset('identity events', [
    'mfa.enabled' => [SecurityEvent::MfaEnabled, 'INFO'],
    'mfa.disabled' => [SecurityEvent::MfaDisabled, 'INFO'],
    'mfa.removed_by_admin' => [SecurityEvent::MfaRemovedByAdmin, 'INFO'],
    'mfa.recovery_code_used' => [SecurityEvent::MfaRecoveryCodeUsed, 'INFO'],
    'mfa.challenge_failed' => [SecurityEvent::MfaChallengeFailed, 'WARNING'],
    'sso.login' => [SecurityEvent::SsoLogin, 'INFO'],
    'sso.login_failed' => [SecurityEvent::SsoLoginFailed, 'WARNING'],
    'sso.identity_linked' => [SecurityEvent::SsoIdentityLinked, 'INFO'],
    'sso.identity_unlinked' => [SecurityEvent::SsoIdentityUnlinked, 'INFO'],
]);

test('a dispatched identity event is recorded with actor, target, address and level', function (SecurityEvent $event, string $level) {
    $actor = User::factory()->admin()->create();
    $subject = User::factory()->create();
    $log = captureSecurityLog();

    SecurityEventOccurred::dispatch($event, $actor, SecurityLog::user($subject), ['provider' => 'idp.example']);

    $line = securityLine($log, $event->value);
    expect($line['actor'])->toBe(['id' => $actor->id, 'email' => $actor->email])
        ->and($line['target'])->toBe(['type' => 'user', 'id' => $subject->id, 'email' => $subject->email])
        ->and($line['details'])->toBe(['provider' => 'idp.example'])
        ->and($line['level'])->toBe($level)
        ->and($line['ip'])->toBe('127.0.0.1');
})->with('identity events');

test('a secret passed in an event\'s details is redacted', function () {
    $log = captureSecurityLog();

    SecurityEventOccurred::dispatch(SecurityEvent::MfaRecoveryCodeUsed, null, null, ['recovery_code' => 'abcd-efgh', 'session_id' => 's3ss10n']);

    $details = securityLine($log, 'mfa.recovery_code_used')['details'];
    expect($details)->toBe(['recovery_code' => '[redacted]', 'session_id' => '[redacted]']);
});

test('event names are unique and none is a duplicate of another', function () {
    $values = array_map(fn (SecurityEvent $e) => $e->value, SecurityEvent::cases());

    expect($values)->toBe(array_values(array_unique($values)));
});
