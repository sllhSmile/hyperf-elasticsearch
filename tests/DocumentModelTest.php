<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use Elastic\Elasticsearch\Exception\ClientResponseException as OfficialClientResponseException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Model\DocumentModel;

final class ModelArticle extends DocumentModel
{
    protected string $index = 'articles';

    protected array $casts = [
        'score' => 'int',
        'published_at' => 'datetime',
    ];
}

final class DefaultClientModelArticle extends DocumentModel
{
    protected string $index = 'default-client-articles';
}

final class DocumentModelTest extends TestCase
{
    public function testCreateSaveAndUpdateUseHydratedModelAndCasts(): void
    {
        $raw = new class {
            public array $indexCalls = [];

            public function index(array $params): array
            {
                $this->indexCalls[] = $params;
                return ['_id' => $params['id'] ?? 'generated-id', 'result' => 'created'];
            }
        };
        $client = new ElasticsearchClient($raw);

        $model = ModelArticle::create([
            'title' => 'PHP',
            'score' => '10',
            'published_at' => '2026-09-07T12:00:00+08:00',
        ], 'article-1', $client, ['refresh' => 'wait_for']);

        self::assertSame('article-1', $model->getKey());
        self::assertTrue($model->exists());
        self::assertSame(10, $model->getAttribute('score'));
        self::assertSame('2026-09-07T12:00:00+08:00', $model->toArray()['published_at']);
        self::assertSame('wait_for', $raw->indexCalls[0]['refresh']);
        self::assertSame('article-1', $raw->indexCalls[0]['id']);

        $model->update(['score' => '11'], $client);
        self::assertSame(11, $model->getAttribute('score'));
        self::assertCount(2, $raw->indexCalls);
        self::assertSame(11, $raw->indexCalls[1]['body']['score']);
    }

    public function testSaveWithoutIdUsesReturnedIdAndFindHydratesSource(): void
    {
        $raw = new class {
            public function index(array $params): object
            {
                return new class {
                    public function asArray(): array
                    {
                        return ['_id' => 'generated-id', 'result' => 'created'];
                    }
                };
            }

            public function get(array $params): object
            {
                return new class {
                    public function toArray(): array
                    {
                        return [
                            '_id' => 'article-2',
                            'found' => true,
                            '_source' => ['title' => 'Elasticsearch', 'score' => '7'],
                        ];
                    }
                };
            }
        };
        $client = new ElasticsearchClient($raw);

        $created = (new ModelArticle(['title' => 'Draft']))->save($client);
        self::assertSame('generated-id', $created->getKey());
        self::assertTrue($created->exists());

        $found = ModelArticle::find('article-2', $client);
        self::assertNotNull($found);
        self::assertSame('article-2', $found->getKey());
        self::assertTrue($found->exists());
        self::assertSame('Elasticsearch', $found->getAttribute('title'));
        self::assertSame(7, $found->getAttribute('score'));
    }

    public function testFindConvertsHttp404ToNull(): void
    {
        $officialException = class_exists(OfficialClientResponseException::class)
            ? (new OfficialClientResponseException('not found', 404))->setResponse(new Psr7Response(404, [], '{"found":false}'))
            : new \Elasticsearch\Common\Exceptions\Missing404Exception('not found', 404);
        $raw = new class($officialException) {
            public function __construct(private \Throwable $exception)
            {
            }

            public function get(array $params): never
            {
                throw $this->exception;
            }
        };

        self::assertNull(ModelArticle::find('missing', new ElasticsearchClient($raw)));
    }

    public function testDeleteMarksModelAsNotExisting(): void
    {
        $raw = new class {
            public function delete(array $params): array
            {
                return ['_id' => $params['id'], 'result' => 'deleted'];
            }
        };
        $client = new ElasticsearchClient($raw);
        $model = (new ModelArticle(['title' => 'PHP']))->setKey('article-3');

        self::assertTrue($model->delete($client));
        self::assertFalse($model->exists());
    }

    public function testCrudUsesClientConfiguredBySetClient(): void
    {
        $raw = new class {
            public function index(array $params): array
            {
                return ['_id' => $params['id'] ?? 'created', 'result' => 'created'];
            }

            public function get(array $params): array
            {
                return ['_id' => $params['id'], 'found' => true, '_source' => ['title' => 'default']];
            }

            public function delete(array $params): array
            {
                return ['_id' => $params['id'], 'result' => 'deleted'];
            }
        };
        DefaultClientModelArticle::setClient(new ElasticsearchClient($raw));

        $created = DefaultClientModelArticle::create(['title' => 'created'], 'article-4');
        self::assertTrue($created->exists());
        self::assertSame('default', DefaultClientModelArticle::find('article-4')?->getAttribute('title'));
        self::assertTrue($created->delete());
    }
}
