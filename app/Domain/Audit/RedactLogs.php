<?php

namespace App\Domain\Audit;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * A `tap` (config/logging.php) that puts RedactSensitiveData on a channel.
 * A tap rather than the `processors` key because Laravel reads `processors`
 * only for the `monolog` driver, and `single`, `daily`, `syslog` and
 * `errorlog` must be redacted too. A stack collects its channels'
 * processors, so tapping the leaves covers it.
 */
class RedactLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactSensitiveData);
        }
    }
}
