<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Exception;

use SllhSmile\Elasticsearch\Bulk\BulkResult;
use Throwable;

/** 分块 Bulk 中断；失败块的提交状态未知，仅 completedResult 可视为已确认。 */
class BulkExecutionException extends ElasticsearchException
{
    /** 保存已完成块、从 1 开始的失败块序号和原始失败原因。 */
    public function __construct(
        public readonly BulkResult $completedResult,
        public readonly int $failedChunk,
        Throwable $previous,
    ) {
        parent::__construct('Bulk execution failed at chunk ' . $failedChunk . '.', 0, $previous);
    }
}
