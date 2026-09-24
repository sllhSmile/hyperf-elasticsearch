<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use PHPUnit\Framework\TestCase;
use Elastic\Elasticsearch\Exception\ClientResponseException as OfficialClientResponseException;
use Elastic\Transport\Exception\InvalidArgumentException as OfficialInvalidArgumentException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use SllhSmile\Elasticsearch\Builder\QueryBuilder;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Exception\ResponseException;
use SllhSmile\Elasticsearch\Exception\TransportException;
use SllhSmile\Elasticsearch\Client\PitManager;
use SllhSmile\Elasticsearch\Index\IndexManager;
use SllhSmile\Elasticsearch\Model\DocumentModel;
use SllhSmile\Elasticsearch\Tests\Support\ClientAdapterStub;

final class RequestBodyArticle extends DocumentModel
{
    protected string $index = 'articles';
}

final class RequestBodyTest extends TestCase
{
    public function testSearchDslIsSentAsBody(): void
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
                return ['hits' => ['total' => 0, 'hits' => []]];
            }
        };
        $client = ClientAdapterStub::client($raw);
        (new QueryBuilder($client, RequestBodyArticle::class, 'articles'))->where('status', 'published')->search();
        self::assertSame('articles', $raw->params['index']);
        self::assertSame('published', $raw->params['body']['query']['bool']['filter'][0]['term']['status']);
    }

    public function testIndexAndAliasRequestsUseBodies(): void
    {
        $indices = new class {
            /** @var list<array{string, array<string, mixed>}> */
            public array $calls = [];
            /** @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function create(array $params): array
            {
                $this->calls[] = ['create', $params];
                return [];
            }
            /** @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function putMapping(array $params): array
            {
                $this->calls[] = ['mapping', $params];
                return [];
            }
            /** @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function putSettings(array $params): array
            {
                $this->calls[] = ['settings', $params];
                return [];
            }
            /** @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function updateAliases(array $params): array
            {
                $this->calls[] = ['aliases', $params];
                return [];
            }
        };
        $raw = new class ($indices) {
            public function __construct(private object $indices) {}
            public function indices(): object
            {
                return $this->indices;
            }
        };
        $manager = new IndexManager(ClientAdapterStub::client($raw));
        $manager->create('articles', ['number_of_shards' => 1], ['properties' => ['title' => ['type' => 'text']]]);
        $manager->putMapping('articles', ['status' => ['type' => 'keyword']]);
        $manager->putSettings('articles', ['refresh_interval' => '1s']);
        $manager->addAlias('articles', 'articles_current', ['is_write_index' => true]);
        $manager->removeAlias('articles', 'articles_old');
        $manager->switchAlias('articles_current', 'articles', 'articles-v2');
        self::assertSame(['settings' => ['number_of_shards' => 1], 'mappings' => ['properties' => ['title' => ['type' => 'text']]]], $indices->calls[0][1]['body']);
        self::assertSame(['properties' => ['status' => ['type' => 'keyword']]], $indices->calls[1][1]['body']);
        self::assertSame(['refresh_interval' => '1s'], $indices->calls[2][1]['body']);
        self::assertArrayHasKey('actions', $indices->calls[3][1]['body']);
        self::assertSame(
            ['add' => ['is_write_index' => true, 'index' => 'articles', 'alias' => 'articles_current']],
            $indices->calls[3][1]['body']['actions'][0],
        );
        self::assertSame(
            [['remove' => ['index' => 'articles', 'alias' => 'articles_old']]],
            $indices->calls[4][1]['body']['actions'],
        );
        self::assertSame([
            ['remove' => ['index' => 'articles', 'alias' => 'articles_current']],
            ['add' => ['index' => 'articles-v2', 'alias' => 'articles_current']],
        ], $indices->calls[5][1]['body']['actions']);
    }

    public function testPitCloseUsesBody(): void
    {
        $raw = new class {
            /** @var array<string, mixed> */
            public array $close = [];
            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function closePointInTime(array $params): array
            {
                $this->close = $params;
                return [];
            }
        };
        $manager = new PitManager(ClientAdapterStub::client($raw));
        $manager->close('pit-1');
        self::assertSame(['id' => 'pit-1'], $raw->close['body']);
    }

    public function testMinimumShouldMatchPreservesPercentage(): void
    {
        $client = ClientAdapterStub::client(new \stdClass());
        $dsl = (new QueryBuilder($client, RequestBodyArticle::class, 'articles'))
            ->should(fn(QueryBuilder $q) => $q->whereMatch('title', 'php'))
            ->minimumShouldMatch('50%')
            ->toDsl();
        self::assertSame('50%', $dsl['query']['bool']['minimum_should_match']);
    }

    public function testExecuteCallsUnhandledOfficialEndpoint(): void
    {
        $raw = new class {
            public function ping(): string
            {
                return 'pong';
            }
        };
        $client = ClientAdapterStub::client($raw);
        self::assertSame('pong', $client->execute(static function (object $official): string {
            if (! method_exists($official, 'ping')) {
                throw new \RuntimeException('Expected ping method.');
            }
            return $official->ping();
        }));
    }

    public function testExecuteNormalizesOfficialException(): void
    {
        $exception = $this->officialResponseException(400);
        $client = ClientAdapterStub::client(new \stdClass());
        try {
            $client->execute(static function () use ($exception): never {
                throw $exception;
            });
            self::fail('Expected ResponseException was not thrown.');
        } catch (ResponseException $normalized) {
            self::assertSame($exception, $normalized->getPrevious());
        }
    }

    /** execute() 中的业务异常不属于 SDK 边界，必须保留原始类型与实例。 */
    public function testExecutePreservesBusinessException(): void
    {
        $failure = new \LogicException('business failed');
        $client = ClientAdapterStub::client(new \stdClass());
        try {
            $client->execute(static function () use ($failure): never {
                throw $failure;
            });
            self::fail('Expected the business exception.');
        } catch (\LogicException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    /** 不支持的 operation 按 ClientInterface 契约保持 BadMethodCallException。 */
    public function testUnsupportedOperationPreservesContractException(): void
    {
        $this->expectException(\BadMethodCallException::class);
        ClientAdapterStub::client(new \stdClass())->call('unsupported');
    }

    public function testOfficialResponseExceptionIsNormalized(): void
    {
        $officialException = $this->officialResponseException(400);
        $raw = new class ($officialException) {
            public function __construct(private \Throwable $exception) {}

            /** @param array<string, mixed> $params */
            public function search(array $params): never
            {
                throw $this->exception;
            }
        };

        try {
            ClientAdapterStub::client($raw)->search(['index' => 'articles', 'body' => []]);
            self::fail('Expected ResponseException was not thrown.');
        } catch (ResponseException $exception) {
            self::assertSame(400, $exception->statusCode());
            self::assertSame($officialException, $exception->getPrevious());
        }
    }

    public function testOfficialTransportExceptionIsNormalized(): void
    {
        $raw = new class ($this->officialTransportException()) {
            public function __construct(private \Throwable $exception) {}

            /** @param array<string, mixed> $params */
            public function search(array $params): never
            {
                throw $this->exception;
            }
        };

        $this->expectException(TransportException::class);
        ClientAdapterStub::client($raw)->search(['index' => 'articles', 'body' => []]);
    }

    public function testTransportFailureResetsLazyClientForTheNextRequest(): void
    {
        $builds = 0;
        $transportException = $this->officialTransportException();
        $client = ElasticsearchClient::fromFactory(function () use (&$builds, $transportException): ClientAdapterStub {
            $builds++;
            if ($builds === 1) {
                $raw = new class ($transportException) {
                    public function __construct(private \Throwable $exception) {}

                    /** @param array<string, mixed> $params */
                    public function search(array $params): never
                    {
                        throw $this->exception;
                    }
                };
                return new ClientAdapterStub($raw);
            }

            $raw = new class {
                /**
                 * @param array<string, mixed> $params
                 * @return array<string, mixed>
                 */
                public function search(array $params): array
                {
                    return ['hits' => ['total' => 0, 'hits' => []]];
                }
            };
            return new ClientAdapterStub($raw);
        });

        try {
            $client->search(['index' => 'articles', 'body' => []]);
            self::fail('Expected the first request to fail.');
        } catch (TransportException) {
        }

        self::assertSame(['hits' => ['total' => 0, 'hits' => []]], $client->responseToArray(
            $client->search(['index' => 'articles', 'body' => []]),
        ));
        self::assertSame(2, $builds);
    }

    public function testInitializationFailureDoesNotBuildAgainToNormalizeIt(): void
    {
        $builds = 0;
        $client = ElasticsearchClient::fromFactory(static function () use (&$builds): never {
            $builds++;
            throw new \RuntimeException('build failed');
        });

        try {
            $client->search(['index' => 'articles', 'body' => []]);
            self::fail('Expected initialization failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('build failed', $exception->getMessage());
            self::assertSame(1, $builds);
        }
    }

    /** SDK 参数错误不会清理 Adapter 或重建官方客户端。 */
    public function testNonNetworkSdkFailureDoesNotRebuildClient(): void
    {
        $builds = 0;
        $client = ElasticsearchClient::fromFactory(static function () use (&$builds): ClientAdapterStub {
            $builds++;
            return new ClientAdapterStub(new class {
                /** @param array<string, mixed> $params */
                public function search(array $params): never
                {
                    throw new OfficialInvalidArgumentException('bad payload');
                }
            });
        });

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $client->search(['index' => 'articles']);
            } catch (\SllhSmile\Elasticsearch\Exception\ElasticsearchException) {
            }
        }
        self::assertSame(1, $builds);
    }

    /** 旧请求延迟返回的传输失败不得清除已重建的健康 Adapter。 */
    public function testLateFailureFromOldAdapterDoesNotDiscardReplacement(): void
    {
        $builds = 0;
        $failure = $this->officialTransportException();
        $client = ElasticsearchClient::fromFactory(static function () use (&$builds, $failure): ClientAdapterStub {
            $builds++;
            if ($builds === 1) {
                return new ClientAdapterStub(new class ($failure) {
                    private int $calls = 0;
                    public function __construct(private readonly \Throwable $failure) {}
                    /** @param array<string, mixed> $params */
                    public function search(array $params): never
                    {
                        if (++$this->calls === 1) {
                            \Fiber::suspend();
                        }
                        throw $this->failure;
                    }
                });
            }
            return new ClientAdapterStub(new class {
                /**
                 * @param array<string, mixed> $params
                 * @return array<string, mixed>
                 */
                public function search(array $params): array
                {
                    return ['hits' => ['total' => 0, 'hits' => []]];
                }
            });
        });

        $lateFailure = null;
        $fiber = new \Fiber(static function () use ($client, &$lateFailure): void {
            try {
                $client->search(['index' => 'articles']);
            } catch (TransportException $exception) {
                $lateFailure = $exception;
            }
        });
        $fiber->start();
        try {
            $client->search(['index' => 'articles']);
        } catch (TransportException) {
        }
        self::assertSame(['hits' => ['total' => 0, 'hits' => []]], $client->responseToArray(
            $client->search(['index' => 'articles']),
        ));
        $fiber->resume();
        self::assertInstanceOf(TransportException::class, $lateFailure);
        $client->search(['index' => 'articles']);
        self::assertSame(2, $builds);
    }

    public function testFixedClientIsNotRebuiltAfterTransportFailure(): void
    {
        $exception = $this->officialTransportException();
        $raw = new class ($exception) {
            public int $calls = 0;
            public function __construct(private \Throwable $exception) {}
            /** @param array<string, mixed> $params */
            public function search(array $params): never
            {
                $this->calls++;
                throw $this->exception;
            }
        };
        $client = ClientAdapterStub::client($raw);
        for ($i = 0; $i < 2; $i++) {
            try {
                $client->search(['index' => 'articles', 'body' => []]);
            } catch (TransportException) {
            }
        }
        self::assertSame(2, $raw->calls);
    }

    public function testResponseNormalizationWorksForArrayAndResponseObjects(): void
    {
        $client = ClientAdapterStub::client(new \stdClass());
        self::assertSame(['hits' => []], $client->responseToArray(['hits' => []]));
        self::assertSame(['hits' => []], $client->responseToArray(new class {
            /** @return array<string, mixed> */
            public function asArray(): array
            {
                return ['hits' => []];
            }
        }));
        self::assertTrue($client->responseToBool(new class {
            public function asBool(): bool
            {
                return true;
            }
        }));
    }

    /** 创建 SDK 8/9 共用的响应异常，验证状态码与 previous 保留。 */
    private function officialResponseException(int $status): \Throwable
    {
        return (new OfficialClientResponseException('bad request', $status))
            ->setResponse(new Psr7Response($status, [], '{"error":"bad"}'));
    }

    /** 创建 SDK 8/9 共用的节点不可用异常，验证传输故障重建。 */
    private function officialTransportException(): \Throwable
    {
        return new \Elastic\Transport\Exception\NoNodeAvailableException('offline');
    }
}
