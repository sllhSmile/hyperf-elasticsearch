<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Bulk;

/**
 * 一个 Bulk 动作的不可变描述；最终会展开为一到两行 NDJSON。
 */
final class BulkOperation
{
    /** 仅由静态工厂创建，保证 metadata/source 结构合法。 */
    private function __construct(private readonly array $meta, private readonly ?array $source = null)
    {
    }

    /** 创建 index 动作（metadata 行 + source 行）。 */
    public static function index(string $index, string $id, array $document): self
    {
        return new self(['index' => ['_index' => $index, '_id' => $id]], $document);
    }

    /** 创建 create 动作，文档已存在时由 ES 返回冲突。 */
    public static function create(string $index, string $id, array $document): self
    {
        return new self(['create' => ['_index' => $index, '_id' => $id]], $document);
    }

    /** 创建 update 动作，可选 doc_as_upsert。 */
    public static function update(string $index, string $id, array $doc, bool $docAsUpsert = false): self
    {
        return new self(['update' => ['_index' => $index, '_id' => $id]], ['doc' => $doc, 'doc_as_upsert' => $docAsUpsert]);
    }

    /** 创建 delete 动作（仅 metadata 行）。 */
    public static function delete(string $index, string $id): self
    {
        return new self(['delete' => ['_index' => $index, '_id' => $id]]);
    }

    /** 按 ES Bulk 顺序返回 metadata/source 行；不进行 JSON 编码。 */
    public function toNdjsonLines(): array
    {
        return $this->source === null ? [$this->meta] : [$this->meta, $this->source];
    }
}
