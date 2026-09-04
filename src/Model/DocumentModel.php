<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Model;

use DateTimeInterface;
use Hyperf\Context\ApplicationContext;
use SllhSmile\Elasticsearch\Builder\QueryBuilder;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Hyperf\Manager;
use SllhSmile\Elasticsearch\Response\SearchHit;

/**
 * 面向 Elasticsearch 文档的轻量模型基类，不依赖 Laravel/Eloquent。
 * 子类声明 index 和 casts，即可获得属性转换、命中元数据及 QueryBuilder 入口。
 */
abstract class DocumentModel
{
    protected string $index = '';

    /** 当前模型使用的连接名，默认读取 elasticsearch.connections.default。 */
    protected string $connection = 'default';

    protected string $idField = '_id';

    protected array $casts = [];

    protected array $attributes = [];

    protected bool $exists = false;

    protected ?string $documentId = null;

    protected ?float $score = null;

    protected array $sortValues = [];

    protected array $highlight = [];

    /** @var array<class-string, ElasticsearchClient> */
    private static array $clients = [];

    /** 创建模型并填充初始属性；不会访问网络。 */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /** 返回模型绑定的 ES 索引名。 */
    public function getIndexName(): string
    {
        if ($this->index === '') {
            throw new \LogicException('Document model must define a non-empty index.');
        }
        return $this->index;
    }

    /** 返回模型声明的连接名；空值时统一回退到 default。 */
    public function getConnectionName(): string
    {
        return $this->connection !== '' ? $this->connection : 'default';
    }

    /** 返回搜索命中的文档 id。 */
    public function getKey(): ?string
    {
        return $this->documentId;
    }

    /** 判断模型是否来自 ES 命中，而非新建对象。 */
    public function exists(): bool
    {
        return $this->exists;
    }

    /** 返回命中相关性分数。 */
    public function getScore(): ?float
    {
        return $this->score;
    }

    /** 返回 search_after 所需的排序值。 */
    public function getSortValues(): array
    {
        return $this->sortValues;
    }

    /** 返回字段高亮片段。 */
    public function getHighlight(): array
    {
        return $this->highlight;
    }

    /** 批量填充属性并执行入站 casts。 */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[(string) $key] = $this->castInbound((string) $key, $value);
        }
        return $this;
    }

    /** 设置单个属性并执行入站 casts。 */
    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $this->castInbound($key, $value);
        return $this;
    }

    /** 读取属性，不存在时返回默认值。 */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** 导出属性并执行出站 casts，适合返回 API。 */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->attributes as $key => $value) {
            $result[$key] = $this->castOutbound((string) $key, $value);
        }
        return $result;
    }

    /** 导出写入 ES 的文档 body。 */
    public function toDocument(): array
    {
        return $this->toArray();
    }

    /** 子类可覆盖以声明索引 mapping。 */
    public function mapping(): array
    {
        return [];
    }

    /** 子类可覆盖以声明索引 settings。 */
    public function settings(): array
    {
        return [];
    }

    /** 按具体模型类保存默认客户端，避免不同模型相互覆盖。 */
    public static function setClient(ElasticsearchClient $client): void
    {
        self::$clients[static::class] = $client;
    }

    /** 创建绑定当前模型索引的 QueryBuilder；传入 client 可覆盖静态默认值。 */
    public static function query(?ElasticsearchClient $client = null): QueryBuilder
    {
        $model = new static();
        $client ??= self::$clients[static::class] ?? null;
        if ($client === null) {
            $client = self::resolveClient($model->getConnectionName());
        }
        if ($client === null) {
            throw new \LogicException('No Elasticsearch client has been configured for the model.');
        }
        return new QueryBuilder($client, static::class, $model->getIndexName());
    }

    /**
     * 从 Hyperf 当前应用容器解析连接客户端。
     * 该路径只在模型未显式传入客户端且未调用 setClient 时执行。
     */
    private static function resolveClient(string $connection): ?ElasticsearchClient
    {
        if (! ApplicationContext::hasContainer()) {
            return null;
        }

        $container = ApplicationContext::getContainer();
        if (! $container->has(Manager::class)) {
            return null;
        }

        return $container->get(Manager::class)->connection($connection);
    }

    /** 将 SearchHit 转换为已存在模型并复制 score/sort/highlight 元数据。 */
    public static function fromSearchHit(SearchHit $hit): static
    {
        $model = new static($hit->source);
        $model->exists = true;
        $model->documentId = $hit->id;
        $model->score = $hit->score;
        $model->sortValues = $hit->sort;
        $model->highlight = $hit->highlight;
        return $model;
    }

    /** 通过魔术属性读取 attributes，等价于 getAttribute。 */
    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    /** 通过魔术属性写入 attributes，等价于 setAttribute。 */
    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    /** 支持 isset($model->field) 语法。 */
    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    /** 根据 casts 将 ES/调用方输入转换为 PHP 属性值。 */
    protected function castInbound(string $key, mixed $value): mixed
    {
        $cast = $this->casts[$key] ?? null;
        return match ($cast) {
            'int', 'integer' => $value === null ? null : (int) $value,
            'float', 'double' => $value === null ? null : (float) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => is_array($value) ? $value : (array) $value,
            'datetime' => $value instanceof DateTimeInterface ? $value : new \DateTimeImmutable((string) $value),
            default => $value,
        };
    }

    /** 根据 casts 将属性转换为可 JSON 化的文档值。 */
    protected function castOutbound(string $key, mixed $value): mixed
    {
        if (($this->casts[$key] ?? null) === 'datetime' && $value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        return $value;
    }
}
