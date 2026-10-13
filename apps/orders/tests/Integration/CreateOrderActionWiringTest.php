<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Order\Create\Action;
use App\Controller\CreateOrderController;
use Msstc4Symfony\LogicBundle\Application\Logging\LoggingMiddleware;
use Msstc4Symfony\LogicBundle\Application\Pipeline\ActionPipeline;
use Msstc4Symfony\MetricsBridgeLogic\Infrastructure\Metrics\MetricsMiddleware;
use Msstc4Symfony\ProfilingBridgeLogic\Infrastructure\Profiling\ProfilingMiddleware;
use Msstc4Symfony\TracingBridgeLogic\Infrastructure\Tracing\TracingMiddleware;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateOrderActionWiringTest extends KernelTestCase
{
    public function testTheActionServiceIsTheLogicPipeline(): void
    {
        self::assertInstanceOf(ActionPipeline::class, self::getContainer()->get(Action::class));
    }

    public function testTheControllerCallsThePipelineNotTheBareAction(): void
    {
        $controller = self::getContainer()->get(CreateOrderController::class);
        self::assertInstanceOf(CreateOrderController::class, $controller);

        self::assertInstanceOf(ActionPipeline::class, new ReflectionProperty($controller, 'create')->getValue($controller));
    }

    public function testTheLogicBridgesWrapTheActionOutsideLogging(): void
    {
        // logic-bundle exposes no public middleware order; ActionPipeline::$middlewares is outermost first.
        $pipeline = self::getContainer()->get(Action::class);
        self::assertIsObject($pipeline);
        $middlewares = new ReflectionProperty($pipeline, 'middlewares')->getValue($pipeline);
        self::assertIsIterable($middlewares);
        $classes = [];
        foreach ($middlewares as $middleware) {
            self::assertIsObject($middleware);
            $classes[] = $middleware::class;
        }

        $outermost = [TracingMiddleware::class, ProfilingMiddleware::class, MetricsMiddleware::class, LoggingMiddleware::class];
        self::assertSame($outermost, array_slice($classes, 0, count($outermost)));
    }
}
