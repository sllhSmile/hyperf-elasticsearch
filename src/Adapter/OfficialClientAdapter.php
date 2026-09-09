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
    public function __construct(
        private readonly object $client,
        private readonly int $clientMajor = ClientMajor::ES9,
    )
    {
    }

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

    public function capabilities(): ClientCapabilities
    {
        return new ClientCapabilities($this->clientMajor);
    }

    public function normalizeException(Throwable $exception): Throwable
    {
        if ($exception instanceof ElasticsearchException) {
            return $exception;
        }

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

    public function raw(): object
    {
        return $this->client;
    }

    /** @param list<string> $classes @return list<string> */
    private function availableClasses(array $classes): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => class_exists($class) || interface_exists($class),
        ));
    }
}
