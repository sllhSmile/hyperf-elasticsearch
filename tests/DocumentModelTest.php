<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use Elastic\Elasticsearch\Exception\ClientResponseException as OfficialClientResponseException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Exception\ResponseException;
use SllhSmile\Elasticsearch\Model\DocumentModel;
use SllhSmile\Elasticsearch\Tests\Support\ClientAdapterStub;

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
            /** @var list<array<string, mixed>> */
            public array $indexCalls = [];

            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function index(array $params): array
            {
                $this->indexCalls[] = $params;
                return ['_id' => $params['id'] ?? 'generated-id', 'result' => 'created'];
            }
        };
        $client = ClientAdapterStub::client($raw);

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
            /** @param array<string, mixed> $params */
            public function index(array $params): object
            {
                return new class {
                    /** @return array<string, mixed> */
                    public function asArray(): array
                    {
                        return ['_id' => 'generated-id', 'result' => 'created'];
                    }
                };
            }

            /** @param array<string, mixed> $params */
            public function get(array $params): object
            {
                return new class {
                    /** @return array<string, mixed> */
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
        $client = ClientAdapterStub::client($raw);

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

    public function testFindConvertsDocumentNotFoundResponseToNull(): void
    {
        $officialException = (new OfficialClientResponseException('not found', 404))
            ->setResponse(new Psr7Response(404, [], '{"found":false}'));
        $raw = new class ($officialException) {
            public function __construct(private \Throwable $exception) {}

            /** @param array<string, mixed> $params */
            public function get(array $params): never
            {
                throw $this->exception;
            }
        };

        self::assertNull(ModelArticle::find('missing', ClientAdapterStub::client($raw)));
    }

    public function testFindAcceptsArrayDocumentNotFoundPayload(): void
    {
        $raw = new class {
            /** @param array<string, mixed> $params */
            public function get(array $params): never
            {
                throw new ResponseException('not found', 404, ['found' => false]);
            }
        };

        self::assertNull(ModelArticle::find('missing', ClientAdapterStub::client($raw)));
    }

    public function testFindRethrowsIndexNotFoundResponse(): void
    {
        $officialException = (new OfficialClientResponseException('index not found', 404))
            ->setResponse(new Psr7Response(404, [], json_encode([
                'error' => ['type' => 'index_not_found_exception', 'reason' => 'no such index [articles]'],
                'status' => 404,
            ], JSON_THROW_ON_ERROR)));
        $raw = new class ($officialException) {
            public function __construct(private \Throwable $exception) {}

            /** @param array<string, mixed> $params */
            public function get(array $params): never
            {
                throw $this->exception;
            }
        };

        $this->expectException(ResponseException::class);
        ModelArticle::find('missing', ClientAdapterStub::client($raw));
    }

    public function testFindRethrowsUnrecognizedHttp404(): void
    {
        $officialException = (new OfficialClientResponseException('not found', 404))
            ->setResponse(new Psr7Response(404, [], 'not-json'));
        $raw = new class ($officialException) {
            public function __construct(private \Throwable $exception) {}

            /** @param array<string, mixed> $params */
            public function get(array $params): never
            {
                throw $this->exception;
            }
        };

        $this->expectException(ResponseException::class);
        ModelArticle::find('missing', ClientAdapterStub::client($raw));
    }

    public function testDeleteMarksModelAsNotExisting(): void
    {
        $raw = new class {
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function delete(array $params): array
            {
                return ['_id' => $params['id'], 'result' => 'deleted'];
            }
        };
        $client = ClientAdapterStub::client($raw);
        $model = (new ModelArticle(['title' => 'PHP']))->setKey('article-3');

        self::assertTrue($model->delete($client));
        self::assertFalse($model->exists());
    }

    public function testCrudAcceptsExplicitClientOutsideContainer(): void
    {
        $raw = new class {
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function index(array $params): array
            {
                return ['_id' => $params['id'] ?? 'created', 'result' => 'created'];
            }

            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function get(array $params): array
            {
                return ['_id' => $params['id'], 'found' => true, '_source' => ['title' => 'default']];
            }

            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function delete(array $params): array
            {
                return ['_id' => $params['id'], 'result' => 'deleted'];
            }
        };
        $client = ClientAdapterStub::client($raw);

        $created = DefaultClientModelArticle::create(['title' => 'created'], 'article-4', $client);
        self::assertTrue($created->exists());
        self::assertSame('default', DefaultClientModelArticle::find('article-4', $client)?->getAttribute('title'));
        self::assertTrue($created->delete($client));
    }

    /** 显式 null 必须在属性读取和所有 cast 中保持，不能变成默认值或当前时间。 */
    public function testNullableAttributesAndCastsPreserveNull(): void
    {
        $model = new ModelArticle([
            'score' => null,
            'published_at' => null,
        ]);

        self::assertNull($model->getAttribute('score', 10));
        self::assertNull($model->getAttribute('published_at', 'fallback'));
        self::assertSame(['score' => null, 'published_at' => null], $model->toArray());
        self::assertSame('fallback', $model->getAttribute('missing', 'fallback'));
    }

}
