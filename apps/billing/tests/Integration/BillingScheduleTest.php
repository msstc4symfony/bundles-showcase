<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Scheduler\ReconcilePayments;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Symfony\Component\Scheduler\Schedule;

final class BillingScheduleTest extends KernelTestCase
{
    public function testTheBillingScheduleRunsTheReconciliationEveryFifteenSeconds(): void
    {
        $schedule = self::getContainer()->get('scheduler.provider.billing');
        self::assertInstanceOf(Schedule::class, $schedule);

        $recurring = $schedule->getRecurringMessages();
        self::assertCount(1, $recurring);
        $trigger = $recurring[0]->getTrigger();
        self::assertSame('every 15 seconds', (string) $trigger);

        $context = new MessageContext('billing', $recurring[0]->getId(), $trigger, new DateTimeImmutable());
        $messages = [...$recurring[0]->getMessages($context)];
        self::assertCount(1, $messages);
        self::assertInstanceOf(ServiceCallMessage::class, $messages[0]);
        self::assertSame(ReconcilePayments::class, $messages[0]->getServiceId());
        self::assertSame('__invoke', $messages[0]->getMethod());
    }
}
