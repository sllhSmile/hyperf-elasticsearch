<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use PHPUnit\Framework\TestCase;
use SllhSmile\Elasticsearch\Config\ConnectionConfig;
use SllhSmile\Elasticsearch\Exception\ConfigurationException;
use SllhSmile\Elasticsearch\ConfigProvider;

final class ConfigTest extends TestCase
{
    public function testConfigDefaultsAndValidation(): void
    {
        $config = ConnectionConfig::fromArray(['hosts' => ['https://example.test']]);
        self::assertSame(10, $config->timeout);
    }

    public function testInvalidAuthCombinationIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        ConnectionConfig::fromArray(['hosts' => ['https://example.test'], 'api_key' => 'x', 'username' => 'u', 'password' => 'p']);
    }

    public function testConfigProviderUsesRootProviderAndPublishDirectory(): void
    {
        $config = (new ConfigProvider())();
        self::assertArrayHasKey(\SllhSmile\Elasticsearch\Hyperf\Manager::class, $config['dependencies']);
        self::assertArrayHasKey(\SllhSmile\Elasticsearch\Client\ElasticsearchClient::class, $config['dependencies']);
        self::assertSame(dirname(__DIR__) . '/publish/elasticsearch.php', realpath($config['publish'][0]['source']));
    }
}
