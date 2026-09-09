<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Contract;

use SllhSmile\Elasticsearch\Adapter\ClientCapabilities;

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
     * ES7 通常为数组，ES8/9 通常为 Response 对象，可用 responseToArray/Bool 归一化。
     *
     * @throws \BadMethodCallException operation 不受支持时抛出
     * @throws \SllhSmile\Elasticsearch\Exception\ResponseException 服务端返回 HTTP 错误时抛出
     * @throws \SllhSmile\Elasticsearch\Exception\TransportException 网络或节点传输失败时抛出
     * @throws \SllhSmile\Elasticsearch\Exception\ElasticsearchException 其他官方客户端错误时抛出
     */
    public function call(string $operation, array $params = []): mixed;

    public function search(array $params): mixed;

    public function get(array $params): mixed;

    public function index(array $params): mixed;

    public function create(array $params): mixed;

    public function update(array $params): mixed;

    public function delete(array $params): mixed;

    public function bulk(array $params): mixed;

    public function responseToArray(mixed $response): array;

    public function responseToBool(mixed $response): bool;

    public function capabilities(): ClientCapabilities;

    /** 发送 GET 请求并按路径分派到官方 endpoint。 */
    public function requestGet(string $path, array $query = [], array $options = []): mixed;

    /** 发送 POST 请求并按路径分派到官方 endpoint。 */
    public function requestPost(string $path, array $query = [], ?array $body = null, array $options = []): mixed;

    /** 发送 PUT 请求并按路径分派到官方 endpoint。 */
    public function requestPut(string $path, array $query = [], ?array $body = null, array $options = []): mixed;

    /** 发送 DELETE 请求并按路径分派到官方 endpoint。 */
    public function requestDelete(string $path, array $query = [], ?array $body = null, array $options = []): mixed;
}
