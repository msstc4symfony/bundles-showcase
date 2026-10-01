<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\GatewayResponse;
use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use Behat\Step\When;
use Webmozart\Assert\Assert;

final class IdempotencyContext implements Context
{
    private OrderContext $orders;

    /** @var list<GatewayResponse> */
    private array $responses = [];

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $this->orders = OrderContext::from($scope);
    }

    #[When('I create an order for :amount with idempotency key :key')]
    public function iCreateAnOrderWithIdempotencyKey(int $amount, string $key): void
    {
        $this->responses[] = $this->orders->gateway->createOrder($amount, RunScoped::id($key));
    }

    #[When('I send :count concurrent order requests for :amount with idempotency key :key')]
    public function iSendConcurrentOrderRequests(int $count, int $amount, string $key): void
    {
        Assert::positiveInteger($count);
        $this->responses = $this->orders->gateway->createOrdersConcurrently($count, $amount, RunScoped::id($key));
    }

    #[Then('both responses carry the same order id')]
    #[Then('all responses carry the same order id')]
    public function allResponsesCarryTheSameOrderId(): void
    {
        Assert::minCount($this->responses, 2);
        $ids = [];
        foreach ($this->responses as $response) {
            Assert::same($response->status, 201, 'Every duplicate must answer like the original creation.');
            $ids[] = $response->field('id');
        }

        Assert::count(array_unique($ids), 1, sprintf('Expected one order, got %s.', implode(', ', array_unique($ids))));
    }
}
