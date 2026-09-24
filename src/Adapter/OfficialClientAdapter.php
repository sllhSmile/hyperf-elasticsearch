<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Adapter;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ElasticsearchException as OfficialElasticsearchException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Elastic\Transport\Exception\TransportException as OfficialTransportException;
use Psr\Http\Client\ClientExceptionInterface as PsrClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use SllhSmile\Elasticsearch\Contract\ClientAdapterInterface;
use SllhSmile\Elasticsearch\Exception\ElasticsearchException;
use SllhSmile\Elasticsearch\Exception\ResponseException;
use SllhSmile\Elasticsearch\Exception\TransportException;
use Throwable;

/**
 * 官方客户端 endpoint 的共同分派逻辑。
 *
 * SDK 8/9 共用 endpoint、响应与异常契约，无需运行时版本分支。
 */
final class OfficialClientAdapter implements ClientAdapterInterface
{
    /** 保存类型明确的官方客户端，避免任意 object 鸭子类型延迟失败。 */
    public function __construct(private readonly Client $client) {}

    /**
     * 将稳定 operation 名映射到不同版本官方客户端共有的 endpoint。
     *
     * @param array<string, mixed> $params
     */
    public function call(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'info', 'search', 'get', 'index', 'create', 'update', 'delete', 'bulk' =>
                $this->invoke($this->client, $operation, $params),
            'indices.create', 'indices.delete', 'indices.exists', 'indices.getMapping',
            'indices.putMapping', 'indices.getSettings', 'indices.putSettings', 'indices.updateAliases' =>
                $this->invoke($this->client->indices(), substr($operation, 8), $params),
            'pit.open' => $this->invoke($this->client, 'openPointInTime', $params),
            'pit.close' => $this->invoke($this->client, 'closePointInTime', $params),
            default => throw new \BadMethodCallException("Unsupported Elasticsearch operation [{$operation}]."),
        };
    }

    /** @return array<mixed> */
    public function responseToArray(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }
        if (is_object($response)) {
            foreach (['asArray', 'toArray'] as $method) {
                if (method_exists($response, $method)) {
                    return (array) $response->{$method}();
                }
            }
        }
        throw new \UnexpectedValueException(sprintf(
            'Elasticsearch response [%s] cannot be converted to an array.',
            get_debug_type($response),
        ));
    }

    /** 将各版本 exists 响应统一转换为布尔值。 */
    public function responseToBool(mixed $response): bool
    {
        if (is_bool($response)) {
            return $response;
        }
        if (is_object($response) && method_exists($response, 'asBool')) {
            return $response->asBool();
        }
        throw new \UnexpectedValueException(sprintf(
            'Elasticsearch response [%s] cannot be converted to a boolean.',
            get_debug_type($response),
        ));
    }

    /** 将官方客户端和 PSR 传输异常归一化为包内稳定异常类型。 */
    public function normalizeException(Throwable $exception): Throwable
    {
        if ($exception instanceof ElasticsearchException) {
            return $exception;
        }

        // 只有确定的网络或节点故障才允许触发客户端重建。
        if ($exception instanceof NoNodeAvailableException
            || $exception instanceof NetworkExceptionInterface) {
            return new TransportException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        // 官方响应异常保留状态码和原始响应，供业务判断客户端或服务端错误。
        if ($exception instanceof ClientResponseException || $exception instanceof ServerResponseException) {
            $response = null;
            try {
                $response = $exception->getResponse();
            } catch (Throwable) {
            }
            $statusCode = $response !== null ? $response->getStatusCode() : (int) $exception->getCode();
            return new ResponseException($exception->getMessage(), $statusCode, $response, $exception);
        }

        if ($exception instanceof OfficialElasticsearchException
            || $exception instanceof OfficialTransportException
            || $exception instanceof PsrClientExceptionInterface) {
            return new ElasticsearchException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        // 业务回调和包内编程错误必须保持原始类型，避免隐藏真实调用栈语义。
        return $exception;
    }

    /** 返回底层官方客户端，供尚未封装的 endpoint 使用。 */
    public function raw(): Client
    {
        return $this->client;
    }

    /**
     * 在已由 match 白名单约束的前提下调用官方生成 endpoint。
     *
     * 官方 SDK 为每个 endpoint 生成不同 array-shape，而本包公共契约保留通用 DSL 数组，
     * 因此统一分派必须在此动态调用。
     *
     * @param array<string, mixed> $params
     */
    private function invoke(object $target, string $method, array $params): mixed
    {
        return $target->{$method}($params);
    }
}
