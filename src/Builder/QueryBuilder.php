<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Builder;

use Closure;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Contract\BuilderInterface;
use SllhSmile\Elasticsearch\Response\SearchResponse;

/**
 * Laravel 风格链式查询构造器。方法只累积状态，search/get/first/count 才访问 ES。
 */
final class QueryBuilder implements BuilderInterface
{
    private array $must = [];
    private array $filter = [];
    private array $mustNot = [];
    private array $should = [];
    private int|string|null $minimumShouldMatch = null;
    private array $body = [];
    private ?array $rawDsl = null;

    /** 绑定客户端、模型类和索引名；构造过程不访问网络。 */
    public function __construct(private readonly ElasticsearchClient $client, private readonly string $modelClass, private readonly string $index)
    {
    }

    /** 添加 term filter。 */
    public function where(string $field, mixed $value): self
    {
        $this->filter[] = ['term' => [$field => $value]];
        return $this;
    }

    /** 添加 terms filter。 */
    public function whereIn(string $field, array $values): self
    {
        $this->filter[] = ['terms' => [$field => array_values($values)]];
        return $this;
    }

    /** 添加 must_not term。 */
    public function whereNot(string $field, mixed $value): self
    {
        $this->mustNot[] = ['term' => [$field => $value]];
        return $this;
    }

    /** 添加全文 match must，可通过 options 传入 operator 等 ES 参数。 */
    public function whereMatch(string $field, string $value, array $options = []): self
    {
        $this->must[] = ['match' => [$field => array_replace(['query' => $value], $options)]];
        return $this;
    }

    /** whereMatch 的语义别名。 */
    public function mustMatch(string $field, string $value, array $options = []): self
    {
        return $this->whereMatch($field, $value, $options);
    }

    /** where 的语义别名。 */
    public function filterTerm(string $field, mixed $value): self
    {
        return $this->where($field, $value);
    }

    /** whereNot 的语义别名。 */
    public function mustNotTerm(string $field, mixed $value): self
    {
        return $this->whereNot($field, $value);
    }

    /** 添加 match_phrase must。 */
    public function wherePhrase(string $field, string $value, array $options = []): self
    {
        $this->must[] = ['match_phrase' => [$field => array_replace(['query' => $value], $options)]];
        return $this;
    }

    /** 用 gte/lte 添加闭区间 range filter。 */
    public function whereBetween(string $field, array $range): self
    {
        [$from, $to] = array_pad(array_values($range), 2, null);
        $this->filter[] = ['range' => [$field => ['gte' => $from, 'lte' => $to]]];
        return $this;
    }

    /** 添加完整 range filter（支持 gt/gte/lt/lte）。 */
    public function whereRange(string $field, array $range): self
    {
        $this->filter[] = ['range' => [$field => $range]];
        return $this;
    }

    /** 添加 exists filter。 */
    public function whereExists(string $field): self
    {
        $this->filter[] = ['exists' => ['field' => $field]];
        return $this;
    }

    /** 添加 prefix filter。 */
    public function wherePrefix(string $field, string $value): self
    {
        $this->filter[] = ['prefix' => [$field => $value]];
        return $this;
    }

    /** 添加 wildcard filter。 */
    public function whereWildcard(string $field, string $value): self
    {
        $this->filter[] = ['wildcard' => [$field => $value]];
        return $this;
    }

    /** 在 nested path 中编译独立子查询。 */
    public function whereNested(string $path, callable $callback): self
    {
        $nested = new self($this->client, $this->modelClass, $this->index);
        $callback($nested);
        $this->filter[] = ['nested' => ['path' => $path, 'query' => $nested->compileQuery()]];
        return $this;
    }

    /** 编译 bool 子句并放入 must。 */
    public function whereBool(callable $callback): self
    {
        $nested = new self($this->client, $this->modelClass, $this->index);
        $callback($nested);
        $this->must[] = ['bool' => $nested->compileBool()];
        return $this;
    }

    /** 添加 should 子查询。 */
    public function should(callable $callback): self
    {
        $nested = new self($this->client, $this->modelClass, $this->index);
        $callback($nested);
        $this->should[] = $nested->compileQuery();
        return $this;
    }

    /** 设置 minimum_should_match；保留 ES 支持的百分比/条件字符串。 */
    public function minimumShouldMatch(int|string $value): self
    {
        if (is_string($value) && trim($value) === '') {
            throw new \InvalidArgumentException('minimum_should_match cannot be empty.');
        }
        if (is_int($value) && $value < 0) {
            throw new \InvalidArgumentException('minimum_should_match cannot be negative.');
        }
        $this->minimumShouldMatch = $value;
        return $this;
    }

