<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Response;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * 搜索结果集合，提供命中迭代、总数、聚合和原始响应访问。
 *
 * @implements IteratorAggregate<int, object>
 */
final class SearchResponse implements Countable, IteratorAggregate
{
    /** @var list<SearchHit> */
    private array $hitObjects;

    /** 解析官方响应；传入模型类时将每个 hit 转为该模型。 */
    public function __construct(private readonly array $raw, ?string $modelClass = null)
    {
        $this->hitObjects = array_map(static fn (array $hit): SearchHit => SearchHit::fromArray($hit), (array) ($raw['hits']['hits'] ?? []));
        if ($modelClass !== null && is_a($modelClass, \SllhSmile\Elasticsearch\Model\DocumentModel::class, true)) {
            foreach ($this->hitObjects as $key => $hit) {
                $this->hitObjects[$key] = $modelClass::fromSearchHit($hit);
            }
        }
    }

    /**
     * 返回已转换为 SearchHit 或模型实例的命中列表。
     *
     * @return list<object>
     */
    public function hits(): array
    {
        return $this->hitObjects;
    }

    /** 返回 ES hits.total（支持 integer 和 value/relation 对象格式）。 */
    public function total(): int
    {
        $total = $this->raw['hits']['total'] ?? 0;
        return is_array($total) ? (int) ($total['value'] ?? 0) : (int) $total;
    }

    /** 返回本页最大相关性分数。 */
    public function maxScore(): ?float
    {
        return isset($this->raw['hits']['max_score']) ? (float) $this->raw['hits']['max_score'] : null;
    }

    /** 返回聚合结果，不存在时为空数组。 */
    public function aggregations(): array
    {
        return (array) ($this->raw['aggregations'] ?? []);
    }

    /** 返回官方客户端解析后的完整响应数组。 */
    public function raw(): array
    {
        return $this->raw;
    }

    /** Countable 实现：返回当前页命中数量。 */
    public function count(): int
    {
        return count($this->hitObjects);
    }

    /** 允许 foreach 遍历当前页命中。 */
    public function getIterator(): Traversable
    {
        yield from $this->hitObjects;
    }

    /** 返回当前页第一条命中，没有命中时返回 null。 */
    public function first(): ?object
    {
        return $this->hitObjects[0] ?? null;
    }
}
