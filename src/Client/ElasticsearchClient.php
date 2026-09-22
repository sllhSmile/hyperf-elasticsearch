<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Client;

use Closure;
use SllhSmile\Elasticsearch\Adapter\AdapterFactory;
use SllhSmile\Elasticsearch\Adapter\ClientCapabilities;
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
    private object $client;

    private ?ClientAdapterInterface $adapter = null;

    /**
     * 保存官方客户端、adapter 或延迟创建 Closure；构造阶段不会访问网络。
     *
     * @param object $client
     */
    public function __construct(object $client)
    {
        $this->client = $client;
    }

    /** 执行 Core operation，并统一转换官方异常。 */
    public function call(string $operation, array $params = []): mixed
    {
        try {
            return $this->adapter()->call($operation, $params);
        } catch (Throwable $exception) {
            throw $this->normalizeException($exception);
        }
    }

    /** 保留低层回调入口；新代码应优先使用 call/endpoint 方法。 */
    public function execute(callable $operation): mixed
    {
        try {
            return $operation($this->adapter()->raw());
        } catch (Throwable $exception) {
            throw $this->normalizeException($exception);
        }
    }

    /**
     * 丢弃当前官方客户端和节点池；下一次请求会通过延迟工厂重新创建。
     * 传输故障可能让官方客户端把单节点标记为 dead，长驻 Worker 不能因此永久失效。
     */
    public function reset(): void
    {
        $this->adapter = null;
    }

    /** 按路径分派 GET 请求到本包支持的官方 endpoint。 */
    public function requestGet(string $path, array $query = [], array $options = []): mixed
    {
        return $this->dispatch('GET', $path, $query, null, $options);
    }

    /** 按路径分派 POST 请求，并将可选 body 传给官方 endpoint。 */
    public function requestPost(string $path, array $query = [], ?array $body = null, array $options = []): mixed
    {
        return $this->dispatch('POST', $path, $query, $body, $options);
    }

    /** 按路径分派 PUT 请求，并将可选 body 传给官方 endpoint。 */
    public function requestPut(string $path, array $query = [], ?array $body = null, array $options = []): mixed
    {
        return $this->dispatch('PUT', $path, $query, $body, $options);
    }

    /** 按路径分派 DELETE 请求，并将可选 body 传给官方 endpoint。 */
    public function requestDelete(string $path, array $query = [], ?array $body = null, array $options = []): mixed
    {
        return $this->dispatch('DELETE', $path, $query, $body, $options);
    }

    /** 返回延迟解析后的官方客户端实例。 */
    public function raw(): object
    {
        return $this->adapter()->raw();
    }

    /** 返回官方 indices endpoint 逃生入口。 */
    public function indices(): mixed
    {
        return $this->raw()->indices();
    }

    /** 返回当前官方客户端主版本和协议能力。 */
    public function capabilities(): ClientCapabilities
    {
        return $this->adapter()->capabilities();
    }

    /** 将官方数组或响应对象转换为统一数组。 */
    public function responseToArray(mixed $response): array
    {
        return $this->adapter()->responseToArray($response);
    }

    /** 将官方 exists 等响应转换为统一布尔值。 */
    public function responseToBool(mixed $response): bool
    {
        return $this->adapter()->responseToBool($response);
    }

    /** 调用集群 info endpoint。 */
    public function info(array $params = []): mixed { return $this->call('info', $params); }

    /** 调用 search endpoint。 */
    public function search(array $params): mixed { return $this->call('search', $params); }

    /** 调用文档 get endpoint。 */
    public function get(array $params): mixed { return $this->call('get', $params); }

    /** 调用文档 index endpoint。 */
    public function index(array $params): mixed { return $this->call('index', $params); }

    /** 调用文档 create endpoint。 */
    public function create(array $params): mixed { return $this->call('create', $params); }

    /** 调用文档 update endpoint。 */
    public function update(array $params): mixed { return $this->call('update', $params); }

    /** 调用文档 delete endpoint。 */
    public function delete(array $params): mixed { return $this->call('delete', $params); }

    /** 调用 bulk endpoint。 */
    public function bulk(array $params): mixed { return $this->call('bulk', $params); }

    /** 创建复用当前客户端的索引管理器。 */
    public function indexManager(): IndexManager { return new IndexManager($this); }

    /** 创建复用当前客户端并使用指定分块大小的 Bulk 管理器。 */
    public function bulkManager(int $chunkSize = 500): BulkManager { return new BulkManager($this, $chunkSize); }

    /** 创建复用当前客户端的 PIT 生命周期管理器。 */
    public function pitManager(): PitManager { return new PitManager($this); }

    /** 首次使用时解析延迟工厂，并缓存与官方客户端版本匹配的 adapter。 */
    private function adapter(): ClientAdapterInterface
    {
        if ($this->adapter !== null) {
            return $this->adapter;
        }
        $client = $this->client instanceof Closure ? ($this->client)() : $this->client;
        if ($client instanceof ClientAdapterInterface) {
            return $this->adapter = $client;
        }
        if (! is_object($client)) {
            throw new ElasticsearchException('Elasticsearch client factory must return an object.');
        }
        return $this->adapter = AdapterFactory::fromClient($client);
    }

    /** 归一化异常；传输故障会清除 adapter，使下一次请求重建官方节点池。 */
    private function normalizeException(Throwable $exception): Throwable
    {
        $normalized = $this->adapter()->normalizeException($exception);
        if ($normalized instanceof TransportException) {
            $this->reset();
        }
        return $normalized;
    }

    /** 将有限的 REST 路径集合映射为稳定 operation，不提供任意 HTTP 传输。 */
    private function dispatch(string $method, string $path, array $query, ?array $body, array $options): mixed
    {
        $method = strtoupper($method);
        $normalized = '/' . trim($path, '/');
        $params = array_replace($query, $options);
        if ($body !== null) {
            $params['body'] = $body;
        }
        // 根路径、搜索、mapping、索引、Bulk 和文档路由按顺序匹配，未声明路由明确拒绝。
        if ($normalized === '/') {
            if ($method !== 'GET') {
                throw new \BadMethodCallException("Unsupported Elasticsearch request method [{$method} {$path}].");
            }
            return $this->info($params);
        }
        if (preg_match('#^/(.+)/_search$#', $normalized, $matches) === 1 || $normalized === '/_search') {
            if (! in_array($method, ['GET', 'POST'], true)) {
                throw new \BadMethodCallException("Unsupported Elasticsearch request method [{$method} {$path}].");
            }
            if (isset($matches[1])) {
                $params['index'] = urldecode($matches[1]);
            }
            return $this->search($params);
        }
        if (preg_match('#^/(.+)/_mapping$#', $normalized, $matches) === 1) {
            if (! in_array($method, ['GET', 'PUT'], true)) {
                throw new \BadMethodCallException("Unsupported Elasticsearch request method [{$method} {$path}].");
            }
            $params['index'] = urldecode($matches[1]);
            return $this->call($method === 'GET' ? 'indices.getMapping' : 'indices.putMapping', $params);
        }
        if (preg_match('#^/([^/]+)$#', $normalized, $matches) === 1 && in_array($method, ['PUT', 'DELETE'], true)) {
            $params['index'] = urldecode($matches[1]);
            return $this->call($method === 'PUT' ? 'indices.create' : 'indices.delete', $params);
        }
        if ($normalized === '/_bulk' && $method === 'POST') {
            return $this->bulk($params);
        }
        if (preg_match('#^/(.+)/_doc/([^/]+)$#', $normalized, $matches) !== 1
            && preg_match('#^/(.+)/([^/]+)$#', $normalized, $matches) !== 1) {
            throw new \BadMethodCallException("Unsupported Elasticsearch request route [{$method} {$path}].");
        }
        if (! in_array($method, ['GET', 'PUT', 'POST', 'DELETE'], true)) {
            throw new \BadMethodCallException("Unsupported Elasticsearch request method [{$method} {$path}].");
        }
        $params['index'] = urldecode($matches[1]);
        $params['id'] = urldecode($matches[2]);
        return match ($method) {
            'GET' => $this->call('get', $params),
            'PUT', 'POST' => $this->index($params),
            'DELETE' => $this->delete($params),
        };
    }
}
