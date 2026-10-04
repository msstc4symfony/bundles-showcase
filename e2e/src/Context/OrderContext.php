<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\GatewayClient;
use App\E2e\Client\GatewayResponse;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Step\Then;
use Behat\Step\When;
use Webmozart\Assert\Assert;

final readonly class OrderContext implements Context
{
    public Scenario $scenario;

    public GatewayClient $gateway;

    public function __construct()
    {
        $this->scenario = new Scenario();
        $this->gateway = new GatewayClient();
    }

    public static function from(BeforeScenarioScope $scope): self
    {
        $environment = $scope->getEnvironment();
        Assert::isInstanceOf($environment, InitializedContextEnvironment::class);

        return $environment->getContext(self::class);
    }

    public function recordCreation(GatewayResponse $response): void
    {
        $this->scenario->lastResponse = $response;
        if ($response->status === 201) {
            $this->scenario->lastOrderId = $response->field('id');
        }
    }

    #[When('I create an order for :amount')]
    public function iCreateAnOrderFor(int $amount): void
    {
        $this->recordCreation($this->gateway->createOrder($amount));
    }

    #[When('I look up the order :id')]
    public function iLookUpTheOrder(string $id): void
    {
        $this->scenario->lastResponse = $this->gateway->showOrder($id);
    }

    #[Then('the response status is :status')]
    public function theResponseStatusIs(int $status): void
    {
        Assert::same($this->scenario->lastResponse()->status, $status);
    }

    #[Then('the order becomes :status within :seconds seconds')]
    public function theOrderBecomesWithin(string $status, int $seconds): void
    {
        $id = $this->scenario->lastOrderId();

        Wait::until(
            fn (): bool => ($this->gateway->showOrder($id)->body['status'] ?? null) === $status,
            $seconds,
            sprintf('Order %s did not become "%s" within %d seconds.', $id, $status, $seconds),
        );
    }
}
