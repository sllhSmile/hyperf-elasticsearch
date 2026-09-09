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

    /** @param object $client 官方客户端实例、adapter 或延迟创建 Closure。 */
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

    public function requestGet(string $path, array $query = [], array $options = []): mixed
    {
        return $this->dispatch('GET', $path, $query, null, $options);
    }

    public function requestPost(string $path, array $query = [], ?array $body = null, array $options = []): mixed
    {
        return $this->dispatch('POST', $path, $query, $body, $options);
    }

    public function requestPut(string $path, array $query = [], ?array $body = null, array $options = []): mixed
    {
        return $this->dispatch('PUT', $path, $query, $body, $options);
    }

    public function requestDelete(string $path, array $query = [], ?array $body = null, array $options = []): mixed
    {
        return $this->dispatch('DELETE', $path, $query, $body, $options);
    }

    public function raw(): object
    {
        return $this->adapter()->raw();
    }

    /** 返回官方 indices endpoint 逃生入口。 */
    public function indices(): mixed
    {
        return $this->raw()->indices();
    }

    public function capabilities(): ClientCapabilities
    {
        return $this->adapter()->capabilities();
    }

    public function responseToArray(mixed $response): array
    {
        return $this->adapter()->responseToArray($response);
    }

    public function responseToBool(mixed $response): bool
    {
        return $this->adapter()->responseToBool($response);
    }

    public function info(array $params = []): mixed { return $this->call('info', $params); }
    public function search(array $params): mixed { return $this->call('search', $params); }
    public function get(array $params): mixed { return $this->call('get', $params); }
    public function index(array $params): mixed { return $this->call('index', $params); }
    public function create(array $params): mixed { return $this->call('create', $params); }
    public function update(array $params): mixed { return $this->call('update', $params); }
    public function delete(array $params): mixed { return $this->call('delete', $params); }
    public function bulk(array $params): mixed { return $this->call('bulk', $params); }

    public function indexManager(): IndexManager { return new IndexManager($this); }
    public function bulkManager(int $chunkSize = 500): BulkManager { return new BulkManager($this, $chunkSize); }
    public function pitManager(): PitManager { return new PitManager($this); }

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

    private function normalizeException(Throwable $exception): Throwable
    {
        $normalized = $this->adapter()->normalizeException($exception);
        if ($normalized instanceof TransportException) {
            $this->reset();
        }
        return $normalized;
    }

    private function dispatch(string $method, string $path, array $query, ?array $body, array $options): mixed
    {
        $method = strtoupper($method);
        $normalized = '/' . trim($path, '/');
        $params = array_replace($query, $options);
        if ($body !== null) {
            $params['body'] = $body;
        }
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
