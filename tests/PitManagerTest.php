<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Tests\Support\ClientAdapterStub;
use SllhSmile\Elasticsearch\Client\PitManager;
use Stringable;

final class PitFakeClient
{
    /** @var array<string, mixed> */
    public array $openParams = [];

    /** @var array<string, mixed> */
    public array $closeParams = [];

    public function __construct(private readonly ?\Throwable $closeFailure = null) {}

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    public function openPointInTime(array $params): array
    {
        $this->openParams = $params;
        return ['id' => 'pit-1'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, bool>
     */
    public function closePointInTime(array $params): array
    {
        $this->closeParams = $params;
        if ($this->closeFailure !== null) {
            throw $this->closeFailure;
        }
        return ['succeeded' => true];
    }
}

final class PitManagerTest extends TestCase
{
    public function testUsingOpensCallsAndClosesPit(): void
    {
        $raw = $this->pitClient();
        $result = (new PitManager(ClientAdapterStub::client($raw)))
            ->using('articles', static fn(string $id): string => "used-{$id}", '2m');

        self::assertSame('used-pit-1', $result);
        self::assertSame(['index' => 'articles', 'keep_alive' => '2m'], $raw->openParams);
        self::assertSame(['body' => ['id' => 'pit-1']], $raw->closeParams);
    }

    public function testCallbackFailureIsRethrownAfterSuccessfulClose(): void
    {
        $raw = $this->pitClient();
        $failure = new \RuntimeException('callback failed');
        try {
            (new PitManager(ClientAdapterStub::client($raw)))->using('articles', static function () use ($failure): never {
                throw $failure;
            });
            self::fail('Expected callback failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
            self::assertSame('pit-1', $raw->closeParams['body']['id']);
        }
    }

    public function testCloseFailureIsThrownAfterSuccessfulCallback(): void
    {
        $closeFailure = new \RuntimeException('close failed');
        $raw = $this->pitClient($closeFailure);
        $this->expectExceptionObject($closeFailure);
        (new PitManager(ClientAdapterStub::client($raw)))->using('articles', static fn(): string => 'ok');
    }

    public function testCallbackFailureWinsAndCloseFailureIsLogged(): void
    {
        $callbackFailure = new \RuntimeException('callback failed');
        $closeFailure = new \RuntimeException('close failed');
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<string, mixed>}> */
            public array $records = [];
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };

        try {
            (new PitManager(ClientAdapterStub::client($this->pitClient($closeFailure)), $logger))
                ->using('articles', static function () use ($callbackFailure): never {
                    throw $callbackFailure;
                });
            self::fail('Expected callback failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($callbackFailure, $exception);
            self::assertCount(1, $logger->records);
            self::assertSame('close failed', $logger->records[0][2]['exception']->getMessage());
            self::assertSame($closeFailure, $logger->records[0][2]['exception']);
            self::assertSame(hash('sha256', 'pit-1'), $logger->records[0][2]['pit_id_hash']);
        }
    }

    private function pitClient(?\Throwable $closeFailure = null): PitFakeClient
    {
        return new PitFakeClient($closeFailure);
    }
}
