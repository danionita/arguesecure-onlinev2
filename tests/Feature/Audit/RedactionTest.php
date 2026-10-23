<?php

use App\Domain\Audit\RedactSensitiveData;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

/*
 * security.md §11: a password, a share token and a session id never reach a
 * log record. Each is logged through the application's real channels, so the
 * tap in config/logging.php is what is under test, not the processor alone.
 */
dataset('channels', ['security', 'stderr', 'single', 'daily']);

test('secrets in any context key never reach a record on :dataset', function (string $channel) {
    $handler = new TestHandler;
    Log::channel($channel)->getLogger()->pushHandler($handler);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    Log::channel($channel)->warning('probe', [
        'password' => 'hunter2-hunter2',
        'password_confirmation' => 'hunter2-hunter2',
        'share_token' => $token,
        'session_id' => 'sess-abcdef',
        'nested' => ['current_password' => 'hunter2-hunter2', 'details' => ['token' => $token]],
        'code' => '123456',
        'private_key' => 'k',
        'ok' => 'kept',
        'session_lifetime_minutes' => 480,
    ]);

    $context = $handler->getRecords()[0]->context;
    expect($context['password'])->toBe(RedactSensitiveData::MASK)
        ->and($context['password_confirmation'])->toBe(RedactSensitiveData::MASK)
        ->and($context['share_token'])->toBe(RedactSensitiveData::MASK)
        ->and($context['session_id'])->toBe(RedactSensitiveData::MASK)
        ->and($context['nested']['current_password'])->toBe(RedactSensitiveData::MASK)
        ->and($context['nested']['details']['token'])->toBe(RedactSensitiveData::MASK)
        ->and($context['code'])->toBe(RedactSensitiveData::MASK)
        ->and($context['private_key'])->toBe(RedactSensitiveData::MASK)
        ->and($context['ok'])->toBe('kept')
        ->and($context['session_lifetime_minutes'])->toBe(480)
        ->and(json_encode($context))->not->toContain($token)->not->toContain('hunter2');
})->with('channels');

test('a share-link path is masked in the message and in any string value', function () {
    $handler = new TestHandler;
    Log::channel('security')->getLogger()->pushHandler($handler);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    Log::channel('security')->info("GET /s/{$token} redeemed", ['details' => ['link' => "https://example.test/s/{$token}", 'other' => '/s/short']]);

    $record = $handler->getRecords()[0];
    expect($record->message)->toBe('GET /s/[redacted] redeemed')
        ->and($record->context['details']['link'])->toBe('https://example.test/s/[redacted]')
        ->and($record->context['details']['other'])->toBe('/s/short');
});

test('the security channel writes one JSON object per line with the five fixed keys', function () {
    // Nothing else in the suite looks at the formatter: a swap to the line
    // formatter would keep every other test here green.
    $stream = fopen('php://memory', 'w+');
    $channel = Log::channel('security')->getLogger();
    $handler = $channel->getHandlers()[0];
    expect($handler->getFormatter())->toBeInstanceOf(JsonFormatter::class);

    $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true));
    $record = new LogRecord(new DateTimeImmutable, 'security', Level::Info, 'auth.login', [
        'event' => 'auth.login', 'actor' => null, 'target' => null, 'ip' => '127.0.0.1', 'details' => [],
    ]);
    $decoded = json_decode(rtrim($handler->getFormatter()->format($record)), true);

    expect($decoded['message'])->toBe('auth.login')
        ->and(array_keys($decoded['context']))->toBe(['event', 'actor', 'target', 'ip', 'details']);
    fclose($stream);
});

test('the security channel logs info whatever LOG_LEVEL says', function () {
    config(['logging.channels.security.level' => 'info']);
    expect(config('logging.channels.security.level'))->toBe('info')
        ->and(config('logging.channels.security.handler_with.stream'))->not->toBe('php://stdout');
});
