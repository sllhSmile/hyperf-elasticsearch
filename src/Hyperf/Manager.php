<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Hyperf;

use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Config\ConnectionConfig;
use SllhSmile\Elasticsearch\Hyperf\Factory\ClientFactory;

/**
 * 连接管理器，按名称懒加载并缓存 ElasticsearchClient。
 */
final class Manager
{
    private array $clients = [];

    /** 注入客户端工厂和 Hyperf elasticsearch 配置数组。 */
    public function __construct(private readonly ClientFactory $factory, private readonly array $config = [])
    {
    }

    /** 获取命名连接；首次调用会校验配置并创建客户端。 */
    public function connection(?string $name = null): ElasticsearchClient
    {
        $name ??= (string) ($this->config['default'] ?? 'default');
        if (! isset($this->clients[$name])) {
            $connection = $this->config['connections'][$name] ?? null;
            if (! is_array($connection)) {
                throw new \InvalidArgumentException("Elasticsearch connection [{$name}] is not configured.");
            }
            $this->clients[$name] = $this->factory->make(ConnectionConfig::fromArray($connection));
        }
        return $this->clients[$name];
    }

    /** 清理一个或全部客户端缓存，便于配置热更新或测试隔离。 */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->clients = [];
            return;
        }
        unset($this->clients[$name]);
    }
}
