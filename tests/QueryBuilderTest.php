<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use PHPUnit\Framework\TestCase;
use SllhSmile\Elasticsearch\Builder\QueryBuilder;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Model\DocumentModel;

final class QueryArticle extends DocumentModel
{
    protected string $index = 'articles';
}

final class QueryBuilderTest extends TestCase
{
    public function testCompilesCommonDsl(): void
    {
        $client = new ElasticsearchClient(new \stdClass());
        $builder = new QueryBuilder($client, QueryArticle::class, 'articles');
        $dsl = $builder->where('status', 'published')
            ->whereMatch('title', 'PHP')
            ->whereBetween('views', [1, 10])
            ->whereNested('author', fn (QueryBuilder $q) => $q->where('author.id', 1))
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
        $client = new ElasticsearchClient(new \stdClass());
        $dsl = (new QueryBuilder($client, QueryArticle::class, 'articles'))
            ->where('status', 'published')
            ->rawDsl(['track_total_hits' => true, 'runtime_mappings' => ['x' => ['type' => 'keyword']]])
            ->toDsl();

        self::assertTrue($dsl['track_total_hits']);
        self::assertSame('keyword', $dsl['runtime_mappings']['x']['type']);
    }

    public function testSortOptionsCannotOverrideValidatedDirection(): void
    {
        $client = new ElasticsearchClient(new \stdClass());
        $dsl = (new QueryBuilder($client, QueryArticle::class, 'articles'))
            ->orderBy('published_at', 'asc', ['mode' => 'min', 'order' => 'invalid'])
            ->toDsl();

        self::assertSame(['published_at' => ['mode' => 'min', 'order' => 'asc']], $dsl['sort'][0]);
    }

    public function testFirstAndCountDoNotMutateOriginalBuilder(): void
    {
        $raw = new class {
            public array $requests = [];
            public function search(array $params): array
            {
                $this->requests[] = $params;
                return ['hits' => ['total' => 3, 'hits' => []]];
            }
        };
        $client = new ElasticsearchClient($raw);
        $query = (new QueryBuilder($client, QueryArticle::class, 'articles'))->where('status', 'published')->size(20);
        $query->first();
        $query->count();

        self::assertSame(20, $query->toDsl()['size']);
        self::assertSame(1, $raw->requests[0]['body']['size']);
        self::assertSame(0, $raw->requests[1]['body']['size']);
    }

    public function testRawDslCannotSilentlyOverrideGeneratedTopLevelDsl(): void
    {
        $client = new ElasticsearchClient(new \stdClass());
        $query = (new QueryBuilder($client, QueryArticle::class, 'articles'))
            ->size(10)
            ->rawDsl(['size' => 5]);

        $this->expectException(\InvalidArgumentException::class);
        $query->toDsl();
    }

    public function testCreateUsesTheBoundModelAndClient(): void
    {
        $raw = new class {
            public array $params = [];

            public function index(array $params): array
            {
                $this->params = $params;
                return ['_id' => $params['id'], 'result' => 'created'];
            }
        };
        $client = new ElasticsearchClient($raw);

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
            public array $params = [];

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
        $response = (new QueryBuilder(new ElasticsearchClient($raw), QueryArticle::class, 'articles'))
            ->where('title', 'PHP')
            ->whereMatch('content', 'Elasticsearch')
            ->whereRange('score', ['gte' => 1])
            ->orderBy('created_at', 'desc')
            ->size(20)
            ->search();

        self::assertSame(1, $response->total());
        self::assertInstanceOf(QueryArticle::class, $response->first());
        self::assertSame('desc', $raw->params['body']['sort'][0]['created_at']);
        self::assertSame(1, $raw->params['body']['query']['bool']['filter'][1]['range']['score']['gte']);
    }
}
