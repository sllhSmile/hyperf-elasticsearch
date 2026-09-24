<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Response;

use Countable;
use IteratorAggregate;
use SllhSmile\Elasticsearch\Model\DocumentModel;
use Traversable;

/**
 * 搜索结果集合，提供命中迭代、总数、聚合和原始响应访问。
 *
 * @implements IteratorAggregate<int, SearchHit|DocumentModel>
 */
final class SearchResponse implements Countable, IteratorAggregate
{
    /** @var list<SearchHit|DocumentModel> */
    private array $hitObjects;

    /**
     * 解析官方响应；传入模型类时将每个 hit 转为该模型。
     *
     * @param array<string, mixed> $raw
     * @param null|class-string<DocumentModel> $modelClass
     */
    public function __construct(private readonly array $raw, ?string $modelClass = null)
    {
        $hits = array_values(array_filter((array) ($raw['hits']['hits'] ?? []), 'is_array'));
        $parsed = array_map(static fn(array $hit): SearchHit => SearchHit::fromArray($hit), $hits);
        $this->hitObjects = $modelClass === null
            ? $parsed
            : array_map(static fn(SearchHit $hit): DocumentModel => $modelClass::fromSearchHit($hit), $parsed);
    }

    /**
     * 返回已转换为 SearchHit 或模型实例的命中列表。
     *
     * @return list<SearchHit|DocumentModel>
     */
    public function hits(): array
    {
        return $this->hitObjects;
    }

    /** 返回包含数值及精确性关系的 hits.total；未返回总数时为 null。 */
    public function total(): ?TotalHits
    {
        $hits = $this->raw['hits'] ?? null;
        if (! is_array($hits) || ! array_key_exists('total', $hits) || $hits['total'] === null) {
            return null;
        }

        $total = $hits['total'];
        if (is_int($total)) {
            if ($total < 0) {
                throw new \UnexpectedValueException('Elasticsearch hits.total value cannot be negative.');
            }
            return new TotalHits($total, TotalHitsRelation::Eq);
        }
        if (! is_array($total)
            || ! isset($total['value'], $total['relation'])
            || ! is_int($total['value'])
            || ! is_string($total['relation'])) {
            throw new \UnexpectedValueException('Elasticsearch hits.total must contain an integer value and a relation.');
        }
        if ($total['value'] < 0) {
            throw new \UnexpectedValueException('Elasticsearch hits.total value cannot be negative.');
        }

        $relation = TotalHitsRelation::tryFrom($total['relation']);
        if ($relation === null) {
            throw new \UnexpectedValueException("Unsupported Elasticsearch hits.total relation [{$total['relation']}].");
        }
        return new TotalHits($total['value'], $relation);
    }

    /** 返回本页最大相关性分数。 */
    public function maxScore(): ?float
    {
        return isset($this->raw['hits']['max_score']) ? (float) $this->raw['hits']['max_score'] : null;
    }

    /** @return array<string, mixed> */
    public function aggregations(): array
    {
        return (array) ($this->raw['aggregations'] ?? []);
    }

    /** @return array<string, mixed> */
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
    public function first(): SearchHit|DocumentModel|null
    {
        return $this->hitObjects[0] ?? null;
    }
}
