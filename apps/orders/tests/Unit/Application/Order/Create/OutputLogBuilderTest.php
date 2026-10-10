<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Order\Create;

use App\Application\Order\Create\Output;
use App\Application\Order\Create\OutputLogBuilder;
use App\Order\OrderView;
use Msstc4Symfony\LogicBundle\Application\Action\OutputInterface;
use PHPUnit\Framework\TestCase;

final class OutputLogBuilderTest extends TestCase
{
    public function testLogsTheCreatedOrder(): void
    {
        $builder = new OutputLogBuilder();
        $output = new Output(new OrderView('0190e0f6-0000-7000-8000-000000000001', 'pending', 150));

        self::assertTrue($builder->supports($output));
        self::assertSame(
            ['order_id' => '0190e0f6-0000-7000-8000-000000000001', 'status' => 'pending', 'amount' => 150],
            $builder->build($output),
        );
    }

    public function testIgnoresOtherOutputs(): void
    {
        self::assertFalse(new OutputLogBuilder()->supports(new class implements OutputInterface {}));
    }
}
