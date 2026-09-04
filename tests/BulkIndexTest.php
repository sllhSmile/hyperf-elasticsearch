<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use PHPUnit\Framework\TestCase;
use SllhSmile\Elasticsearch\Bulk\BulkOperation;

final class BulkIndexTest extends TestCase
{
    public function testBulkOperationProducesNdjsonLines(): void
    {
        $operation = BulkOperation::index('articles', '1', ['title' => 'PHP']);
        self::assertSame([
            ['index' => ['_index' => 'articles', '_id' => '1']],
            ['title' => 'PHP'],
        ], $operation->toNdjsonLines());

        self::assertSame([
            ['delete' => ['_index' => 'articles', '_id' => '2']],
        ], BulkOperation::delete('articles', '2')->toNdjsonLines());
    }
}
