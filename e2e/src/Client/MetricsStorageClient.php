<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Webmozart\Assert\Assert;

/**
 * The apps' metrics storage in the compose Redis, through redis-cli in its container (Redis publishes no port).
 * promphp keeps a counter in one hash per metric; each field is the JSON list of label values.
 */
final readonly class MetricsStorageClient
{
    private const string SERVICE = 'redis';

    public function __construct(private DockerClient $docker = new DockerClient())
    {
    }

    /**
     * @param non-negative-int $database
     * @param non-empty-string $metric
     *
     * @return non-empty-string
     */
    public function counterKey(int $database, string $metric): string
    {
        $keys = $this->lines($this->redis($database, '--scan', '--pattern', '*:counter:' . $metric));
        Assert::count($keys, 1, sprintf('Expected one counter hash of %s in Redis DB %d, found: %s.', $metric, $database, implode(', ', $keys)));

        return $keys[0];
    }

    /**
     * @param non-negative-int $database
     * @param non-empty-string $key
     *
     * @return list<non-empty-string>
     */
    public function fields(int $database, string $key): array
    {
        return $this->lines($this->redis($database, 'hkeys', $key));
    }

    /**
     * @param non-negative-int $database
     * @param non-empty-string $key
     * @param non-empty-string $field
     */
    public function increment(int $database, string $key, string $field, int $by): void
    {
        $reply = trim($this->redis($database, 'hincrbyfloat', $key, $field, (string) $by));
        Assert::numeric($reply, sprintf('HINCRBYFLOAT %s failed: %s', $key, $reply));
    }

    /**
     * @param non-negative-int $database
     * @param non-empty-string $key
     * @param non-empty-string $field
     */
    public function delete(int $database, string $key, string $field): void
    {
        $reply = trim($this->redis($database, 'hdel', $key, $field));
        Assert::inArray($reply, ['0', '1'], sprintf('HDEL %s failed: %s', $key, $reply));
    }

    /**
     * @param non-negative-int $database
     */
    private function redis(int $database, string ...$arguments): string
    {
        return $this->docker->exec(self::SERVICE, ['redis-cli', '-n', (string) $database, ...array_values($arguments)]);
    }

    /**
     * @return list<non-empty-string>
     */
    private function lines(string $output): array
    {
        $lines = [];
        foreach (explode("\n", $output) as $line) {
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
