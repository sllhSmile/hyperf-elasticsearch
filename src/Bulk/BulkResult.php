<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Bulk;

/** Bulk 请求的汇总结果，包含逐项响应、错误数和分块原始响应。 */
final class BulkResult
{
    public function __construct(public readonly array $items, public readonly int $errors, public readonly array $raw = [])
    {
    }

    /** 判断是否至少有一个 item 失败。 */
    public function hasErrors(): bool
    {
        return $this->errors > 0;
    }
}
