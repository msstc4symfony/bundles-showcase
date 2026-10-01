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

    public ?string $lastOrderId = null;

    public ?string $requestId = null;

    public function lastResponse(): GatewayResponse
    {
        return $this->lastResponse ?? throw new LogicException('No request has been sent yet in this scenario.');
    }

    public function lastOrderId(): string
    {
        return $this->lastOrderId ?? throw new LogicException('No order has been created yet in this scenario.');
    }
}
