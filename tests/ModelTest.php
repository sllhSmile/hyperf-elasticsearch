<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use PHPUnit\Framework\TestCase;
use Hyperf\Context\ApplicationContext;
use Psr\Container\ContainerInterface;
use SllhSmile\Elasticsearch\Hyperf\Factory\ClientFactory;
use SllhSmile\Elasticsearch\Hyperf\Manager;
use SllhSmile\Elasticsearch\Model\DocumentModel;
use SllhSmile\Elasticsearch\Response\SearchHit;
use SllhSmile\Elasticsearch\Response\SearchResponse;

final class Article extends DocumentModel
{
    protected string $index = 'articles';

    protected string $connection = 'default';

    protected array $casts = ['views' => 'int', 'published_at' => 'datetime'];
}

final class ModelTest extends TestCase
{
    public function testModelSerializesAndHydratesHit(): void
    {
        $model = Article::fromSearchHit(SearchHit::fromArray([
            '_id' => 'a-1',
            '_score' => 1.5,
            'sort' => [1.5, 'a-1'],
            'highlight' => ['title' => ['<em>PHP</em>']],
            '_source' => ['title' => 'PHP', 'views' => '3', 'published_at' => '2026-01-01T00:00:00+00:00'],
        ]));

        self::assertTrue($model->exists());
        self::assertSame('a-1', $model->getKey());
        self::assertSame(3, $model->views);
        self::assertSame('PHP', $model->getAttribute('title'));
        self::assertSame('2026-01-01T00:00:00+00:00', $model->toArray()['published_at']);
        self::assertSame(['title' => ['<em>PHP</em>']], $model->getHighlight());
    }

    public function testSearchResponseParsesTotalAndModels(): void
    {
        $response = new SearchResponse([
            'hits' => [
                'total' => ['value' => 2, 'relation' => 'eq'],
                'hits' => [['_id' => '1', '_source' => ['title' => 'One']], ['_id' => '2', '_source' => ['title' => 'Two']]],
            ],
            'aggregations' => ['categories' => ['buckets' => []]],
        ], Article::class);

        self::assertCount(2, $response);
        self::assertSame(2, $response->total());
        self::assertInstanceOf(Article::class, $response->first());
        self::assertSame('Two', $response->hits()[1]->title);
        self::assertArrayHasKey('categories', $response->aggregations());
    }

    public function testModelQueryResolvesDeclaredConnectionFromApplicationContainer(): void
    {
        $manager = new Manager(new ClientFactory(), [
            'default' => 'default',
            'connections' => [
                'default' => ['hosts' => ['https://example.test']],
            ],
        ]);
        $container = new class($manager) implements ContainerInterface {
            public function __construct(private Manager $manager) {}
            public function get(string $id): mixed { return $id === Manager::class ? $this->manager : throw new \RuntimeException($id); }
            public function has(string $id): bool { return $id === Manager::class; }
        };
        ApplicationContext::setContainer($container);

        $builder = Article::query();
        self::assertSame([], $builder->toDsl());
    }
}
