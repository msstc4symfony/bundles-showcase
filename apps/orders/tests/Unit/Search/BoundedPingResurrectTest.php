<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\BoundedPingResurrect;
use Elastic\Transport\NodePool\Node;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BoundedPingResurrectTest extends TestCase
{
    #[TestWith([200, true])]
    #[TestWith([503, false])]
    public function testANodeIsAliveOnlyWhenItAnswersOk(int $status, bool $alive): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requests, $status): MockResponse {
            $requests[] = $method . ' ' . $url;

            return new MockResponse('', ['http_code' => $status]);
        });

        self::assertSame($alive, new BoundedPingResurrect(new Psr18Client($http))->ping(new Node('http://elasticsearch:9200')));
        self::assertSame(['HEAD http://elasticsearch:9200/'], $requests);
    }

    public function testATimedOutPingMeansDead(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse([''], ['error' => 'Max duration was reached']));

        self::assertFalse(new BoundedPingResurrect(new Psr18Client($http))->ping(new Node('http://elasticsearch:9200')));
    }

    public function testAnyFailureOfThePingMeansDead(): void
    {
        $http = new MockHttpClient(static fn (): never => throw new LogicException('Broken client'));

        self::assertFalse(new BoundedPingResurrect(new Psr18Client($http))->ping(new Node('http://elasticsearch:9200')));
    }
}
