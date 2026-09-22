<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Client;

use SllhSmile\Elasticsearch\Contract\ClientInterface;
use SllhSmile\Elasticsearch\Exception\UnsupportedCapabilityException;

/** 管理 Point In Time 搜索上下文，并保证使用结束后释放资源。 */
final class PitManager
{
    /** 绑定客户端；每次操作前仍会检查 PIT 能力。 */
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /** 打开指定索引的 PIT，并返回后续搜索所需的 PIT ID。 */
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

    /** 关闭 PIT 并返回官方 endpoint 响应。 */
    public function close(string $pitId): mixed
    {
        $this->assertSupported();
        return $this->client->call('pit.close', ['body' => ['id' => $pitId]]);
    }

    /** 在回调期间持有 PIT，并通过 finally 保证正常返回或异常时都尝试关闭。 */
    public function using(string $index, callable $callback, string $keepAlive = '1m'): mixed
    {
        $pitId = $this->open($index, $keepAlive);
        try {
            return $callback($pitId);
        } finally {
            $this->close($pitId);
        }
    }

    /** 在发起请求前拒绝当前客户端不支持的 PIT 操作。 */
    private function assertSupported(): void
    {
        if (! $this->client->capabilities()->pit) {
            throw new UnsupportedCapabilityException('Point in Time is not supported by the configured Elasticsearch client.');
        }
    }
}
