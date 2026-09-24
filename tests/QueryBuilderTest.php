<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use Hyperf\Collection\Collection;
use PHPUnit\Framework\TestCase;
use SllhSmile\Elasticsearch\Builder\QueryBuilder;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Tests\Support\ClientAdapterStub;
use SllhSmile\Elasticsearch\Model\DocumentModel;
use SllhSmile\Elasticsearch\Response\TotalHitsRelation;

final class QueryArticle extends DocumentModel
{
    protected string $index = 'articles';
}

final class QueryBuilderTest extends TestCase
{
    public function testCompilesCommonDsl(): void
    {
        $client = ClientAdapterStub::client(new \stdClass());
        $builder = new QueryBuilder($client, QueryArticle::class, 'articles');
        $dsl = $builder->where('status', 'published')
            ->whereMatch('title', 'PHP')
            ->whereBetween('views', [1, 10])
            ->whereNested('author', fn(QueryBuilder $q) => $q->where('author.id', 1))
            ->orderBy('_score', 'desc')
            ->size(20)
            ->highlight(['title'])
            ->aggs('categories', ['terms' => ['field' => 'category_id']])
            ->toDsl();

        self::assertSame('published', $dsl['query']['bool']['filter'][0]['term']['status']);
        self::assertSame('PHP', $dsl['query']['bool']['must'][0]['match']['title']['query']);
        self::assertSame(10, $dsl['query']['bool']['filter'][1]['range']['views']['lte']);
        self::assertSame('author', $dsl['query']['bool']['filter'][2]['nested']['path']);
        self::assertSame([['_score' => 'desc']], $dsl['sort']);
        self::assertArrayHasKey('categories', $dsl['aggs']);
    }

    public function testRawDslCanAddTopLevelOptions(): void
    {
        $client = ClientAdapterStub::client(new \stdClass());
        $dsl = (new QueryBuilder($client, QueryArticle::class, 'articles'))
            ->where('status', 'published')
            ->rawDsl(['track_total_hits' => true, 'runtime_mappings' => ['x' => ['type' => 'keyword']]])
            ->toDsl();

        self::assertTrue($dsl['track_total_hits']);
        self::assertSame('keyword', $dsl['runtime_mappings']['x']['type']);
    }

    public function testRawDslCanProvideCompleteDslOnFreshBuilder(): void
    {
        $client = ClientAdapterStub::client(new \stdClass());
        $dsl = (new QueryBuilder($client, QueryArticle::class, 'articles'))
            ->rawDsl([
                'query' => ['match' => ['title' => 'PHP']],
                'sort' => [['published_at' => 'desc']],
                'size' => 10,
            ])
            ->toDsl();

        self::assertSame('PHP', $dsl['query']['match']['title']);
        self::assertSame([['published_at' => 'desc']], $dsl['sort']);
        self::assertSame(10, $dsl['size']);
    }

    /** 重复提供 sort 和 bool 子句时，列表整体替换，旁侧对象字段继续合并。 */
    public function testRepeatedRawDslReplacesListsAndMergesObjectKeys(): void
    {
        $client = ClientAdapterStub::client(new \stdClass());
        $dsl = (new QueryBuilder($client, QueryArticle::class, 'articles'))
            ->rawDsl([
                'sort' => [['price' => 'asc']],
                'query' => ['bool' => ['must' => [['term' => ['status' => 'draft']]], 'filter' => [['term' => ['active' => true]]]]],
            ])
            ->rawDsl([
                'sort' => [['created_at' => 'desc']],
                'query' => ['bool' => ['must' => [['match' => ['title' => 'PHP']]]]],
            ])
            ->toDsl();

        self::assertSame([['created_at' => 'desc']], $dsl['sort']);
        self::assertSame([['match' => ['title' => 'PHP']]], $dsl['query']['bool']['must']);
        self::assertSame([['term' => ['active' => true]]], $dsl['query']['bool']['filter']);
    }

    public function testSortOptionsCannotOverrideValidatedDirection(): void
    {
        $client = ClientAdapterStub::client(new \stdClass());
        $dsl = (new QueryBuilder($client, QueryArticle::class, 'articles'))
            ->orderBy('published_at', 'asc', ['mode' => 'min', 'order' => 'invalid'])
            ->toDsl();

        self::assertSame(['published_at' => ['mode' => 'min', 'order' => 'asc']], $dsl['sort'][0]);
    }

