<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Model;

use DateTimeInterface;
use Hyperf\Context\ApplicationContext;
use SllhSmile\Elasticsearch\Builder\QueryBuilder;
use SllhSmile\Elasticsearch\Contract\ClientInterface;
use SllhSmile\Elasticsearch\Exception\ResponseException;
use SllhSmile\Elasticsearch\Hyperf\Manager;
use SllhSmile\Elasticsearch\Response\SearchHit;
use Throwable;

/**
 * 面向 Elasticsearch 文档的轻量模型基类，不依赖 Laravel/Eloquent。
 * 子类声明 index 和 casts，即可获得属性转换、命中元数据及 QueryBuilder 入口。
 *
 * @phpstan-consistent-constructor
 */
abstract class DocumentModel
{
    protected string $index = '';

    /** 空字符串表示使用 Manager 声明的顶层默认连接。 */
    protected string $connection = '';

    /** @var array<string, string> */
    protected array $casts = [];

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** @var array<string, mixed> 成功读取或写入时的文档快照，用于只提交改动字段。 */
    protected array $original = [];

    protected bool $exists = false;

    protected ?string $documentId = null;

    protected ?float $score = null;

    /** @var list<mixed> */
    protected array $sortValues = [];

    /** @var array<string, mixed> */
    protected array $highlight = [];

    /** @param array<string, mixed> $attributes */
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

