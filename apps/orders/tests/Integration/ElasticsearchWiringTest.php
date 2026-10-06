<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Search\BoundedPingResurrect;
use App\Search\ElasticaOrderIndex;
use Elastic\Transport\NodePool\SimpleNodePool;
use FOS\ElasticaBundle\Elastica\Client;
use Msstc4Symfony\MetricsBundle\Infrastructure\Elastica\TimingHttpClient;
use Override;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pins the production Elasticsearch wiring of the compiled container: the app overrides FOSElasticaBundle's
 * "RoundRobin" node pool service by id, so a renamed id in FOS must fail here instead of silently
 * restoring the unbounded ping.
 */
final class ElasticsearchWiringTest extends KernelTestCase
{
    /** @var resource|null */
    private $listener;

    /** @var array{server: ?string, env: ?string, getenv: string|false} */
    private array $previousUrl = ['server' => null, 'env' => null, 'getenv' => false];

    private const string URL_VARIABLE = 'ELASTICSEARCH_URL';

    #[Override]
    protected function setUp(): void
    {
        // A local socket nobody answers: a request to it would sit in the accept backlog and be seen below.
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($listener);
        $this->listener = $listener;
        $address = stream_socket_get_name($listener, false);
        self::assertIsString($address);

        $this->previousUrl = [
            'server' => $this->stringOrNull($_SERVER[self::URL_VARIABLE] ?? null),
            'env' => $this->stringOrNull($_ENV[self::URL_VARIABLE] ?? null),
            'getenv' => getenv(self::URL_VARIABLE),
        ];
        $url = 'http://' . $address;
        $_SERVER[self::URL_VARIABLE] = $_ENV[self::URL_VARIABLE] = $url;
        putenv(self::URL_VARIABLE . '=' . $url);
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->listener !== null) {
            fclose($this->listener);
        }

        if ($this->previousUrl['server'] === null) {
            unset($_SERVER[self::URL_VARIABLE]);
        } else {
            $_SERVER[self::URL_VARIABLE] = $this->previousUrl['server'];
        }

        if ($this->previousUrl['env'] === null) {
            unset($_ENV[self::URL_VARIABLE]);
        } else {
            $_ENV[self::URL_VARIABLE] = $this->previousUrl['env'];
        }

        putenv($this->previousUrl['getenv'] === false ? self::URL_VARIABLE : self::URL_VARIABLE . '=' . $this->previousUrl['getenv']);
    }

    public function testTheClientResurrectsDeadNodesWithABoundedPingAndIsMeasured(): void
    {
        $client = self::getContainer()->get(Client::class);
        self::assertInstanceOf(Client::class, $client);
        $transport = $client->getTransport();

        $pool = $transport->getNodePool();
        self::assertInstanceOf(SimpleNodePool::class, $pool);
        self::assertInstanceOf(BoundedPingResurrect::class, new ReflectionProperty(SimpleNodePool::class, 'resurrect')->getValue($pool));
        self::assertInstanceOf(TimingHttpClient::class, $transport->getClient());
        self::assertNoConnection();
    }

    public function testBootAndBuildingTheIndexAndTheReadinessCheckerCallNoElasticsearch(): void
    {
        $container = self::getContainer();

        self::assertInstanceOf(ElasticaOrderIndex::class, $container->get(ElasticaOrderIndex::class));
        self::assertTrue($container->has('healthcheck.checker.fos_elastica.client.default'));
        self::assertIsObject($container->get('healthcheck.checker.fos_elastica.client.default'));
        self::assertNoConnection();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    private function assertNoConnection(): void
    {
        self::assertNotNull($this->listener);
        self::assertFalse(@stream_socket_accept($this->listener, 0), 'Elasticsearch was called while building services.');
    }
}
