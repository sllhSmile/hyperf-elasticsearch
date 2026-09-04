<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Client;

use Elastic\Elasticsearch\Exception\ClientResponseException as OfficialClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException as OfficialServerResponseException;
use Elastic\Elasticsearch\Exception\ElasticsearchException as OfficialElasticsearchException;
use Elastic\Transport\Exception\TransportException as OfficialTransportException;
use Psr\Http\Client\ClientExceptionInterface as PsrClientExceptionInterface;
use SllhSmile\Elasticsearch\Bulk\BulkManager;
use SllhSmile\Elasticsearch\Contract\ClientInterface;
use SllhSmile\Elasticsearch\Exception\ElasticsearchException;
use SllhSmile\Elasticsearch\Exception\ResponseException;
use SllhSmile\Elasticsearch\Exception\TransportException;
use SllhSmile\Elasticsearch\Index\IndexManager;
use Throwable;

/**
 * 官方 ES9 客户端的轻量适配器，提供常用 endpoint 和管理器入口。
 */
final class ElasticsearchClient implements ClientInterface
{
    /**
     * 官方客户端或一个延迟创建回调。
     *
     * Hyperf 会在 Worker 中缓存依赖对象；如果这里在 Worker 启动阶段就
     * 创建底层 HTTP 客户端，协程状态尚未建立，Handler 可能被错误固定为
     * 同步实现。因此工厂传入 Closure，第一次真正调用 endpoint 时才解析。
     */
    private object $client;

    private ?object $resolvedClient = null;

    /** @param object|\Closure $client 官方客户端实例或延迟创建回调。 */
    public function __construct(object $client)
    {
        $this->client = $client;
    }

    /** 解析并缓存官方客户端；同一连接后续调用复用同一个实例。 */
    private function resolve(): object
    {
        if ($this->resolvedClient !== null) {
            return $this->resolvedClient;
        }

        $client = $this->client instanceof \Closure ? ($this->client)() : $this->client;
        if (! is_object($client)) {
            throw new \UnexpectedValueException('Elasticsearch client factory must return an object.');
        }

        return $this->resolvedClient = $client;
    }

    /**
     * 执行一个官方 endpoint，并将官方客户端异常转换为本包异常。
     *
     * IndexManager/PitManager 等内部组件也通过此入口调用 endpoint，确保
     * 调用方可以统一捕获本包的 ResponseException/TransportException。
     * raw() 则是有意保留的官方客户端逃生舱，直接调用 raw() 的代码使用
     * 官方异常类型，不经过本适配层转换。
     */
    public function execute(callable $operation): mixed
    {
        try {
            return $operation($this->resolve());
        } catch (Throwable $exception) {
            throw $this->normalizeException($exception);
        }
    }