    public function testFirstAndCountDoNotMutateOriginalBuilder(): void
    {
        $raw = new class {
            /** @var list<array<string, mixed>> */
            public array $requests = [];
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function search(array $params): array
            {
                $this->requests[] = $params;
                return ['hits' => ['total' => 3, 'hits' => []]];
            }
        };
        $client = ClientAdapterStub::client($raw);
        $query = (new QueryBuilder($client, QueryArticle::class, 'articles'))->where('status', 'published')->size(20);
        $query->first();
        self::assertSame(3, $query->count());

        self::assertSame(20, $query->toDsl()['size']);
        self::assertSame(1, $raw->requests[0]['body']['size']);
        self::assertSame(0, $raw->requests[1]['body']['size']);
    }

    public function testRawDslCannotSilentlyOverrideGeneratedTopLevelDsl(): void
    {
        $client = ClientAdapterStub::client(new \stdClass());
        $query = (new QueryBuilder($client, QueryArticle::class, 'articles'))
            ->size(10)
            ->rawDsl(['size' => 5]);

        $this->expectException(\InvalidArgumentException::class);
        $query->toDsl();
    }

    public function testCountRejectsInexactTotal(): void
    {
        $raw = new class {
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function search(array $params): array
            {
                return ['hits' => [
                    'total' => ['value' => 10000, 'relation' => 'gte'],
                    'hits' => [],
                ]];
            }
        };
        $query = new QueryBuilder(ClientAdapterStub::client($raw), QueryArticle::class, 'articles');

        $this->expectException(\UnexpectedValueException::class);
        $query->count();
    }

    public function testCreateUsesTheBoundModelAndClient(): void
    {
        $raw = new class {
            /** @var array<string, mixed> */
            public array $params = [];

            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function create(array $params): array
            {
                $this->params = $params;
                return ['_id' => $params['id'], 'result' => 'created'];
            }
        };
        $client = ClientAdapterStub::client($raw);

        $model = (new QueryBuilder($client, QueryArticle::class, 'articles'))
            ->create(['title' => 'PHP'], 'article-1', ['refresh' => 'wait_for']);

        self::assertInstanceOf(QueryArticle::class, $model);
        self::assertSame('article-1', $model->getKey());
        self::assertTrue($model->exists());
        self::assertSame('articles', $raw->params['index']);
        self::assertSame('PHP', $raw->params['body']['title']);
        self::assertSame('wait_for', $raw->params['refresh']);
    }

    public function testChainedRangeAndSortSearchReturnsHydratedModels(): void
    {
        $raw = new class {
            /** @var array<string, mixed> */
            public array $params = [];

            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function search(array $params): array
            {
                $this->params = $params;
                return [
                    'hits' => [
                        'total' => ['value' => 1, 'relation' => 'eq'],
                        'hits' => [
                            ['_id' => 'article-1', '_source' => ['title' => 'PHP'], '_score' => 1.2],
                        ],
                    ],
                ];
            }
        };
        $response = (new QueryBuilder(ClientAdapterStub::client($raw), QueryArticle::class, 'articles'))
            ->where('title', 'PHP')
            ->whereMatch('content', 'Elasticsearch')
            ->whereRange('score', ['gte' => 1])
            ->orderBy('created_at', 'desc')
            ->size(20)
            ->search();

        $total = $response->total();
        self::assertNotNull($total);
        self::assertSame(1, $total->value);
        self::assertSame(TotalHitsRelation::Eq, $total->relation);
        self::assertInstanceOf(QueryArticle::class, $response->first());
        self::assertSame('desc', $raw->params['body']['sort'][0]['created_at']);
        self::assertSame(1, $raw->params['body']['query']['bool']['filter'][1]['range']['score']['gte']);
    }

    public function testPitSearchOmitsIndexWhileNormalSearchKeepsIt(): void
    {
        $raw = new class {
            /** @var list<array<string, mixed>> */
            public array $requests = [];
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function search(array $params): array
            {
                $this->requests[] = $params;
                return ['hits' => ['total' => 0, 'hits' => []]];
            }
        };
        $client = ClientAdapterStub::client($raw);
        (new QueryBuilder($client, QueryArticle::class, 'articles'))->search();
        (new QueryBuilder($client, QueryArticle::class, 'articles'))->pit('pit-1')->search();
        (new QueryBuilder($client, QueryArticle::class, 'articles'))
            ->rawDsl(['pit' => ['id' => 'pit-2'], 'size' => 10])->search();

        self::assertSame('articles', $raw->requests[0]['index']);
        self::assertArrayNotHasKey('index', $raw->requests[1]);
        self::assertArrayNotHasKey('index', $raw->requests[2]);
    }

