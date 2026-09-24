<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use SllhSmile\Elasticsearch\Config\ConnectionConfig;
use SllhSmile\Elasticsearch\Exception\ConfigurationException;
use SllhSmile\Elasticsearch\ConfigProvider;

final class ConfigTest extends TestCase
{
    public function testConfigDefaultsAndValidation(): void
    {
        $config = ConnectionConfig::fromArray(['hosts' => ['https://example.test']]);
        self::assertSame(10, $config->timeout);
        self::assertSame(1, $config->retries);
        self::assertTrue($config->verifyTls);
    }

    public function testInvalidAuthCombinationIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        ConnectionConfig::fromArray(['hosts' => ['https://example.test'], 'api_key' => 'x', 'username' => 'u', 'password' => 'p']);
    }

    public function testIncompleteBasicAuthenticationIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        ConnectionConfig::fromArray(['hosts' => ['https://example.test'], 'username' => 'u']);
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRejected(array $config): void
    {
        $this->expectException(ConfigurationException::class);
        ConnectionConfig::fromArray($config);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'host is not a URL' => [['hosts' => ['example.test']]];
        yield 'host embeds credentials' => [['hosts' => ['https://user:pass@example.test']]];
        yield 'host has unsupported scheme' => [['hosts' => ['ftp://example.test']]];
        yield 'timeout string is not coerced' => [['hosts' => ['https://example.test'], 'timeout' => '10']];
        yield 'retry string is not coerced' => [['hosts' => ['https://example.test'], 'retries' => '0']];
        yield 'empty api key' => [['hosts' => ['https://example.test'], 'api_key' => '']];
        yield 'authorization header override' => [['hosts' => ['https://example.test'], 'headers' => ['authorization' => 'Bearer bad']]];
        yield 'header injection' => [['hosts' => ['https://example.test'], 'headers' => ['X-Test' => "ok\r\nbad"]]];
        yield 'missing CA path' => [['hosts' => ['https://example.test'], 'verify_tls' => '/missing/ca.pem']];
        yield 'removed connect timeout' => [['hosts' => ['https://example.test'], 'connect_timeout' => 1]];
        yield 'removed client options' => [['hosts' => ['https://example.test'], 'client_options' => []]];
        yield 'unknown typo is rejected' => [['hosts' => ['https://example.test'], 'retrise' => 0]];
    }

    public function testConfigProviderUsesRootProviderAndPublishDirectory(): void
    {
        $config = (new ConfigProvider())();
        self::assertArrayHasKey(\SllhSmile\Elasticsearch\Hyperf\Manager::class, $config['dependencies']);
        self::assertArrayNotHasKey(\SllhSmile\Elasticsearch\Client\ElasticsearchClient::class, $config['dependencies']);
        self::assertArrayNotHasKey(\SllhSmile\Elasticsearch\Contract\ClientInterface::class, $config['dependencies']);
        self::assertSame(dirname(__DIR__) . '/publish/elasticsearch.php', realpath($config['publish'][0]['source']));
    }
}
