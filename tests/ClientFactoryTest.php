<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use Hyperf\Elasticsearch\ClientBuilderFactory;
use Hyperf\Guzzle\ClientFactory as GuzzleClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SllhSmile\Elasticsearch\Config\ConnectionConfig;
use SllhSmile\Elasticsearch\Hyperf\Factory\ClientFactory;

final class ClientFactoryTest extends TestCase
{
    public function testElastic8Or9KeepsTimeoutTlsAndHeadersOnHyperfClient(): void
    {
        if (! class_exists('Elastic\\Elasticsearch\\ClientBuilder')) {
            self::markTestSkipped('This assertion targets the ES8/9 HTTP client branch.');
        }

        $container = $this->container();

        $client = (new ClientFactory($container))->make(new ConnectionConfig(
            hosts: ['https://example.test'],
            timeout: 17,
            connectTimeout: 4,
            verifyTls: '/tmp/test-ca.pem',
            headers: ['X-Test' => 'factory'],
        ))->raw();
        $httpConfig = $client->getTransport()->getClient()->getConfig();

        self::assertSame(17, $httpConfig['timeout']);
        self::assertSame(4, $httpConfig['connect_timeout']);
        self::assertSame('/tmp/test-ca.pem', $httpConfig['verify']);
        self::assertSame('factory', $httpConfig['headers']['X-Test']);
    }

    public function testElastic7UsesMatchedBuilderWithEncodedApiKey(): void
    {
        if (! class_exists('Elasticsearch\\ClientBuilder')) {
            self::markTestSkipped('This assertion targets the ES7 RingPHP branch.');
        }

        $client = (new ClientFactory($this->container()))->make(new ConnectionConfig(
            hosts: ['https://example.test'],
            apiKey: base64_encode('id:secret'),
            timeout: 17,
            connectTimeout: 4,
            verifyTls: false,
            headers: ['X-Test' => 'factory'],
        ))->raw();

        self::assertInstanceOf('Elasticsearch\\Client', $client);
    }

    private function container(): ContainerInterface
    {
        $container = new class implements ContainerInterface {
            public array $services = [];

            public function get(string $id): mixed
            {
                return $this->services[$id] ?? throw new \RuntimeException($id);
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
        $container->services[GuzzleClientFactory::class] = new GuzzleClientFactory($container);
        $reflection = new \ReflectionClass(ClientBuilderFactory::class);
        $arguments = $reflection->getConstructor() === null ? [] : [$container];
        $container->services[ClientBuilderFactory::class] = $reflection->newInstanceArgs($arguments);
        return $container;
    }
}
