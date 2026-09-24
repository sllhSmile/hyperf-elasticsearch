<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Response;

/** 将 Elasticsearch 的总命中值与精确性关系绑定为不可变结果。 */
final class TotalHits
{
    /** 保存非负命中数及其与真实总数的关系。 */
    public function __construct(public readonly int $value, public readonly TotalHitsRelation $relation)
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Total hits value cannot be negative.');
        }
    }

    /** 判断 value 是否为精确总数，而不是命中数下界。 */
    public function isExact(): bool
    {
        return $this->relation === TotalHitsRelation::Eq;
    }
}
