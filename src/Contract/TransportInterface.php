<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Contract;

/**
 * 低层传输契约，约定 HTTP 方法、路径、查询参数和 JSON body 的形状。
 */
interface TransportInterface
{
    /**
     * 发送一次请求；具体实现负责协程调度、序列化和异常转换。
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $options = []): mixed;
}
