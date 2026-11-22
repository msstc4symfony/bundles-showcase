<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\GatewayResponse;
use LogicException;

/**
 * State shared by the step definitions of one scenario.
 */
final class Scenario
{
    public ?GatewayResponse $lastResponse = null;

    /** @var non-empty-string|null */
    public ?string $lastOrderId = null;

    /** @var list<GatewayResponse> every response of the last "I create N orders" step, its last one is the response the "last response" steps read */
    public array $batch = [];

    public function lastResponse(): GatewayResponse
    {
        return $this->lastResponse ?? throw new LogicException('No request has been sent yet in this scenario.');
    }

    /**
     * @return non-empty-string
     */
    public function lastOrderId(): string
    {
        return $this->lastOrderId ?? throw new LogicException('No order has been created yet in this scenario.');
    }
}
