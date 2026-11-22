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
            $id = $response->field('id');
            Assert::stringNotEmpty($id);
            $this->scenario->lastOrderId = $id;
        }
    }

    #[When('I create an order for :amount')]
    public function iCreateAnOrderFor(int $amount): void
    {
        $this->recordCreation($this->gateway->createOrder($amount));
    }

    /**
     * One after another; "the response status is" then checks every one of them.
     */
    #[When('I create :count orders for :amount')]
    public function iCreateOrdersFor(int $count, int $amount): void
    {
        Assert::positiveInteger($count);
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $response = $this->gateway->createOrder($amount);
            $this->recordCreation($response);
            $batch[] = $response;
        }

        $this->scenario->batch = $batch;
    }

    #[When('I look up the order :id')]
    public function iLookUpTheOrder(string $id): void
    {
        Assert::stringNotEmpty($id);
        $this->scenario->lastResponse = $this->gateway->showOrder($id);
    }

    #[When('I send GET :path to the gateway')]
    public function iSendGetToTheGateway(string $path): void
    {
        Assert::stringNotEmpty($path);
        $this->scenario->lastResponse = $this->gateway->get($path);
    }

    #[Then('the response status is :status')]
    public function theResponseStatusIs(int $status): void
    {
        $last = $this->scenario->lastResponse();
        $batch = $this->scenario->batch;
        if ($batch !== [] && $batch[array_key_last($batch)] === $last) {
            Assert::same(
                array_map(static fn (GatewayResponse $response): int => $response->status, $batch),
                array_fill(0, \count($batch), $status),
                'Not every created order got the status.',
            );

            return;
        }

        Assert::same($last->status, $status);
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
