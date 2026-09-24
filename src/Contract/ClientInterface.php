<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Contract;

/**
 * 对外客户端契约，允许上层在不依赖官方客户端类型的情况下发起请求。
 */
interface ClientInterface
{
    /**
     * 调用一个受支持的官方 endpoint，并可能立即发起网络请求。
     *
     * operation 支持 info、search、get、index、create、update、delete、bulk、
     * indices.create/delete/exists/getMapping/putMapping/getSettings/putSettings/
     * updateAliases，以及 pit.open/pit.close。params 使用官方客户端的关联数组结构；
     * 写操作的 body 必须是可由官方客户端编码的数组。返回值保留当前官方客户端形态：
     * SDK 8/9 通常为 Response 对象，可用 responseToArray/Bool 归一化。
     *
     * @throws \BadMethodCallException                                   operation 不受支持时抛出
     * @throws \SllhSmile\Elasticsearch\Exception\ResponseException      服务端返回 HTTP 错误时抛出
     * @throws \SllhSmile\Elasticsearch\Exception\TransportException     网络或节点传输失败时抛出
     * @throws \SllhSmile\Elasticsearch\Exception\ElasticsearchException 其他官方客户端错误时抛出
     * @param array<string, mixed> $params
     */
    public function call(string $operation, array $params = []): mixed;

    /**
     * 使用官方客户端执行尚未封装的 endpoint，并统一转换异常。
     *
     * @param callable(object): mixed $operation
     */
    public function execute(callable $operation): mixed;

    /** @param array<string, mixed> $params */
    public function info(array $params = []): mixed;

    /** @param array<string, mixed> $params */
    public function search(array $params): mixed;

    /** @param array<string, mixed> $params */
    public function get(array $params): mixed;

    /** @param array<string, mixed> $params */
    public function index(array $params): mixed;

    /** @param array<string, mixed> $params */
    public function create(array $params): mixed;

    /** @param array<string, mixed> $params */
    public function update(array $params): mixed;

    /** @param array<string, mixed> $params */
    public function delete(array $params): mixed;

    /** @param array<string, mixed> $params */
    public function bulk(array $params): mixed;

    /** @return array<mixed> */
    public function responseToArray(mixed $response): array;

    /** 将 exists 等响应统一转换为布尔值。 */
    public function responseToBool(mixed $response): bool;

}
