<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\DockerClient;
use App\E2e\Client\HealthClient;
use App\E2e\Client\HealthReport;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Step\Then;
use Behat\Step\When;
use LogicException;
use Webmozart\Assert\Assert;

final class HealthContext implements Context
{
    private readonly HealthClient $health;

    private readonly DockerClient $docker;

    private ?HealthReport $lastReport = null;

    /** @var list<string> services stopped by this scenario and not started again yet */
    private array $stopped = [];

    public function __construct()
    {
        $this->health = new HealthClient();
        $this->docker = new DockerClient();
    }

    #[When('I ask :service for readiness')]
    public function iAskForReadiness(string $service): void
    {
        $this->lastReport = $this->health->readiness($service);
    }

    #[Then('readiness is up')]
    public function readinessIsUp(): void
    {
        $report = $this->lastReport();
        Assert::true($report->isUp(), sprintf("Readiness is not up (HTTP %d):\n%s", $report->status, $report->raw));
    }

    #[Then('/^readiness mentions ((?:"[^"]+"(?:, )?)+)$/')]
    public function readinessMentions(string $quotedList): void
    {
        preg_match_all('/"([^"]+)"/', $quotedList, $matches);
        Assert::notEmpty($matches[1]);
        foreach ($matches[1] as $expected) {
            Assert::true($this->lastReport()->mentions($expected), sprintf('Readiness does not mention "%s".', $expected));
        }
    }

    #[Then('readiness has a line matching :pattern')]
    public function readinessHasALineMatching(string $pattern): void
    {
        Assert::stringNotEmpty($pattern);
        Assert::true($this->lastReport()->hasLineMatching($pattern), sprintf("Readiness has no line matching %s:\n%s", $pattern, $this->lastReport()->raw));
    }

    #[When('I stop :service')]
    public function iStop(string $service): void
    {
        $this->docker->stop($service);
        $this->stopped[] = $service;
    }

    #[When('I start :service')]
    public function iStart(string $service): void
    {
        $this->docker->start($service);
        $this->stopped = array_values(array_diff($this->stopped, [$service]));
    }

    // A failed chaos step must not leave the stand without Redis or orders for the next run.
    #[AfterScenario]
    public function restartWhatWasStopped(): void
    {
        foreach ($this->stopped as $service) {
            $this->docker->start($service);
        }

        $this->stopped = [];
    }

    #[Then('within :seconds seconds :service readiness is down')]
    public function withinReadinessIsDown(int $seconds, string $service): void
    {
        Wait::until(
            fn (): bool => $this->health->readiness($service)->isDown(),
            $seconds,
            sprintf('"%s" readiness did not go down.', $service),
        );
    }

    #[Then('within :seconds seconds :service readiness is up')]
    public function withinReadinessIsUp(int $seconds, string $service): void
    {
        Wait::until(
            fn (): bool => $this->health->readiness($service)->isUp(),
            $seconds,
            sprintf('"%s" readiness did not come back up.', $service),
        );
    }

    #[Then('within :seconds seconds :service readiness has a line matching :pattern')]
    public function withinReadinessHasALineMatching(int $seconds, string $service, string $pattern): void
    {
        Assert::stringNotEmpty($pattern);
        Wait::until(
            fn (): bool => $this->health->readiness($service)->hasLineMatching($pattern),
            $seconds,
            sprintf('"%s" readiness has no line matching %s.', $service, $pattern),
        );
    }

    #[Then('within :seconds seconds :service readiness is up with a warning matching :pattern')]
    public function withinReadinessIsUpWithAWarningMatching(int $seconds, string $service, string $pattern): void
    {
        Assert::stringNotEmpty($pattern);
        Wait::until(
            function () use ($service, $pattern): bool {
                $report = $this->health->readiness($service);

                return $report->isUp() && $report->warnsMatching($pattern);
            },
            $seconds,
            sprintf('"%s" readiness was not up with a warning matching %s.', $service, $pattern),
        );
    }

    #[Then(':service liveliness is up')]
    public function livelinessIsUp(string $service): void
    {
        $report = $this->health->liveliness($service);
        Assert::true($report->isUp(), sprintf("\"%s\" liveliness is not up (HTTP %d):\n%s", $service, $report->status, $report->raw));
    }

    private function lastReport(): HealthReport
    {
        return $this->lastReport ?? throw new LogicException('Ask a service for readiness first.');
    }
}
