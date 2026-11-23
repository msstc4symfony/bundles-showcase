<?php

declare(strict_types=1);

namespace App\Tests\Unit\Slo;

use App\Slo\OpenMetrics;
use PHPUnit\Framework\TestCase;

final class OpenMetricsTest extends TestCase
{
    public function testNoLabelsIsNoBraces(): void
    {
        self::assertSame('', OpenMetrics::labelSet([]));
    }

    public function testBackslashQuoteAndNewlineAreEscaped(): void
    {
        self::assertSame('{a="x\\\\y",b="say \\"hi\\"",c="1\\n2"}', OpenMetrics::labelSet(['a' => 'x\\y', 'b' => 'say "hi"', 'c' => "1\n2"]));
    }
}
