<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Client;

use Closure;
use Elastic\Elasticsearch\Client;
use Psr\Log\LoggerInterface;
use SllhSmile\Elasticsearch\Adapter\OfficialClientAdapter;
use SllhSmile\Elasticsearch\Bulk\BulkManager;
use SllhSmile\Elasticsearch\Contract\ClientAdapterInterface;
use SllhSmile\Elasticsearch\Contract\ClientInterface;
use SllhSmile\Elasticsearch\Exception\ElasticsearchException;
use SllhSmile\Elasticsearch\Exception\TransportException;
use SllhSmile\Elasticsearch\Index\IndexManager;
use Throwable;

/** 版本无关的 Elasticsearch 客户端外观。 */
final class ElasticsearchClient implements ClientInterface
{
    /** @var array<string, ClientAdapterInterface> */
    private array $adapters = [];

    /** @var null|Closure(): (Client|ClientAdapterInterface) */
    private ?Closure $clientFactory = null;

    /** @var null|Closure(): string */
    private ?Closure $scopeResolver = null;

    /**
     * 仅由命名构造方法创建，避免固定客户端与可重建工厂的生命周期混淆。
     */
    private function __construct(private readonly ?LoggerInterface $logger = null) {}

    /** 包装固定的官方 Client 或显式 Adapter；传输故障后不替换该实例。 */
    public static function fromClient(Client|ClientAdapterInterface $client, ?LoggerInterface $logger = null): self
    {
        $instance = new self($logger);
        $instance->adapters['fixed'] = self::toAdapter($client);
        return $instance;
    }

    /**
     * 创建按执行作用域延迟构建的客户端，传输故障只重建当前作用域。
     *
     * @param Closure(): (Client|ClientAdapterInterface) $factory
     * @param null|Closure(): string $scopeResolver
     */
    public static function fromFactory(Closure $factory, ?Closure $scopeResolver = null, ?LoggerInterface $logger = null): self
    {
        $instance = new self($logger);
        $instance->clientFactory = $factory;
        $instance->scopeResolver = $scopeResolver ?? static fn(): string => 'default';
        return $instance;
    }

    /** @param array<string, mixed> $params */
    public function call(string $operation, array $params = []): mixed
    {
        [$scope, $adapter] = $this->resolveAdapter();
        try {
            return $adapter->call($operation, $params);
        } catch (Throwable $exception) {
            throw $this->normalizeException($exception, $scope, $adapter);
        }
    }

    /** 保留低层回调入口；新代码应优先使用 call/endpoint 方法。 */
    public function execute(callable $operation): mixed
    {
        [$scope, $adapter] = $this->resolveAdapter();
        try {
            return $operation($adapter->raw());
        } catch (Throwable $exception) {
            throw $this->normalizeException($exception, $scope, $adapter);
        }
    }

    /** @return array<mixed> */
    public function responseToArray(mixed $response): array
    {
        [, $adapter] = $this->resolveAdapter();
        return $adapter->responseToArray($response);
    }

    /** 将官方 exists 等响应转换为统一布尔值。 */
    public function responseToBool(mixed $response): bool
    {
        [, $adapter] = $this->resolveAdapter();
        return $adapter->responseToBool($response);
    }

    /** @param array<string, mixed> $params */
    public function info(array $params = []): mixed
    {
        return $this->call('info', $params);
    }

    /** @param array<string, mixed> $params */
    public function search(array $params): mixed
    {
        return $this->call('search', $params);
    }

    /** @param array<string, mixed> $params */
    public function get(array $params): mixed
    {
        return $this->call('get', $params);
    }

    /** @param array<string, mixed> $params */
    public function index(array $params): mixed
    {
        return $this->call('index', $params);
    }

    /** @param array<string, mixed> $params */
    public function create(array $params): mixed
    {
        return $this->call('create', $params);
    }

    /** @param array<string, mixed> $params */
    public function update(array $params): mixed
    {
        return $this->call('update', $params);
    }

    /** @param array<string, mixed> $params */
    public function delete(array $params): mixed
    {
        return $this->call('delete', $params);
    }

    /** @param array<string, mixed> $params */
    public function bulk(array $params): mixed
    {
        return $this->call('bulk', $params);
    }

    /** 创建复用当前客户端的索引管理器。 */
    public function indexManager(): IndexManager
    {
        return new IndexManager($this);
    }

    /** 创建复用当前客户端并使用指定分块大小的 Bulk 管理器。 */
    public function bulkManager(int $chunkSize = 500): BulkManager
    {
        return new BulkManager($this, $chunkSize);
    }

    /** 创建复用当前客户端的 PIT 生命周期管理器。 */
    public function pitManager(): PitManager
    {
        return new PitManager($this, $this->logger);
    }

    /** @return array{string, ClientAdapterInterface} */
    private function resolveAdapter(): array
    {
        if ($this->clientFactory === null) {
            return ['fixed', $this->adapters['fixed']];
        }
        $scopeResolver = $this->scopeResolver;
        $factory = $this->clientFactory;
        if ($scopeResolver === null) {
            throw new ElasticsearchException('Elasticsearch client scope resolver is not configured.');
        }
        $scope = $scopeResolver();
        if ($scope === '') {
            throw new ElasticsearchException('Elasticsearch client scope cannot be empty.');
        }
        if (! isset($this->adapters[$scope])) {
            $this->adapters[$scope] = self::toAdapter($factory());
        }
        return [$scope, $this->adapters[$scope]];
    }

    /** 将官方 Client 包装为统一 Adapter，显式 Adapter 保持原样。 */
    private static function toAdapter(Client|ClientAdapterInterface $client): ClientAdapterInterface
    {
        return $client instanceof ClientAdapterInterface ? $client : new OfficialClientAdapter($client);
    }

    /** 归一化异常；只有当前引用仍是失败实例时才清理，避免旧协程删除新客户端。 */
    private function normalizeException(Throwable $exception, string $scope, ClientAdapterInterface $adapter): Throwable
    {
        $normalized = $adapter->normalizeException($exception);
        if ($normalized instanceof TransportException
            && $this->clientFactory !== null
            && ($this->adapters[$scope] ?? null) === $adapter) {
            unset($this->adapters[$scope]);
        }
        return $normalized;
    }
}
