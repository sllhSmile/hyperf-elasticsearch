<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Contract;

use Throwable;

/**
 * 统一官方 Elasticsearch PHP 客户端 8/9 的最小适配边界。
 */
interface ClientAdapterInterface
{
    /** @param array<string, mixed> $params */
    public function call(string $operation, array $params = []): mixed;

    /** @return array<mixed> */
    public function responseToArray(mixed $response): array;

    /** 将官方 exists 响应统一转换为 bool。 */
    public function responseToBool(mixed $response): bool;

    /** 统一官方异常；已是包内异常时必须原样返回。 */
    public function normalizeException(Throwable $exception): Throwable;

    /** 返回官方客户端逃生入口。 */
    public function raw(): object;
}
