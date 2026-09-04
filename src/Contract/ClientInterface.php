<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Contract;

/**
 * 对外客户端契约，允许上层在不依赖官方客户端类型的情况下发起请求。
 */
interface ClientInterface
{
    /** 发送 GET 请求并按路径分派到官方 endpoint。 */
    public function requestGet(string $path, array $query = [], array $options = []): mixed;

    /** 发送 POST 请求并按路径分派到官方 endpoint。 */
    public function requestPost(string $path, array $query = [], ?array $body = null, array $options = []): mixed;

    /** 发送 PUT 请求并按路径分派到官方 endpoint。 */
    public function requestPut(string $path, array $query = [], ?array $body = null, array $options = []): mixed;

    /** 发送 DELETE 请求并按路径分派到官方 endpoint。 */
    public function requestDelete(string $path, array $query = [], ?array $body = null, array $options = []): mixed;
}
