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
}
