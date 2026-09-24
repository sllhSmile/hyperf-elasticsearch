<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Hyperf;

use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Config\ConnectionConfig;
use SllhSmile\Elasticsearch\Exception\ConfigurationException;
use SllhSmile\Elasticsearch\Hyperf\Factory\ClientFactory;

/**
 * 连接管理器，按名称懒加载并缓存 ElasticsearchClient。
 */
final class Manager
{
    /** @var array<string, ElasticsearchClient> */
    private array $clients = [];

    /** @var array<string, ConnectionConfig> */
    private array $connections = [];

    private string $defaultConnection;

    /**
     * 在 Manager 进入 Worker 缓存前校验全部配置，但不创建 HTTP Client 或访问网络。
     *
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly ClientFactory $factory, array $config = [])
    {
        foreach (array_keys($config) as $key) {
            if (! in_array($key, ['default', 'connections'], true)) {
                throw new ConfigurationException("Unknown Elasticsearch root option [{$key}].");
            }
        }
        $default = $config['default'] ?? null;
        $connections = $config['connections'] ?? null;
        if (! is_string($default) || $default === '' || ! is_array($connections)) {
            throw new ConfigurationException('Elasticsearch config requires a non-empty default name and connections array.');
        }
        foreach ($connections as $name => $connection) {
            if (! is_string($name) || $name === '' || ! is_array($connection)) {
                throw new ConfigurationException('Elasticsearch connection names must be non-empty strings with array configuration.');
            }
            $this->connections[$name] = ConnectionConfig::fromArray($connection);
        }
        if (! isset($this->connections[$default])) {
            throw new ConfigurationException("Elasticsearch default connection [{$default}] is not configured.");
        }
        $this->defaultConnection = $default;
    }

    /** 获取命名连接；首次调用会校验配置并创建客户端。 */
    public function connection(?string $name = null): ElasticsearchClient
    {
        $name ??= $this->defaultConnection;
        if ($name === '') {
            throw new ConfigurationException('Elasticsearch connection name cannot be empty.');
        }
        if (! isset($this->clients[$name])) {
            $connection = $this->connections[$name] ?? null;
            if (! $connection instanceof ConnectionConfig) {
                throw new ConfigurationException("Elasticsearch connection [{$name}] is not configured.");
            }
            $this->clients[$name] = $this->factory->make($connection);
        }
        return $this->clients[$name];
    }

    /** 清理一个或全部客户端缓存；配置快照不重读，更新配置仍需重启 Worker。 */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->clients = [];
            return;
        }
        unset($this->clients[$name]);
    }
}
