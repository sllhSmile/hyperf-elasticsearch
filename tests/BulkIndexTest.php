<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use PHPUnit\Framework\TestCase;
use SllhSmile\Elasticsearch\Bulk\BulkManager;
use SllhSmile\Elasticsearch\Bulk\BulkOperation;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Tests\Support\ClientAdapterStub;

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

    public function testManagerChunksOperationsAndCountsItemFailures(): void
    {
        $raw = new class {
            /** @var list<array<string, mixed>> */
            public array $requests = [];
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function bulk(array $params): array
            {
                $this->requests[] = $params;
                $id = count($this->requests);
                return ['errors' => $id === 2, 'items' => [[
                    'index' => $id === 2
                        ? ['status' => 409, 'error' => ['type' => 'version_conflict']]
                        : ['status' => 201],
                ]]];
            }
        };
        $result = (new BulkManager(ClientAdapterStub::client($raw), 1))->execute([
            BulkOperation::index('articles', '1', ['title' => 'One']),
            BulkOperation::index('articles', '2', ['title' => 'Two']),
        ], ['refresh' => 'wait_for']);

        self::assertCount(2, $raw->requests);
        self::assertSame('wait_for', $raw->requests[0]['refresh']);
        self::assertCount(2, $result->items);
        self::assertSame(1, $result->errors);
        self::assertTrue($result->hasErrors());
    }

    public function testManagerDoesNotSendRequestForEmptyOperations(): void
    {
        $raw = new class {
            public int $requests = 0;
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function bulk(array $params): array
            {
                $this->requests++;
                return [];
            }
        };
        $result = (new BulkManager(ClientAdapterStub::client($raw)))->execute([]);

        self::assertSame(0, $raw->requests);
        self::assertSame([], $result->items);
        self::assertFalse($result->hasErrors());
    }
}
