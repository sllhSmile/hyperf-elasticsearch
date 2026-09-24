<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use PHPUnit\Framework\TestCase;
use SllhSmile\Elasticsearch\Bulk\BulkManager;
use SllhSmile\Elasticsearch\Bulk\BulkOperation;
use SllhSmile\Elasticsearch\Exception\BulkExecutionException;
use SllhSmile\Elasticsearch\Exception\BulkProtocolException;
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

    /** 中途请求失败时仅前面完成的块可用于安全地决定后续重试。 */
    public function testInterruptedBulkCarriesConfirmedResult(): void
    {
        $raw = new class {
            public int $calls = 0;
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function bulk(array $params): array
            {
                ++$this->calls;
                if ($this->calls === 2) {
                    throw new \RuntimeException('connection lost');
                }
                return ['items' => [['index' => ['status' => 201]]]];
            }
        };
        try {
            (new BulkManager(ClientAdapterStub::client($raw), 1))->execute([
                BulkOperation::index('articles', '1', ['title' => 'One']),
                BulkOperation::index('articles', '2', ['title' => 'Two']),
            ]);
            self::fail('Expected BulkExecutionException.');
        } catch (BulkExecutionException $exception) {
            self::assertSame(2, $exception->failedChunk);
            self::assertCount(1, $exception->completedResult->items);
            self::assertCount(1, $exception->completedResult->raw);
            self::assertSame('connection lost', $exception->getPrevious()?->getMessage());
        }
    }

    /** 缺少 status 的成功 HTTP 响应不可伪装为成功的 Bulk 项。 */
    public function testMalformedBulkResponseCarriesPreviousChunkOnly(): void
    {
        $raw = new class {
            public int $calls = 0;
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function bulk(array $params): array
            {
                ++$this->calls;
                return ['items' => [['index' => $this->calls === 1 ? ['status' => 201] : []]]];
            }
        };
        try {
            (new BulkManager(ClientAdapterStub::client($raw), 1))->execute([
                BulkOperation::index('articles', '1', []),
                BulkOperation::index('articles', '2', []),
            ]);
            self::fail('Expected BulkProtocolException.');
        } catch (BulkProtocolException $exception) {
            self::assertSame(2, $exception->failedChunk);
            self::assertCount(1, $exception->completedResult->items);
        }
    }
}
