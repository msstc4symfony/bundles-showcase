<?php

declare(strict_types=1);

namespace App\Orders;

use Webmozart\Assert\Assert;

final readonly class OrdersResponse
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        Assert::isArray($decoded);
        Assert::integer($decoded['status'] ?? null);
        Assert::string($decoded['body'] ?? null);

        return new self($decoded['status'], $decoded['body']);
    }

    public function toJson(): string
    {
        return json_encode(['status' => $this->status, 'body' => $this->body], JSON_THROW_ON_ERROR);
    }
}
