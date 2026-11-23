<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\AlertLabels;
use App\E2e\Client\AlertmanagerClient;
use App\E2e\Client\AlertRecorderClient;
use App\E2e\Client\ManagedAlert;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use LogicException;
use Webmozart\Assert\Assert;

/**
 * The recorder is shared by every run and never reset here: the ticket is delivered once, when the stack starts
 * (repeat_interval 1h). "New" notifications are those after the remembered count. Whether an alert fires now is
 * read from Alertmanager, not from the recorder.
 */
final class AlertingContext implements Context
{
    private const string ALERTNAME = 'SloErrorBudgetBurn';

    private const string APPLICATION = 'showcase';

    private readonly AlertmanagerClient $alertmanager;

    private readonly AlertRecorderClient $recorder;

    /** @var non-negative-int|null notifications the recorder held when the scenario remembered them */
    private ?int $rememberedNotifications = null;

    /** @var string what the last poll of a waiting step saw, for its timeout message */
    private string $lastPoll = '';

    public function __construct()
    {
        $this->alertmanager = new AlertmanagerClient();
        $this->recorder = new AlertRecorderClient();
    }

    #[Given('I remember the notifications the alert recorder got so far')]
    public function iRememberTheNotifications(): void
    {
        $this->rememberedNotifications = \count($this->recorder->notifications());
    }

    #[Then('within :seconds seconds the :receiver receiver got a firing :severity alert of :slo :objective')]
    public function theReceiverGotAFiringAlert(int $seconds, string $receiver, string $severity, string $slo, string $objective): void
    {
        $this->awaitNotification($seconds, $receiver, $severity, $slo, $objective, 0);
    }

    #[Then('within :seconds seconds the :receiver receiver got a new firing :severity alert of :slo :objective')]
    public function theReceiverGotANewFiringAlert(int $seconds, string $receiver, string $severity, string $slo, string $objective): void
    {
        $since = $this->rememberedNotifications ?? throw new LogicException('Remember the notifications of the alert recorder first.');
        $this->awaitNotification($seconds, $receiver, $severity, $slo, $objective, $since);
    }

    #[Given('within :seconds seconds Alertmanager has an uninhibited :severity alert of :slo :objective')]
    public function alertmanagerHasAnUninhibitedAlert(int $seconds, string $severity, string $slo, string $objective): void
    {
        Wait::until(
            function () use ($severity, $slo, $objective): bool {
                $alert = $this->managedAlert($severity, $slo, $objective);
                $this->lastPoll = $this->describe($alert);

                return $alert instanceof ManagedAlert && $alert->state === 'active';
            },
            $seconds,
            fn (): string => sprintf('Alertmanager has no active %s alert of %s %s: %s.', $severity, $slo, $objective, $this->lastPoll),
        );
    }

    #[Given('Alertmanager has no :severity alert of :slo :objective')]
    public function alertmanagerHasNoAlert(string $severity, string $slo, string $objective): void
    {
        $alert = $this->managedAlert($severity, $slo, $objective);
        Assert::null($alert, sprintf('A %s alert of %s %s is still in Alertmanager (%s); wait for it to resolve or run on a fresh stack.', $severity, $slo, $objective, $this->describe($alert)));
    }

    #[Then('within :seconds seconds Alertmanager shows the :target alert of :slo :objective inhibited by its :source alert')]
    public function alertmanagerShowsTheAlertInhibited(int $seconds, string $target, string $slo, string $objective, string $source): void
    {
        Wait::until(
            function () use ($target, $source, $slo, $objective): bool {
                $inhibitor = $this->managedAlert($source, $slo, $objective);
                $inhibited = $this->managedAlert($target, $slo, $objective);
                $this->lastPoll = sprintf('%s: %s, %s: %s', $source, $this->describe($inhibitor), $target, $this->describe($inhibited));

                return $inhibitor instanceof ManagedAlert && $inhibited instanceof ManagedAlert
                    && $inhibited->state === 'suppressed'
                    && \in_array($inhibitor->fingerprint, $inhibited->inhibitedBy, true);
            },
            $seconds,
            fn (): string => sprintf('The %s alert of %s %s is not inhibited by its %s alert: %s.', $target, $slo, $objective, $source, $this->lastPoll),
        );
    }

    /**
     * @param non-negative-int $since notifications before this position are skipped
     */
    private function awaitNotification(int $seconds, string $receiver, string $severity, string $slo, string $objective, int $since): void
    {
        $wanted = $this->labels($severity, $slo, $objective);
        Wait::until(
            function () use ($receiver, $wanted, $since): bool {
                $notifications = \array_slice($this->recorder->notifications(), $since);
                $this->lastPoll = sprintf('%d notifications', \count($notifications));
                foreach ($notifications as $notification) {
                    if ($notification->receiver !== $receiver) {
                        continue;
                    }

                    foreach ($notification->alerts as $alert) {
                        if ($alert->status === 'firing' && AlertLabels::contain($alert->labels, $wanted)) {
                            return true;
                        }
                    }
                }

                return false;
            },
            $seconds,
            fn (): string => sprintf('The %s receiver got no firing alert %s (%s).', $receiver, json_encode($wanted, JSON_THROW_ON_ERROR), $this->lastPoll),
        );
    }

    private function managedAlert(string $severity, string $slo, string $objective): ?ManagedAlert
    {
        $alerts = $this->alertmanager->alerts($this->labels($severity, $slo, $objective));
        Assert::maxCount($alerts, 1, sprintf('Alertmanager has %d %s alerts of %s %s, expected at most one.', \count($alerts), $severity, $slo, $objective));

        return $alerts[0] ?? null;
    }

    /**
     * @return non-empty-array<non-empty-string, string>
     */
    private function labels(string $severity, string $slo, string $objective): array
    {
        return ['alertname' => self::ALERTNAME, 'application' => self::APPLICATION, 'severity' => $severity, 'slo' => $slo, 'objective' => $objective];
    }

    private function describe(?ManagedAlert $alert): string
    {
        return $alert instanceof ManagedAlert ? $alert->state . ($alert->inhibitedBy === [] ? '' : ', inhibited by ' . implode(', ', $alert->inhibitedBy)) : 'none';
    }
}
