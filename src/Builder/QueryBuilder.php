<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Builder;

use Hyperf\Collection\Collection;
use SllhSmile\Elasticsearch\Client\PitManager;
use SllhSmile\Elasticsearch\Contract\ClientInterface;
use SllhSmile\Elasticsearch\Model\DocumentModel;
use SllhSmile\Elasticsearch\Response\SearchResponse;
use SllhSmile\Elasticsearch\Response\SearchHit;

/**
 * Laravel 风格链式查询构造器。方法只累积状态，search/get/first/count 才访问 ES。
 */
final class QueryBuilder
{
    /** @var list<array<string, mixed>> */
    private array $must = [];
    /** @var list<array<string, mixed>> */
    private array $filter = [];
    /** @var list<array<string, mixed>> */
    private array $mustNot = [];
    /** @var list<array<string, mixed>> */
    private array $should = [];
    private int|string|null $minimumShouldMatch = null;
    /** @var array<string, mixed> */
    private array $body = [];
    /** @var null|array<string, mixed> */
    private ?array $rawDsl = null;

    /** @param class-string<DocumentModel> $modelClass */
    public function __construct(private readonly ClientInterface $client, private readonly string $modelClass, private readonly string $index) {}

    /** 添加 term filter。 */
    public function where(string $field, mixed $value): self
    {
        $this->filter[] = ['term' => [$field => $value]];
        return $this;
    }

    /** @param array<mixed> $values */
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

    /** @param array<string, mixed> $options */
    public function whereMatch(string $field, string $value, array $options = []): self
    {
        $this->must[] = ['match' => [$field => array_replace(['query' => $value], $options)]];
        return $this;
    }

    /** @param array<string, mixed> $options */
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

    /** @param array<string, mixed> $options */
    public function wherePhrase(string $field, string $value, array $options = []): self
    {
        $this->must[] = ['match_phrase' => [$field => array_replace(['query' => $value], $options)]];
        return $this;
    }

    /** @param array<mixed> $range */
    public function whereBetween(string $field, array $range): self
    {
        if (count($range) !== 2) {
            throw new \InvalidArgumentException('whereBetween requires exactly two boundary values.');
        }
        [$from, $to] = array_pad(array_values($range), 2, null);
        $this->filter[] = ['range' => [$field => ['gte' => $from, 'lte' => $to]]];
        return $this;
    }

    /** @param array<string, mixed> $range */
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

