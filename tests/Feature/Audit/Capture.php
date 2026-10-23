<?php

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

/**
 * Records what the `security` channel writes, after its processors have run,
 * so a test sees exactly what would reach stderr. The app (and so the
 * channel) is rebuilt for every test, so nothing leaks between them.
 */
function captureSecurityLog(): TestHandler
{
    $handler = new TestHandler;
    Log::channel('security')->getLogger()->pushHandler($handler);

    return $handler;
}

/**
 * The records' contexts, in order, each with the record's level name.
 *
 * @return list<array<string, mixed>>
 */
function securityLines(TestHandler $handler): array
{
    return array_map(
        fn ($record) => [...$record->context, 'level' => $record->level->getName()],
        $handler->getRecords(),
    );
}

/**
 * The single line for an event, failing if there is none or several.
 *
 * @return array<string, mixed>
 */
function securityLine(TestHandler $handler, string $event): array
{
    $lines = array_values(array_filter(securityLines($handler), fn (array $line) => $line['event'] === $event));

    expect($lines)->toHaveCount(1, "expected exactly one '{$event}' line, got: ".json_encode(array_column(securityLines($handler), 'event')));

    return $lines[0];
}
