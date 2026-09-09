<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Client;

use SllhSmile\Elasticsearch\Contract\ClientInterface;
use SllhSmile\Elasticsearch\Exception\UnsupportedCapabilityException;

/** 管理 Point In Time 搜索上下文，并保证使用结束后释放资源。 */
final class PitManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function open(string $index, string $keepAlive = '1m'): string
    {
        $this->assertSupported();
        $response = $this->client->call('pit.open', ['index' => $index, 'keep_alive' => $keepAlive]);
        $raw = $this->client->responseToArray($response);
        if (! isset($raw['id'])) {
            throw new \RuntimeException('Elasticsearch did not return a PIT id.');
        }
        return (string) $raw['id'];
    }

    public function close(string $pitId): mixed
    {
        $this->assertSupported();
        return $this->client->call('pit.close', ['body' => ['id' => $pitId]]);
    }

    public function using(string $index, callable $callback, string $keepAlive = '1m'): mixed
    {
        $pitId = $this->open($index, $keepAlive);
        try {
            return $callback($pitId);
        } finally {
            $this->close($pitId);
        }
    }

    private function assertSupported(): void
    {
        if (! $this->client->capabilities()->pit) {
            throw new UnsupportedCapabilityException('Point in Time is not supported by the configured Elasticsearch client.');
        }
    }
}