    /** @param array<string, mixed> $options */
    public function orderBy(string $field, string $direction = 'asc', array $options = []): self
    {
        $direction = strtolower($direction);
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Sort direction must be asc or desc.');
        }
        // order 由 direction 统一控制，禁止 options 覆盖已校验的排序方向。
        $this->body['sort'][] = $options === [] ? [$field => $direction] : [$field => array_replace($options, ['order' => $direction])];
        return $this;
    }

    /** @param array<array-key, string> $fields */
    public function select(array $fields): self
    {
        $this->body['_source'] = ['includes' => array_values($fields)];
        return $this;
    }

    /**
     * 同时设置 _source includes/excludes。
     *
     * @param list<string> $includes
     * @param list<string> $excludes
     */
    public function source(array $includes = [], array $excludes = []): self
    {
        $this->body['_source'] = array_filter(['includes' => $includes, 'excludes' => $excludes], static fn(array $v): bool => $v !== []);
        return $this;
    }

    /** 设置浅分页起始偏移。 */
    public function from(int $from): self
    {
        if ($from < 0) {
            throw new \InvalidArgumentException('from cannot be negative.');
        }
        $this->body['from'] = $from;
        return $this;
    }

    /** 设置返回条数。 */
    public function size(int $size): self
    {
        if ($size < 0) {
            throw new \InvalidArgumentException('size cannot be negative.');
        }
        $this->body['size'] = $size;
        return $this;
    }

    /** size 的语义别名。 */
    public function limit(int $size): self
    {
        return $this->size($size);
    }

    /**
     * 配置高亮字段及 ES highlight 选项。
     *
     * @param list<string> $fields
     * @param array<string, mixed> $options
     */
    public function highlight(array $fields, array $options = []): self
    {
        $this->body['highlight'] = array_replace(['fields' => array_fill_keys($fields, (object) [])], $options);
        return $this;
    }

    /** @param array<string, mixed> $aggregation */
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

    /** @param array<mixed> $sortValues */
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

    /**
     * 合并原始 DSL 的对象字段，列表整体替换；不得覆盖链式 API 已生成的同名顶层字段。
     *
     * @param array<string, mixed> $dsl
     */
    public function rawDsl(array $dsl): self
    {
        if ($this->rawDsl === null) {
            $this->rawDsl = $dsl;
            return $this;
        }
        $this->rawDsl = self::mergeRawDsl($this->rawDsl, $dsl);
        return $this;
    }

    /**
     * 对象键递归合并，避免 PHP 按数字下标把两次传入的 sort/bool 子句拼成一个子句。
     *
     * @param array<mixed> $previous
     * @param array<mixed> $incoming
     * @return array<mixed>
     */
    private static function mergeRawDsl(array $previous, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (isset($previous[$key]) && is_array($previous[$key]) && is_array($value)
                && ! array_is_list($previous[$key]) && ! array_is_list($value)) {
                $previous[$key] = self::mergeRawDsl($previous[$key], $value);
                continue;
            }
            $previous[$key] = $value;
        }
        return $previous;
    }

    /**
     * 编译链式状态和原始片段，不访问 Elasticsearch。
     *
     * @return array<string, mixed>
     */
    public function toDsl(): array
    {
        $compiled = array_replace_recursive($this->body, []);
        if ($this->must !== [] || $this->filter !== [] || $this->mustNot !== [] || $this->should !== []) {
            $compiled['query'] = $this->compileQuery();
        }
        if ($this->rawDsl !== null) {
            foreach (array_intersect_key($compiled, $this->rawDsl) as $key => $value) {
                if ($this->rawDsl[$key] !== $value) {
                    throw new \InvalidArgumentException("rawDsl conflicts with generated DSL key [{$key}]; use rawDsl() on a fresh builder to provide a complete DSL.");
                }
            }
            $compiled = array_replace_recursive($compiled, $this->rawDsl);
        }
        return $compiled;
    }

    /** 将 DSL 放入请求体；PIT 已携带索引上下文，因此不得同时发送 index。 */
    public function search(): SearchResponse
    {
        $dsl = $this->toDsl();
        $params = ['body' => $dsl];
        if (! array_key_exists('pit', $dsl)) {
            $params['index'] = $this->index;
        }
        $response = $this->client->search($params);
        $raw = $this->client->responseToArray($response);
        return new SearchResponse($raw, $this->modelClass);
    }

    /** search 的语义别名。 */
    public function get(): SearchResponse
    {
        return $this->search();
    }

    /**
     * 通过模型 QueryBuilder 创建并写入文档。
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     */
    public function create(array $attributes, ?string $id = null, array $options = []): DocumentModel
    {
        return $this->modelClass::create($attributes, $id, $this->client, $options);
    }

    /**
     * 使用 PIT 和 search_after 按批遍历；回调返回严格的 false 时提前结束。
     * 已有 from/size 分别作为起始偏移和总量上限；不修改调用方的 Builder。
     *
     * @param callable(Collection<int, DocumentModel>, int): mixed $callback
     */
    public function chunk(int $count, callable $callback): bool
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('chunk count must be greater than zero.');
        }
        $dsl = $this->toDsl();
        if (isset($dsl['pit']) || isset($dsl['search_after'])) {
            throw new \InvalidArgumentException('chunk cannot be combined with an existing PIT or search_after cursor.');
        }
        $skip = $dsl['from'] ?? 0;
        $remaining = $dsl['size'] ?? null;
        if (! is_int($skip) || $skip < 0 || ($remaining !== null && (! is_int($remaining) || $remaining < 0))) {
            throw new \InvalidArgumentException('chunk requires non-negative integer from and size values.');
        }
        if ($remaining === 0) {
            return true;
        }
        unset($dsl['from'], $dsl['size']);
        // _shard_doc 在同一 PIT 内提供稳定的并列值顺序。
        $dsl['sort'] = array_merge((array) ($dsl['sort'] ?? []), [['_shard_doc' => 'asc']]);
        $pit = new PitManager($this->client);
        $pitId = $pit->open($this->index);
        $page = 1;
        $cursor = null;
        $lastSort = null;
        $failure = null;
        $result = true;
        try {
            while (true) {
                $body = $dsl;
                $body['size'] = $remaining === null ? $count : min($count, $remaining);
                $body['pit'] = ['id' => $pitId, 'keep_alive' => '1m'];
                if ($cursor !== null) {
                    $body['search_after'] = $cursor;
                }
                $raw = $this->client->responseToArray($this->client->search(['body' => $body]));
                if (isset($raw['pit_id']) && is_string($raw['pit_id']) && $raw['pit_id'] !== '') {
                    $pitId = $raw['pit_id'];
                }
                if (! isset($raw['hits']['hits']) || ! is_array($raw['hits']['hits']) || ! array_is_list($raw['hits']['hits'])) {
                    throw new \UnexpectedValueException('PIT search response did not contain a hits list.');
                }
                $hits = array_map(static function (SearchHit|DocumentModel $hit): DocumentModel {
                    if (! $hit instanceof DocumentModel) {
                        throw new \UnexpectedValueException('Model query returned a non-model search hit.');
                    }
                    return $hit;
                }, (new SearchResponse($raw, $this->modelClass))->hits());
                if ($hits === []) {
                    break;
                }
                $lastHit = $raw['hits']['hits'][array_key_last($raw['hits']['hits'])] ?? null;
                if (! is_array($lastHit) || ! isset($lastHit['sort']) || ! is_array($lastHit['sort']) || $lastHit['sort'] === []) {
                    throw new \UnexpectedValueException('PIT search response did not contain sort values for search_after.');
                }
                $cursor = array_values($lastHit['sort']);
                if ($cursor === $lastSort) {
                    throw new \UnexpectedValueException('PIT search_after cursor did not advance.');
                }
                $lastSort = $cursor;
                // 偏移只用于首段遍历；后续请求始终沿用服务端游标。
                if ($skip > 0) {
                    $seen = count($hits);
                    $hits = array_slice($hits, $skip);
                    $skip = max(0, $skip - $seen);
                    if ($hits === []) {
                        if ($seen < $body['size']) {
                            break;
                        }
                        continue;
                    }
                }
                if ($remaining !== null) {
                    $hits = array_slice($hits, 0, $remaining);
                    $remaining -= count($hits);
                }
                if ($callback(new Collection($hits), $page) === false) {
                    $result = false;
                    break;
                }
                $page++;
                if ($remaining === 0 || count($raw['hits']['hits']) < $body['size']) {
                    break;
                }
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        try {
            $pit->close($pitId);
        } catch (\Throwable $closeException) {
            if ($failure === null) {
                throw $closeException;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $result;
    }

    /**
     * create 的语义别名，适合显式表达写入动作。
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     */
    public function insert(array $attributes, ?string $id = null, array $options = []): DocumentModel
    {
        return $this->create($attributes, $id, $options);
    }

    /** 设置 size=1 搜索并返回首条命中。 */
    public function first(): SearchHit|DocumentModel|null
    {
        $query = clone $this;
        return $query->size(1)->search()->first();
    }

    /** 设置 size=0/track_total_hits 并返回精确总数；响应不是精确值时拒绝静默降级。 */
    public function count(): int
    {
        $query = clone $this;
        $total = $query->trackTotalHits(true)->size(0)->search()->total();
        if ($total === null || ! $total->isExact()) {
            throw new \UnexpectedValueException('Elasticsearch did not return an exact total for count().');
        }
        return $total->value;
    }

    /** @return array<string, mixed> */
    private function compileQuery(): array
    {
        return ['bool' => $this->compileBool()];
    }

    /** @return array<string, mixed> */
    private function compileBool(): array
    {
        $bool = array_filter([
            'must' => $this->must,
            'filter' => $this->filter,
            'must_not' => $this->mustNot,
            'should' => $this->should,
            'minimum_should_match' => $this->minimumShouldMatch,
        ], static fn(mixed $value): bool => $value !== [] && $value !== null);
        return $bool;
    }
}
