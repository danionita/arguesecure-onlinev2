<?php

namespace App\Domain\Audit;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * A `tap` (config/logging.php) that stores what the `security` channel writes
 * (W128, D97). A handler on the channel rather than a second call in
 * SecurityLog, so a row is built from the record after RedactSensitiveData
 * and no caller can forget to.
 */
class StoreSecurityEvents
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            // After the stream handler, not before: pushHandler() would put
            // this one first, and the stream handler is the channel's
            // primary (tests read its formatter as `getHandlers()[0]`).
            $monolog->setHandlers([...$monolog->getHandlers(), new SecurityEventStoreHandler]);
        }
    }
}