    /** 将官方 4xx/5xx、传输异常和其他官方异常归一化为包内类型。 */
    private function normalizeException(Throwable $exception): Throwable
    {
        if ($exception instanceof ElasticsearchException) {
            return $exception;
        }

        if ($exception instanceof OfficialClientResponseException || $exception instanceof OfficialServerResponseException) {
            $response = null;
            if (method_exists($exception, 'getResponse')) {
                try {
                    $response = $exception->getResponse();
                } catch (Throwable) {
                    $response = null;
                }
            }
            $statusCode = $response !== null && method_exists($response, 'getStatusCode')
                ? (int) $response->getStatusCode()
                : (int) $exception->getCode();
            return new ResponseException($exception->getMessage(), $statusCode, $response, $exception);
        }

        if ($exception instanceof OfficialTransportException || $exception instanceof PsrClientExceptionInterface) {
            return new TransportException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        if ($exception instanceof OfficialElasticsearchException) {
            return new ElasticsearchException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return new ElasticsearchException($exception->getMessage(), (int) $exception->getCode(), $exception);
    }

    /** GET 请求入口；path、query 和 body 的分派规则由私有 dispatch 统一处理。 */
    public function requestGet(string $path, array $query = [], array $options = []): mixed
    {
        return $this->dispatch('GET', $path, $query, null, $options);
    }

    /** POST 请求入口；JSON DSL 或 Bulk 行数据通过 body 传递。 */
    public function requestPost(string $path, array $query = [], ?array $body = null, array $options = []): mixed
    {
        return $this->dispatch('POST', $path, $query, $body, $options);
    }

    /** PUT 请求入口；索引、mapping 或文档内容通过 body 传递。 */
    public function requestPut(string $path, array $query = [], ?array $body = null, array $options = []): mixed
    {
        return $this->dispatch('PUT', $path, $query, $body, $options);
    }

    /** DELETE 请求入口；可选 body 供未来 endpoint 扩展。 */
    public function requestDelete(string $path, array $query = [], ?array $body = null, array $options = []): mixed
    {
        return $this->dispatch('DELETE', $path, $query, $body, $options);
    }

    /**
     * 按路径显式分派官方 endpoint；body 会以 `body` 参数传递。
     * 不支持的路由会抛出 BadMethodCallException，不会静默发送错误请求。
     */
    private function dispatch(string $method, string $path, array $query = [], ?array $body = null, array $options = []): mixed
    {
        $method = strtoupper($method);
        $normalized = '/' . trim($path, '/');
        $params = $query;
        if ($body !== null) {
            $params['body'] = $body;
        }

        if ($normalized === '/') {
            if ($method !== 'GET') {
                throw new \BadMethodCallException("Unsupported Elasticsearch request method [{$method} {$path}].");
            }
            return $this->execute(static fn (object $client): mixed => $client->info($params));
        }
        if (preg_match('#^/(.+)/_search$#', $normalized, $matches) === 1) {
            if (! in_array($method, ['GET', 'POST'], true)) {
                throw new \BadMethodCallException("Unsupported Elasticsearch request method [{$method} {$path}].");
            }
            return $this->execute(
                static fn (object $client): mixed => $client->search(
                    array_replace($params, ['index' => urldecode($matches[1])])
                )
            );
        }
        if ($normalized === '/_search') {
            if (! in_array($method, ['GET', 'POST'], true)) {
                throw new \BadMethodCallException("Unsupported Elasticsearch request method [{$method} {$path}].");
            }
            return $this->execute(static fn (object $client): mixed => $client->search($params));
        }
        if (preg_match('#^/(.+)/_mapping$#', $normalized, $matches) === 1) {
            if (! in_array($method, ['GET', 'PUT'], true)) {
                throw new \BadMethodCallException("Unsupported Elasticsearch request method [{$method} {$path}].");
            }
            $params['index'] = urldecode($matches[1]);
            return $this->execute(static fn (object $client): mixed => $method === 'GET'
                ? $client->indices()->getMapping($params)
                : $client->indices()->putMapping($params));
        }
        if (preg_match('#^/([^/]+)$#', $normalized, $matches) === 1 && in_array($method, ['PUT', 'DELETE'], true)) {
            $params['index'] = urldecode($matches[1]);
            return $this->execute(static fn (object $client): mixed => $method === 'PUT'
                ? $client->indices()->create($params)
                : $client->indices()->delete($params));
        }
        if ($normalized === '/_bulk' && $method === 'POST') {
            return $this->execute(static fn (object $client): mixed => $client->bulk($params));
        }
        if (preg_match('#^/(.+)/_doc/([^/]+)$#', $normalized, $matches) === 1) {
            if (! in_array($method, ['GET', 'PUT', 'POST', 'DELETE'], true)) {
                throw new \BadMethodCallException("Unsupported Elasticsearch request method [{$method} {$path}].");
            }
            $params['index'] = urldecode($matches[1]);
            $params['id'] = urldecode($matches[2]);
            return $this->execute(static fn (object $client) => match ($method) {
                'GET' => $client->get($params),
                'PUT', 'POST' => $client->index($params),
                'DELETE' => $client->delete($params),
                default => throw new \BadMethodCallException("Unsupported Elasticsearch document method [{$method}]."),
            });
        }
        if (preg_match('#^/(.+)/([^/]+)$#', $normalized, $matches) === 1) {
            if (! in_array($method, ['GET', 'PUT', 'POST', 'DELETE'], true)) {
                throw new \BadMethodCallException("Unsupported Elasticsearch request method [{$method} {$path}].");
            }
            $params['index'] = urldecode($matches[1]);
            $params['id'] = urldecode($matches[2]);
            return $this->execute(static fn (object $client) => match ($method) {
                'GET' => $client->get($params),
                'PUT', 'POST' => $client->index($params),
                'DELETE' => $client->delete($params),
                default => throw new \BadMethodCallException("Unsupported Elasticsearch document method [{$method}]."),
            });
        }
        throw new \BadMethodCallException("Unsupported Elasticsearch request route [{$method} {$path}].");
    }

    /** 返回官方客户端，用于尚未封装的 endpoint（例如 PIT）。 */
    public function raw(): object
    {
        return $this->resolve();
    }

    /** 转发 search endpoint；查询 DSL 应放入 params.body。 */
    public function search(array $params): mixed
    {
        return $this->execute(static fn (object $client): mixed => $client->search($params));
    }

    /** 转发 index endpoint。 */
    public function index(array $params): mixed
    {
        return $this->execute(static fn (object $client): mixed => $client->index($params));
    }

    /** 转发 create endpoint。 */
    public function create(array $params): mixed
    {
        return $this->execute(static fn (object $client): mixed => $client->create($params));
    }

    /** 转发 update endpoint。 */
    public function update(array $params): mixed
    {
        return $this->execute(static fn (object $client): mixed => $client->update($params));
    }

    /** 转发 delete endpoint。 */
    public function delete(array $params): mixed
    {
        return $this->execute(static fn (object $client): mixed => $client->delete($params));
    }

    /** 转发 bulk endpoint；body 由 BulkManager 按行组织。 */
    public function bulk(array $params): mixed
    {
        return $this->execute(static fn (object $client): mixed => $client->bulk($params));
    }

    /** 返回官方 indices endpoint 对象。 */
    public function indices(): mixed
    {
        return $this->resolve()->indices();
    }

    /** 创建索引管理器（管理器本身无额外网络状态）。 */
    public function indexManager(): IndexManager
    {
        return new IndexManager($this);
    }

    /** 创建指定分块大小的 Bulk 管理器。 */
    public function bulkManager(int $chunkSize = 500): BulkManager
    {
        return new BulkManager($this, $chunkSize);
    }

    /** 创建 PIT 生命周期管理器。 */
    public function pitManager(): PitManager
    {
        return new PitManager($this);
    }
}
