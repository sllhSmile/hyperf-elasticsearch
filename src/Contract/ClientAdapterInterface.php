<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Contract;

use SllhSmile\Elasticsearch\Adapter\ClientCapabilities;
use Throwable;

/**
 * 统一官方 Elasticsearch PHP 客户端 7/8/9 的最小适配边界。
 */
interface ClientAdapterInterface
{
    /** 执行一个由 Core 定义的 endpoint operation。 */
    public function call(string $operation, array $params = []): mixed;

    /** 将官方数组/响应对象统一转换为数组。 */
    public function responseToArray(mixed $response): array;

    /** 将官方 exists 响应统一转换为 bool。 */
    public function responseToBool(mixed $response): bool;

    /** 返回当前客户端支持的协议能力。 */
    public function capabilities(): ClientCapabilities;

    /** 统一官方异常；已是包内异常时必须原样返回。 */
    public function normalizeException(Throwable $exception): Throwable;

    /** 返回官方客户端逃生入口。 */
    public function raw(): object;
}
