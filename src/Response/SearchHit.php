<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Response;

/** 单条搜索命中，保留 source 与 ES 返回的元数据。 */
final class SearchHit
{
    /**
     * 使用解析后的 source 和 hit 元数据创建只读命中对象。
     *
     * @param array<string, mixed> $source
     * @param list<mixed> $sort
     * @param array<string, mixed> $highlight
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly array $source,
        public readonly ?string $id = null,
        public readonly ?float $score = null,
        public readonly array $sort = [],
        public readonly array $highlight = [],
        public readonly array $raw = [],
    ) {}

    /** @param array<string, mixed> $hit */
    public static function fromArray(array $hit): self
    {
        return new self(
            source: (array) ($hit['_source'] ?? []),
            id: isset($hit['_id']) ? (string) $hit['_id'] : null,
            score: isset($hit['_score']) ? (float) $hit['_score'] : null,
            sort: array_values((array) ($hit['sort'] ?? [])),
            highlight: (array) ($hit['highlight'] ?? []),
            raw: $hit,
        );
    }
}
