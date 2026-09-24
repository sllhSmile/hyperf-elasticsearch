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
use SllhSmile\Elasticsearch\Response\TotalHitsRelation;

final class Article extends DocumentModel
{
    protected string $index = 'articles';

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
        self::assertSame(3, $model->getAttribute('views'));
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

        $total = $response->total();
        self::assertNotNull($total);
        self::assertCount(2, $response);
        self::assertSame(2, $total->value);
        self::assertSame(TotalHitsRelation::Eq, $total->relation);
        self::assertTrue($total->isExact());
        self::assertInstanceOf(Article::class, $response->first());
        $second = $response->hits()[1];
        self::assertInstanceOf(Article::class, $second);
        self::assertSame('Two', $second->getAttribute('title'));
        self::assertArrayHasKey('categories', $response->aggregations());
    }

    public function testSearchResponsePreservesTotalHitsRelation(): void
    {
        $response = new SearchResponse(['hits' => [
            'total' => ['value' => 10000, 'relation' => 'gte'],
            'hits' => [],
        ]]);

        $total = $response->total();
        self::assertNotNull($total);
        self::assertSame(10000, $total->value);
        self::assertSame(TotalHitsRelation::Gte, $total->relation);
        self::assertFalse($total->isExact());
    }

    public function testSearchResponseSupportsLegacyIntegerAndMissingTotal(): void
    {
        $legacy = new SearchResponse(['hits' => ['total' => 3, 'hits' => []]]);
        $missing = new SearchResponse(['hits' => ['hits' => []]]);

        $total = $legacy->total();
        self::assertNotNull($total);
        self::assertSame(3, $total->value);
        self::assertSame(TotalHitsRelation::Eq, $total->relation);
        self::assertNull($missing->total());
    }

    public function testSearchResponseRejectsUnknownTotalRelation(): void
    {
        $response = new SearchResponse(['hits' => [
            'total' => ['value' => 1, 'relation' => 'unknown'],
            'hits' => [],
        ]]);

        $this->expectException(\UnexpectedValueException::class);
        $response->total();
    }

    public function testSearchResponseRejectsMalformedTotal(): void
    {
        $response = new SearchResponse(['hits' => [
            'total' => ['value' => '1', 'relation' => 'eq'],
            'hits' => [],
        ]]);

        $this->expectException(\UnexpectedValueException::class);
        $response->total();
    }

    public function testModelQueryResolvesDeclaredConnectionFromApplicationContainer(): void
    {
        $property = new \ReflectionProperty(ApplicationContext::class, 'container');
        $previous = $property->getValue();
        $factoryContainer = new class implements ContainerInterface {
            /** 该用例只验证连接解析，不构建 HTTP Client。 */
            public function get(string $id): mixed
            {
                throw new \RuntimeException($id);
            }

            /** 该用例不声明工厂依赖。 */
            public function has(string $id): bool
            {
                return false;
            }
        };
        $manager = new Manager(new ClientFactory($factoryContainer), [
            'default' => 'primary',
            'connections' => [
                'primary' => ['hosts' => ['https://example.test']],
            ],
        ]);
        $container = new class ($manager) implements ContainerInterface {
            public function __construct(private Manager $manager) {}
            public function get(string $id): mixed
            {
                return $id === Manager::class ? $this->manager : throw new \RuntimeException($id);
            }
            public function has(string $id): bool
            {
                return $id === Manager::class;
            }
        };
        try {
            ApplicationContext::setContainer($container);
            $builder = Article::query();
            self::assertSame([], $builder->toDsl());
        } finally {
            // Hyperf 容器是 Worker 级静态状态，测试后必须恢复，包括原值为 null 时。
            $property->setValue(null, $previous);
        }
    }
}
