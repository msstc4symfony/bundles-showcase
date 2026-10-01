<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Webmozart\Assert\Assert;

final readonly class GatewayResponse
{
    /**
     * @param array<string, mixed> $body decoded JSON; empty when the body is not a JSON object
     * @param array<string, list<string>> $headers lower-cased names
     */
    public function __construct(
        public int $status,
        public array $body,
        public array $headers,
    ) {
    }

    public function field(string $name): string
    {
        $value = $this->body[$name] ?? null;
        Assert::string($value, sprintf('Response field "%s" is missing or not a string (HTTP %d).', $name, $this->status));

        return $value;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
