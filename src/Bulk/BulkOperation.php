<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Bulk;

/**
 * 一个 Bulk 动作的不可变描述；最终会展开为一到两行 NDJSON。
 */
final class BulkOperation
{
    /**
     * 仅由静态工厂创建，保证 metadata/source 结构合法。
     *
     * @param array<string, mixed> $meta
     * @param null|array<string, mixed> $source
     */
    private function __construct(private readonly array $meta, private readonly ?array $source = null) {}

    /** @param array<string, mixed> $document */
    public static function index(string $index, string $id, array $document): self
    {
        return new self(['index' => ['_index' => $index, '_id' => $id]], $document);
    }

    /** @param array<string, mixed> $document */
    public static function create(string $index, string $id, array $document): self
    {
        return new self(['create' => ['_index' => $index, '_id' => $id]], $document);
    }

    /** @param array<string, mixed> $doc */
    public static function update(string $index, string $id, array $doc, bool $docAsUpsert = false): self
    {
        return new self(['update' => ['_index' => $index, '_id' => $id]], ['doc' => $doc, 'doc_as_upsert' => $docAsUpsert]);
    }

    /** 创建 delete 动作（仅 metadata 行）。 */
    public static function delete(string $index, string $id): self
    {
        return new self(['delete' => ['_index' => $index, '_id' => $id]]);
    }

    /** @return list<array<string, mixed>> */
    public function toNdjsonLines(): array
    {
        return $this->source === null ? [$this->meta] : [$this->meta, $this->source];
    }
}
