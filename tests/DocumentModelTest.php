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
            /** @var list<array<string, mixed>> */
            public array $createCalls = [];
            /** @var list<array<string, mixed>> */
            public array $updateCalls = [];

            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function index(array $params): array
            {
                $this->indexCalls[] = $params;
                return ['_id' => $params['id'] ?? 'generated-id', 'result' => 'created'];
            }

            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function create(array $params): array
            {
                $this->createCalls[] = $params;
                return ['_id' => $params['id'], 'result' => 'created'];
            }

            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function update(array $params): array
            {
                $this->updateCalls[] = $params;
                return ['_id' => $params['id'], 'result' => 'updated'];
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
        self::assertSame('wait_for', $raw->createCalls[0]['refresh']);
        self::assertSame('article-1', $raw->createCalls[0]['id']);

        self::assertTrue($model->update(['score' => '11'], $client));
        self::assertSame(11, $model->getAttribute('score'));
        self::assertCount(0, $raw->indexCalls);
        self::assertCount(1, $raw->updateCalls);
        self::assertSame(['score' => 11], $raw->updateCalls[0]['body']['doc']);
        self::assertTrue($model->save($client));
        self::assertCount(1, $raw->updateCalls);
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

        $created = new ModelArticle(['title' => 'Draft']);
        self::assertTrue($created->save($client));
        self::assertSame('generated-id', $created->getKey());
        self::assertTrue($created->exists());

        $found = ModelArticle::find('article-2', $client);
        self::assertNotNull($found);
        self::assertSame('article-2', $found->getKey());
        self::assertTrue($found->exists());
        self::assertSame('Elasticsearch', $found->getAttribute('title'));
        self::assertSame(7, $found->getAttribute('score'));
    }

    /** 投影模型的保存只提交被改动字段，且失败后可重试同一改动。 */
    public function testProjectedModelUpdatesOnlyChangedFieldsAndRetainsFailedChanges(): void
    {
        $raw = new class {
            /** @var list<array<string, mixed>> */
            public array $updates = [];
            public bool $fail = true;

            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function update(array $params): array
            {
                $this->updates[] = $params;
                if ($this->fail) {
                    throw new \RuntimeException('temporary failure');
                }
                return ['result' => 'updated'];
            }
        };
        $client = ClientAdapterStub::client($raw);
        $model = ModelArticle::fromSearchHit(new \SllhSmile\Elasticsearch\Response\SearchHit(['title' => 'Old'], 'a-1'));

        try {
            $model->update(['title' => 'New'], $client);
            self::fail('Expected the first update to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('temporary failure', $exception->getMessage());
        }
        self::assertSame('New', $model->getAttribute('title'));
        $raw->fail = false;
        self::assertTrue($model->save($client));
        self::assertSame(['doc' => ['title' => 'New']], $raw->updates[1]['body']);
        self::assertTrue($model->save($client));
        self::assertCount(2, $raw->updates);
    }

    /** Eloquent 风格：未持久化模型的 update 返回 false，属性保持原值。 */
    public function testUpdateOfNewModelReturnsFalse(): void
    {
        $model = new ModelArticle(['title' => 'Draft']);
        self::assertFalse($model->update(['title' => 'Changed']));
        self::assertSame('Draft', $model->getAttribute('title'));
    }

    /** 更新对象后同步已加载字段，后续无改动的 save 不再发送请求。 */
    public function testObjectUpdateKeepsLoadedFieldsInModelAndSnapshot(): void
    {
        $raw = new class {
            /** @var list<array<string, mixed>> */
            public array $updates = [];

            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function update(array $params): array
            {
                $this->updates[] = $params;
                return ['result' => 'updated'];
            }
        };
        $client = ClientAdapterStub::client($raw);
        $model = ModelArticle::fromSearchHit(new \SllhSmile\Elasticsearch\Response\SearchHit([
            'profile' => ['name' => '张三', 'age' => 30, 'address' => ['city' => '上海', 'street' => '旧街']],
            'tags' => ['old'],
        ], 'article-1'));

        self::assertTrue($model->update([
            'profile' => ['name' => '李四', 'address' => ['street' => '新街']],
            'tags' => ['new'],
        ], $client));
        self::assertSame([
            'name' => '李四',
            'age' => 30,
            'address' => ['city' => '上海', 'street' => '新街'],
        ], $model->getAttribute('profile'));
        self::assertSame(['new'], $model->getAttribute('tags'));
        self::assertCount(1, $raw->updates);
        self::assertTrue($model->save($client));
        self::assertCount(1, $raw->updates);
    }

    /** 指定 ID 的创建冲突由 ES 返回，模型仍保持未持久化状态。 */
    public function testCreateWithExistingIdPreservesConflictAndModelState(): void
    {
        $raw = new class {
            /** @param array<string, mixed> $params */
            public function create(array $params): never
            {
                throw new ResponseException('version conflict', 409);
            }
        };
        try {
            ModelArticle::create(['title' => 'Duplicate'], 'a-1', ClientAdapterStub::client($raw));
            self::fail('Expected a 409 conflict.');
        } catch (ResponseException $exception) {
            self::assertSame(409, $exception->statusCode());
        }
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
        $model = ModelArticle::fromSearchHit(new \SllhSmile\Elasticsearch\Response\SearchHit(['title' => 'PHP'], 'article-3'));

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
            public function create(array $params): array
            {
                return ['_id' => $params['id'], 'result' => 'created'];
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
