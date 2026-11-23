<?php

declare(strict_types=1);

namespace App\E2e\Client;

/**
 * One webhook call Alertmanager made to the alert recorder.
 */
final readonly class RecordedNotification
{
    /**
     * @param list<NotifiedAlert> $alerts
     */
    public function __construct(
        public string $receiver,
        public array $alerts,
    ) {
    }
}