    /** 返回显式连接名；null 表示交由 Manager 解析顶层默认连接。 */
    public function getConnectionName(): ?string
    {
        return $this->connection !== '' ? $this->connection : null;
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

    /** @return list<mixed> */
    public function getSortValues(): array
    {
        return $this->sortValues;
    }

    /** @return array<string, mixed> */
    public function getHighlight(): array
    {
        return $this->highlight;
    }

    /** @param array<string, mixed> $attributes */
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
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->attributes as $key => $value) {
            $result[$key] = $this->castOutbound((string) $key, $value);
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function toDocument(): array
    {
        return $this->toArray();
    }

    /**
     * 创建并写入一个新文档；指定 ID 时拒绝覆盖已有文档。
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     */
    public static function create(array $attributes, ?string $id = null, ?ClientInterface $client = null, array $options = []): static
    {
        $model = new static($attributes);
        if ($id !== null) {
            $model->documentId = $id;
        }
        $model->save($client, $options);
        return $model;
    }

    /**
     * 新模型执行创建，已存在模型只更新改动字段；没有改动时直接成功。
     *
     * @param array<string, mixed> $options
     */
    public function save(?ClientInterface $client = null, array $options = []): bool
    {
        $document = $this->toDocument();
        if ($this->exists) {
            if ($this->documentId === null) {
                throw new \LogicException('Cannot update an Elasticsearch document without an ID.');
            }
            $changes = array_diff_key($document, $this->original);
            foreach (array_intersect_key($document, $this->original) as $key => $value) {
                if ($value !== $this->original[$key]) {
                    $changes[$key] = $value;
                }
            }
            if ($changes === []) {
                return true;
            }
            $client ??= $this->resolveClient();
            // ES update/doc 合并对象字段；只提交顶层脏字段可保护投影查询未加载的字段。
            $client->responseToArray($client->update(array_replace($options, [
                'index' => $this->getIndexName(),
                'id' => $this->documentId,
                'body' => ['doc' => $changes],
            ])));
            // 写入成功后同步已加载对象的合并结果，避免模型属性和脏字段快照分离。
            // 投影查询中未加载的字段仍保持未知，与普通 ORM 的部分字段加载一致。
            $this->fill(self::mergeDocument($this->original, $changes));
        } else {
            $client ??= $this->resolveClient();
            $params = array_replace($options, [
                'index' => $this->getIndexName(),
                'body' => $document,
            ]);
            if ($this->documentId === null) {
                $raw = $client->responseToArray($client->index($params));
            } else {
                $params['id'] = $this->documentId;
                $raw = $client->responseToArray($client->create($params));
            }
            if (! isset($raw['_id']) || ! is_scalar($raw['_id']) || (string) $raw['_id'] === '') {
                throw new \UnexpectedValueException('Elasticsearch create response did not contain a document ID.');
            }
            $this->documentId = (string) $raw['_id'];
            $this->exists = true;
        }
        // 失败时不更新快照，保留本地改动以便调用方重试。
        $this->original = $this->toDocument();
        return true;
    }

    /**
     * 模拟 ES update/doc 的对象递归合并；列表和标量作为完整字段替换。
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function mergeDocument(array $original, array $changes): array
    {
        foreach ($changes as $key => $value) {
            $previous = $original[$key] ?? null;
            $original[$key] = is_array($previous) && is_array($value)
                && ! array_is_list($previous) && ! array_is_list($value)
                ? self::mergeDocument($previous, $value)
                : $value;
        }
        return $original;
    }

    /** 按 ES 文档 ID 读取并 hydrate 模型；只有明确的文档未命中返回 null。 */
    public static function find(string $id, ?ClientInterface $client = null): ?static
    {
        $model = new static();
        $client ??= $model->resolveClient();
        try {
            $raw = $client->responseToArray($client->get([
                'index' => $model->getIndexName(),
                'id' => $id,
            ]));
        } catch (ResponseException $exception) {
            if ($exception->statusCode() === 404 && self::isDocumentNotFoundResponse($exception->response())) {
                return null;
            }
            throw $exception;
        }
        if (($raw['found'] ?? true) === false) {
            return null;
        }
        $model->documentId = isset($raw['_id']) ? (string) $raw['_id'] : $id;
        $model->exists = true;
        $model->fill((array) ($raw['_source'] ?? []));
        $model->original = $model->toDocument();
        return $model;
    }

    /**
     * 仅更新已加载或已创建模型；失败时保留已填充的本地属性。
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     */
    public function update(array $attributes, ?ClientInterface $client = null, array $options = []): bool
    {
        if (! $this->exists) {
            return false;
        }
        $this->fill($attributes);
        return $this->save($client, $options);
    }

    /** @param array<string, mixed> $options */
    public function delete(?ClientInterface $client = null, array $options = []): bool
    {
        if ($this->documentId === null) {
            throw new \LogicException('Cannot delete an Elasticsearch document without an ID.');
        }
        $client ??= $this->resolveClient();
        $raw = $client->responseToArray($client->delete(array_replace($options, [
            'index' => $this->getIndexName(),
            'id' => $this->documentId,
        ])));
        $this->exists = false;
        return ($raw['result'] ?? null) === 'deleted' || ($raw['deleted'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    public function mapping(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return [];
    }

    /** 创建绑定当前模型索引的 QueryBuilder；容器外调用必须显式传入 client。 */
    public static function query(?ClientInterface $client = null): QueryBuilder
    {
        $model = new static();
        $client ??= self::resolveClientForConnection($model->getConnectionName());
        if ($client === null) {
            throw new \LogicException('No Elasticsearch client has been configured for the model.');
        }
        return new QueryBuilder($client, static::class, $model->getIndexName());
    }

    /**
     * 从 Hyperf 当前应用容器解析连接客户端。
     * 该路径只在模型未显式传入客户端时执行。
     */
    private static function resolveClientForConnection(?string $connection): ?ClientInterface
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

    /** 解析当前模型声明的连接。 */
    private function resolveClient(): ClientInterface
    {
        $client = self::resolveClientForConnection($this->getConnectionName());
        if ($client === null) {
            throw new \LogicException('No Elasticsearch client has been configured for the model.');
        }
        return $client;
    }

    /** 判断 404 响应是否明确表示文档不存在，避免隐藏索引缺失等部署错误。 */
    private static function isDocumentNotFoundResponse(mixed $response): bool
    {
        $payload = self::responsePayload($response);
        return $payload !== null
            && ($payload['found'] ?? null) === false
            && ! array_key_exists('error', $payload);
    }

    /**
     * 将异常中保留的数组、SDK 响应或 PSR-7 响应解析为错误载荷。
     *
     * @return null|array<string, mixed>
     */
    private static function responsePayload(mixed $response): ?array
    {
        if (is_array($response)) {
            return $response;
        }
        if (! is_object($response)) {
            return null;
        }

        foreach (['asArray', 'toArray'] as $method) {
            if (! method_exists($response, $method)) {
                continue;
            }
            try {
                $payload = $response->{$method}();
                if (is_array($payload)) {
                    return $payload;
                }
            } catch (Throwable) {
                // 继续尝试 PSR-7 body；无法识别时保留原异常。
            }
        }

        if (! method_exists($response, 'getBody')) {
            return null;
        }
        try {
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return is_array($payload) ? $payload : null;
        } catch (Throwable) {
            return null;
        }
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
        $model->original = $model->toDocument();
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
        // 与常见 ORM cast 语义一致：显式 null 不应被转换为 false、空数组或当前时间。
        if ($value === null) {
            return null;
        }
        $cast = $this->casts[$key] ?? null;
        return match ($cast) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
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