    /** PIT ID 可在响应中轮换，回调应拿到模型 Collection 和连续批次号。 */
    public function testChunkUsesLatestPitAndClosesItAfterEarlyStop(): void
    {
        $raw = new class {
            /** @var list<array<string, mixed>> */
            public array $searches = [];
            /** @var array<string, mixed> */
            public array $closed = [];
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function openPointInTime(array $params): array
            {
                return ['id' => 'pit-1'];
            }
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function search(array $params): array
            {
                $this->searches[] = $params;
                $page = count($this->searches);
                return [
                    'pit_id' => 'pit-' . ($page + 1),
                    'hits' => ['hits' => [[
                        '_id' => 'a-' . $page,
                        '_source' => ['title' => 'Page ' . $page],
                        'sort' => [$page, $page],
                    ]]],
                ];
            }
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function closePointInTime(array $params): array
            {
                $this->closed = $params;
                return ['succeeded' => true];
            }
        };
        $seen = [];
        $query = (new QueryBuilder(ClientAdapterStub::client($raw), QueryArticle::class, 'articles'))
            ->where('status', 'published')->orderBy('created_at');
        $finished = $query->chunk(1, static function (Collection $models, int $page) use (&$seen): bool {
            $seen[] = [$page, $models->first()?->getKey()];
            return $page !== 2;
        });

        self::assertFalse($finished);
        self::assertSame([[1, 'a-1'], [2, 'a-2']], $seen);
        self::assertSame('pit-2', $raw->searches[1]['body']['pit']['id']);
        self::assertSame([1, 1], $raw->searches[1]['body']['search_after']);
        self::assertSame([['created_at' => 'asc'], ['_shard_doc' => 'asc']], $raw->searches[0]['body']['sort']);
        self::assertSame('published', $raw->searches[0]['body']['query']['bool']['filter'][0]['term']['status']);
        self::assertSame(['body' => ['id' => 'pit-3']], $raw->closed);
        self::assertArrayNotHasKey('pit', $query->toDsl());
    }

    /** 批次回调抛错时仍关闭最新 PIT，并保留原异常。 */
    public function testChunkClosesPitOnCallbackFailure(): void
    {
        $raw = new class {
            /** @var array<string, mixed> */
            public array $closed = [];
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function openPointInTime(array $params): array
            {
                return ['id' => 'pit-1'];
            }
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function search(array $params): array
            {
                return ['pit_id' => 'pit-2', 'hits' => ['hits' => [['_id' => 'a-1', '_source' => [], 'sort' => [1]]]]];
            }
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function closePointInTime(array $params): array
            {
                $this->closed = $params;
                return ['succeeded' => true];
            }
        };
        try {
            (new QueryBuilder(ClientAdapterStub::client($raw), QueryArticle::class, 'articles'))
                ->chunk(1, static function (): never { throw new \RuntimeException('callback failed'); });
            self::fail('Expected callback failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('callback failed', $exception->getMessage());
        }
        self::assertSame(['body' => ['id' => 'pit-2']], $raw->closed);
    }

    /** 已设 from/size 分别决定起点和总量，不能改变原 Builder 的 DSL。 */
    public function testChunkHonorsOffsetAndTotalLimit(): void
    {
        $raw = new class {
            public int $calls = 0;
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function openPointInTime(array $params): array
            {
                return ['id' => 'pit-1'];
            }
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function search(array $params): array
            {
                ++$this->calls;
                $id = $this->calls;
                return ['hits' => ['hits' => [[
                    '_id' => 'a-' . $id,
                    '_source' => ['title' => 'Title ' . $id],
                    'sort' => [$id],
                ]]]];
            }
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function closePointInTime(array $params): array
            {
                return ['succeeded' => true];
            }
        };
        $query = (new QueryBuilder(ClientAdapterStub::client($raw), QueryArticle::class, 'articles'))
            ->from(1)->size(2);
        $ids = [];
        self::assertTrue($query->chunk(1, static function (Collection $models) use (&$ids): void {
            $ids[] = $models->first()?->getKey();
        }));
        self::assertSame(['a-2', 'a-3'], $ids);
        self::assertSame(3, $raw->calls);
        self::assertSame(1, $query->toDsl()['from']);
        self::assertSame(2, $query->toDsl()['size']);
    }
}
