<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Adapter;

use Psr\Http\Client\ClientExceptionInterface as PsrClientExceptionInterface;
use SllhSmile\Elasticsearch\Contract\ClientAdapterInterface;
use SllhSmile\Elasticsearch\Exception\ElasticsearchException;
use SllhSmile\Elasticsearch\Exception\ResponseException;
use SllhSmile\Elasticsearch\Exception\TransportException;
use Throwable;

/**
 * 官方客户端 endpoint 的共同分派逻辑。
 *
 * ES7 返回数组，ES8/9 默认返回 Response 对象；版本 adapter 共享这里的
 * operation 映射，但通过 clientMajor 保留各版本能力边界。
 */
class OfficialClientAdapter implements ClientAdapterInterface
{
    /** 保存官方客户端及其主版本，供统一 endpoint 分派和能力声明使用。 */
    public function __construct(
        private readonly object $client,
        private readonly int $clientMajor = ClientMajor::ES9,
    )
    {
    }

    /** 将稳定 operation 名映射到不同版本官方客户端共有的 endpoint。 */
    public function call(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'info' => $this->client->info($params),
            'search' => $this->client->search($params),
            'get' => $this->client->get($params),
            'index' => $this->client->index($params),
            'create' => $this->client->create($params),
            'update' => $this->client->update($params),
            'delete' => $this->client->delete($params),
            'bulk' => $this->client->bulk($params),
            'indices.create' => $this->client->indices()->create($params),
            'indices.delete' => $this->client->indices()->delete($params),
            'indices.exists' => $this->client->indices()->exists($params),
            'indices.getMapping' => $this->client->indices()->getMapping($params),
            'indices.putMapping' => $this->client->indices()->putMapping($params),
            'indices.getSettings' => $this->client->indices()->getSettings($params),
            'indices.putSettings' => $this->client->indices()->putSettings($params),
            'indices.updateAliases' => $this->client->indices()->updateAliases($params),
            'pit.open' => $this->client->openPointInTime($params),
            'pit.close' => $this->client->closePointInTime($params),
            default => throw new \BadMethodCallException("Unsupported Elasticsearch operation [{$operation}]."),
        };
    }

    /** 将 ES7 数组或 ES8/9 响应对象统一转换为数组。 */
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
        return (array) $response;
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
        return (bool) $response;
    }

    /** 返回与当前官方客户端主版本关联的协议能力。 */
    public function capabilities(): ClientCapabilities
    {
        return new ClientCapabilities($this->clientMajor);
    }

    /** 将官方客户端和 PSR 传输异常归一化为包内稳定异常类型。 */
    public function normalizeException(Throwable $exception): Throwable
    {
        if ($exception instanceof ElasticsearchException) {
            return $exception;
        }

        // 先识别无可用节点、DNS 和 HTTP 传输故障，避免误归类为服务端响应错误。
        $transportClasses = $this->availableClasses([
            'Elastic\\Transport\\Exception\\TransportException',
            'Elastic\\Transport\\Exception\\NoNodeAvailableException',
            'Elasticsearch\\Common\\Exceptions\\NoNodesAvailableException',
        ]);
        foreach ($transportClasses as $class) {
            if ($exception instanceof $class) {
                return new TransportException($exception->getMessage(), (int) $exception->getCode(), $exception);
            }
        }
        if ($exception instanceof PsrClientExceptionInterface) {
            return new TransportException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        // 官方 7/8/9 的响应异常命名不同，但都保留状态码和原始响应供业务判断。
        $responseClasses = $this->availableClasses([
            'Elastic\\Elasticsearch\\Exception\\ClientResponseException',
            'Elastic\\Elasticsearch\\Exception\\ServerResponseException',
            'Elasticsearch\\Common\\Exceptions\\ClientErrorResponseException',
            'Elasticsearch\\Common\\Exceptions\\ServerErrorResponseException',
            'Elasticsearch\\Common\\Exceptions\\BadRequest400Exception',
            'Elasticsearch\\Common\\Exceptions\\Unauthorized401Exception',
            'Elasticsearch\\Common\\Exceptions\\Forbidden403Exception',
            'Elasticsearch\\Common\\Exceptions\\Missing404Exception',
            'Elasticsearch\\Common\\Exceptions\\Conflict409Exception',
        ]);
        foreach ($responseClasses as $class) {
            if (! $exception instanceof $class) {
                continue;
            }
            $response = null;
            if (method_exists($exception, 'getResponse')) {
                try {
                    $response = $exception->getResponse();
                } catch (Throwable) {
                }
            }
            $statusCode = $response !== null && method_exists($response, 'getStatusCode')
                ? (int) $response->getStatusCode()
                : (int) $exception->getCode();
            return new ResponseException($exception->getMessage(), $statusCode, $response, $exception);
        }

        return new ElasticsearchException($exception->getMessage(), (int) $exception->getCode(), $exception);
    }

    /** 返回底层官方客户端，供尚未封装的 endpoint 使用。 */
    public function raw(): object
    {
        return $this->client;
    }

    /**
     * 过滤当前依赖版本中实际存在的异常类型，避免跨版本 instanceof 触发无效引用。
     *
     * @param list<string> $classes
     *
     * @return list<string>
     */
    private function availableClasses(array $classes): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => class_exists($class) || interface_exists($class),
        ));
    }
}
