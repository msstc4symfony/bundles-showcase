<?php

declare(strict_types=1);

namespace App\Tests\Unit\Slo;

use App\Slo\Diagnostics;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase
{
    public function testWhatEncodesIsJson(): void
    {
        self::assertSame('{"record":"a/b","n":[1]}', Diagnostics::describe(['record' => 'a/b', 'n' => [1]]));
    }

    public function testWhatDoesNotEncodeFallsBackWithoutThrowing(): void
    {
        self::assertSame('INF', Diagnostics::describe(INF));
        self::assertSame('array', Diagnostics::describe([NAN]));
    }
}
