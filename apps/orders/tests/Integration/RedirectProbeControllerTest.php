<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Override;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RedirectProbeControllerTest extends WebTestCase
{
    #[Override]
    protected function tearDown(): void
    {
        // Back to phpunit.dist.xml's empty value: unset would let a real BAGGAGE_ECHO_URL through getenv().
        $_SERVER['BAGGAGE_ECHO_URL'] = '';
        unset($_ENV['BAGGAGE_ECHO_URL']);
        parent::tearDown();
    }

    public function testIsOffWithoutAnEcho(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_probe/redirect');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRedirectsToTheEchoKeepingTheMethod(): void
    {
        $_SERVER['BAGGAGE_ECHO_URL'] = $_ENV['BAGGAGE_ECHO_URL'] = 'http://echo.test';
        $client = self::createClient();
        $client->request('GET', '/_probe/redirect');

        self::assertResponseStatusCodeSame(307);
        self::assertResponseRedirects('http://echo.test/via-orders');
    }
}
