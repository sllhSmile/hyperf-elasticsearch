<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests\Support;

use Elastic\Elasticsearch\Client;
use SllhSmile\Elasticsearch\Adapter\OfficialClientAdapter;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Contract\ClientAdapterInterface;

/** 测试专用 Adapter：允许匿名对象记录 endpoint 参数，不放宽生产客户端类型。 */
final class ClientAdapterStub implements ClientAdapterInterface
{
    private static ?OfficialClientAdapter $normalizer = null;

    /** 保存测试替身，其方法形状由对应用例声明。 */
    public function __construct(private readonly object $client) {}

    /** 便利创建类型安全的客户端外观，避免每个用例重复包装。 */
    public static function client(object $raw): ElasticsearchClient
    {
        return ElasticsearchClient::fromClient(new self($raw));
    }

    /** @param array<string, mixed> $params */
    public function call(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'info', 'search', 'get', 'index', 'create', 'update', 'delete', 'bulk' =>
                $this->invoke($this->client, $operation, $params),
            'indices.create', 'indices.delete', 'indices.exists', 'indices.getMapping',
            'indices.putMapping', 'indices.getSettings', 'indices.putSettings', 'indices.updateAliases' =>
                $this->invoke($this->invoke($this->client, 'indices'), substr($operation, 8), $params),
            'pit.open' => $this->invoke($this->client, 'openPointInTime', $params),
            'pit.close' => $this->invoke($this->client, 'closePointInTime', $params),
            default => throw new \BadMethodCallException("Unsupported Elasticsearch operation [{$operation}]."),
        };
    }

    /** @return array<mixed> */
    public function responseToArray(mixed $response): array
    {
        return self::normalizer()->responseToArray($response);
    }

    /** 使用生产 Adapter 的严格布尔响应契约。 */
    public function responseToBool(mixed $response): bool
    {
        return self::normalizer()->responseToBool($response);
    }

    /** 使用生产 Adapter 的异常分类，避免测试替身复制一套规则。 */
    public function normalizeException(\Throwable $exception): \Throwable
    {
        return self::normalizer()->normalizeException($exception);
    }

    /** 返回替身供 execute() 测试高级 endpoint。 */
    public function raw(): object
    {
        return $this->client;
    }

    /**
     * 按 endpoint 名称调用替身，缺失方法时给出和生产 Adapter 一致的错误。
     *
     * @param array<string, mixed> $params
     */
    private function invoke(object $target, string $method, array $params = []): mixed
    {
        if (! method_exists($target, $method)) {
            throw new \BadMethodCallException("Official Elasticsearch client method [{$method}] is unavailable.");
        }
        return $params === [] && $method === 'indices'
            ? $target->{$method}()
            : $target->{$method}($params);
    }

    /** 无网络构造官方类型，仅供响应和异常纯函数逻辑使用。 */
    private static function normalizer(): OfficialClientAdapter
    {
        if (self::$normalizer === null) {
            $client = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
            self::$normalizer = new OfficialClientAdapter($client);
        }
        return self::$normalizer;
    }
}