    /** 添加排序；方向仅允许 asc/desc。 */
    public function orderBy(string $field, string $direction = 'asc', array $options = []): self
    {
        $direction = strtolower($direction);
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Sort direction must be asc or desc.');
        }
        $this->body['sort'][] = $options === [] ? [$field => $direction] : [$field => array_replace(['order' => $direction], $options)];
        return $this;
    }

    /** 设置 _source includes。 */
    public function select(array $fields): self
    {
        $this->body['_source'] = ['includes' => array_values($fields)];
        return $this;
    }

    /** 同时设置 _source includes/excludes。 */
    public function source(array $includes = [], array $excludes = []): self
    {
        $this->body['_source'] = array_filter(['includes' => $includes, 'excludes' => $excludes], static fn (array $v): bool => $v !== []);
        return $this;
    }

    /** 设置浅分页起始偏移。 */
    public function from(int $from): self
    {
        $this->body['from'] = max(0, $from);
        return $this;
    }

    /** 设置返回条数。 */
    public function size(int $size): self
    {
        $this->body['size'] = max(0, $size);
        return $this;
    }

    /** size 的语义别名。 */
    public function limit(int $size): self
    {
        return $this->size($size);
    }

    /** 配置高亮字段及 ES highlight 选项。 */
    public function highlight(array $fields, array $options = []): self
    {
        $this->body['highlight'] = array_replace(['fields' => array_fill_keys($fields, (object) [])], $options);
        return $this;
    }

    /** 添加命名聚合。 */
    public function aggs(string $name, array $aggregation): self
    {
        $this->body['aggs'][$name] = $aggregation;
        return $this;
    }

    /** 配置 hits.total 精确统计或阈值。 */
    public function trackTotalHits(bool|int $value = true): self
    {
        $this->body['track_total_hits'] = $value;
        return $this;
    }

    /** 设置 search_after 深分页游标。 */
    public function searchAfter(array $sortValues): self
    {
        $this->body['search_after'] = array_values($sortValues);
        return $this;
    }

    /** 将 PIT id/keep_alive 写入查询 body。 */
    public function pit(string $id, string $keepAlive = '1m'): self
    {
        $this->body['pit'] = ['id' => $id, 'keep_alive' => $keepAlive];
        return $this;
    }

    /** 递归合并一段原始 DSL。 */
    public function rawDsl(array $dsl): self
    {
        $this->rawDsl = array_replace_recursive($this->rawDsl ?? [], $dsl);
        return $this;
    }

    /** 完整替换原始 DSL，覆盖此前 rawDsl。 */
    public function replaceDsl(array $dsl): self
    {
        $this->rawDsl = $dsl;
        return $this;
    }

    /** 编译最终 DSL；查询参数和 body 的分界在 search 中处理。 */
    public function toDsl(): array
    {
        $compiled = array_replace_recursive($this->body, []);
        if ($this->must !== [] || $this->filter !== [] || $this->mustNot !== [] || $this->should !== []) {
            $compiled['query'] = $this->compileQuery();
        }
        if ($this->rawDsl !== null) {
            $compiled = array_replace_recursive($compiled, $this->rawDsl);
        }
        return $compiled;
    }

    /** 将 DSL 放入 ES9 body 发起搜索并解析为 SearchResponse。 */
    public function search(): SearchResponse
    {
        $response = $this->client->search(['index' => $this->index, 'body' => $this->toDsl()]);
        $raw = is_object($response) && method_exists($response, 'asArray') ? $response->asArray() : (is_object($response) && method_exists($response, 'toArray') ? $response->toArray() : (array) $response);
        return new SearchResponse($raw, $this->modelClass);
    }

    /** search 的语义别名。 */
    public function get(): SearchResponse
    {
        return $this->search();
    }

    /** 设置 size=1 搜索并返回首条命中。 */
    public function first(): ?object
    {
        return $this->size(1)->search()->first();
    }

    /** 设置 size=0/track_total_hits 并返回匹配总数。 */
    public function count(): int
    {
        return $this->trackTotalHits(true)->size(0)->search()->total();
    }

    /** 将 bool 条件包装为 query.bool 节点。 */
    private function compileQuery(): array
    {
        return ['bool' => $this->compileBool()];
    }

    /** 编译 must/filter/must_not/should 和 minimum_should_match。 */
    private function compileBool(): array
    {
        $bool = array_filter([
            'must' => $this->must,
            'filter' => $this->filter,
            'must_not' => $this->mustNot,
            'should' => $this->should,
            'minimum_should_match' => $this->minimumShouldMatch,
        ], static fn (mixed $value): bool => $value !== [] && $value !== null);
        return $bool;
    }
}
