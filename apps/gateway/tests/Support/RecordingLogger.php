<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : 'unknown', 'message' => (string) $message];
    }

    public function has(string $level): bool
    {
        return array_filter($this->records, static fn (array $record): bool => $record['level'] === $level) !== [];
    }
}
