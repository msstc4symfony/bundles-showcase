<?php

declare(strict_types=1);

namespace App\Tests\Unit\Slo;

use App\Slo\RangeWindow;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RangeWindowTest extends TestCase
{
    public function testChunksCoverTheGridOnceWithAtMostMaxPointsEach(): void
    {
        $chunks = new RangeWindow(1000, 1950, 100)->chunks(4);

        self::assertSame(
            [[1000, 1300], [1400, 1700], [1800, 1950]],
            array_map(static fn (RangeWindow $chunk): array => [$chunk->start, $chunk->end], $chunks),
        );
    }

    public function testAWindowOfOneInstantIsOneChunk(): void
    {
        self::assertCount(1, new RangeWindow(1000, 1000, 300)->chunks(11_000));
    }

    public function testChunksOfOnePointAreTheInstants(): void
    {
        self::assertSame(
            [[1000, 1000], [1300, 1300], [1600, 1600]],
            array_map(static fn (RangeWindow $chunk): array => [$chunk->start, $chunk->end], new RangeWindow(1000, 1700, 300)->chunks(1)),
        );
    }

    public function testTheEndMustNotBeBeforeTheStart(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RangeWindow(2000, 1000, 300);
    }
}
