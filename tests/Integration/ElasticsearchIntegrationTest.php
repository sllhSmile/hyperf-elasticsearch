<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Hyperf\Guzzle\ClientFactory as GuzzleClientFactory;
use Psr\Container\ContainerInterface;
use SllhSmile\Elasticsearch\Config\ConnectionConfig;
use SllhSmile\Elasticsearch\Hyperf\Factory\ClientFactory;
use SllhSmile\Elasticsearch\Builder\QueryBuilder;
use SllhSmile\Elasticsearch\Bulk\BulkOperation;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Model\DocumentModel;

final class IntegrationDocument extends DocumentModel
{
    protected string $index = 'integration-placeholder';
}

/** 仅在 CI 提供真实 ES 地址时运行，不影响普通本地单元测试。 */
final class ElasticsearchIntegrationTest extends TestCase
{
    private ElasticsearchClient $client;

    private string $index;

    private string $nextIndex;

    /** 配置测试连接和唯一索引，缺少外部服务时跳过。 */
    protected function setUp(): void
    {
        $host = getenv('ELASTICSEARCH_TEST_HOST');
        if (! is_string($host) || $host === '') {
            self::markTestSkipped('ELASTICSEARCH_TEST_HOST is not configured.');
        }
        if (! function_exists('Swoole\Coroutine\run')) {
            self::markTestSkipped('Swoole is required to verify the Hyperf coroutine transport.');
        }
        $container = new class implements ContainerInterface {
            /** 仅暴露 Guzzle 工厂，保证测试不依赖 hyperf/elasticsearch。 */
            public function get(string $id): mixed
            {
                return $id === GuzzleClientFactory::class
                    ? new GuzzleClientFactory($this) : throw new \RuntimeException($id);
            }

            /** 声明包内工厂实际需要的容器绑定。 */
            public function has(string $id): bool
            {
                return $id === GuzzleClientFactory::class;
            }
        };
        $this->client = (new ClientFactory($container))->make(new ConnectionConfig(hosts: [$host], retries: 0));
        $suffix = strtolower(bin2hex(random_bytes(6)));
        $this->index = "hyperf-elasticsearch-{$suffix}";
        $this->nextIndex = "{$this->index}-next";
    }

    /** 在协程内尽力释放测试索引。 */
    protected function tearDown(): void
    {
        if (! isset($this->client)) {
            return;
        }
        $this->inCoroutine(function (): void {
            foreach ([$this->index, $this->nextIndex] as $index) {
                try {
                    $this->client->indexManager()->delete($index);
                } catch (\Throwable) {
                    // 测试可能在创建索引前失败，清理异常不能掩盖原始断言。
                }
            }
        });
    }

    /** 真实服务验证包内工厂、协程传输及文档/索引/PIT endpoint。 */
    public function testCrudBulkAliasAndPitAgainstRealServer(): void
    {
        $this->inCoroutine(fn() => $this->exerciseEndpoints());
    }

    /** 在同一协程与客户端中验证 CRUD、Bulk、Alias 和 PIT。 */
    private function exerciseEndpoints(): void
    {
        $indices = $this->client->indexManager();
        $indices->create($this->index, mappings: ['properties' => ['title' => ['type' => 'text']]]);
        $indices->create($this->nextIndex);
        self::assertTrue($indices->exists($this->index));

        $this->client->index([
            'index' => $this->index,
            'id' => 'one',
            'refresh' => 'wait_for',
            'body' => ['title' => 'Hyperf Elasticsearch'],
        ]);
        $document = $this->client->responseToArray($this->client->get(['index' => $this->index, 'id' => 'one']));
        self::assertSame('Hyperf Elasticsearch', $document['_source']['title']);

        $this->client->update([
            'index' => $this->index, 'id' => 'one',
            'body' => ['doc' => ['title' => 'Updated title']],
        ]);
        $updated = $this->client->responseToArray($this->client->get(['index' => $this->index, 'id' => 'one']));
        self::assertSame('Updated title', $updated['_source']['title']);

        $bulk = $this->client->bulkManager(1)->execute([
            BulkOperation::index($this->index, 'two', ['title' => 'Second']),
            BulkOperation::index($this->index, 'three', ['title' => 'Third']),
        ], ['refresh' => 'wait_for']);
        self::assertFalse($bulk->hasErrors());

        $deleted = $this->client->responseToArray($this->client->delete(['index' => $this->index, 'id' => 'one', 'refresh' => 'wait_for']));
        self::assertSame('deleted', $deleted['result']);

        $alias = "{$this->index}-alias";
        $indices->addAlias($this->index, $alias, ['is_write_index' => true]);
        $indices->switchAlias($alias, $this->index, $this->nextIndex);

        $pit = $this->client->pitManager()->open($this->index, '1m');
        try {
            $response = (new QueryBuilder($this->client, IntegrationDocument::class, $this->index))
                ->pit($pit)->orderBy('_shard_doc')->size(2)->search();
            $total = $response->total();
            self::assertNotNull($total);
            self::assertGreaterThanOrEqual(2, $total->value);
        } finally {
            $this->client->pitManager()->close($pit);
        }
    }

    /** 将协程内断言和网络异常带回 PHPUnit，避免后台失败被遗漏。 */
    private function inCoroutine(callable $callback): void
    {
        $failure = null;
        \Swoole\Coroutine\run(static function () use ($callback, &$failure): void {
            try {
                $callback();
            } catch (\Throwable $exception) {
                $failure = $exception;
            }
        });
        if ($failure !== null) {
            throw $failure;
        }
    }
}
