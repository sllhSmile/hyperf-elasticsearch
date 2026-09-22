<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Bulk;

/** Bulk 请求的汇总结果，包含逐项响应、错误数和分块原始响应。 */
final class BulkResult
{
    /** 保存合并后的逐项结果、失败项数量以及每个分块的原始响应。 */
    public function __construct(public readonly array $items, public readonly int $errors, public readonly array $raw = [])
    {
    }

    /** 判断是否至少有一个 item 失败。 */
    public function hasErrors(): bool
    {
        return $this->errors > 0;
    }
}
