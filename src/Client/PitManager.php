<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Client;

/** 管理 Point In Time 搜索上下文，并保证使用结束后释放资源。 */
final class PitManager
{
    /** 使用一个已构造的 ES 客户端执行 PIT endpoint。 */
    public function __construct(private readonly ElasticsearchClient $client)
    {
    }

    /** 打开 PIT 并返回 ES 生成的 id；会发起网络请求。 */
    public function open(string $index, string $keepAlive = '1m'): string
    {
        $response = $this->client->execute(static fn (object $client): mixed => $client->openPointInTime([
            'index' => $index,
            'keep_alive' => $keepAlive,
        ]));
        $raw = is_object($response) && method_exists($response, 'asArray') ? $response->asArray() : (array) $response;
        if (! isset($raw['id'])) {
            throw new \RuntimeException('Elasticsearch did not return a PIT id.');
        }
        return (string) $raw['id'];
    }

    /** 关闭 PIT；ES9 要求 id 放在请求 body.id 中。 */
    public function close(string $pitId): mixed
    {
        return $this->client->execute(static fn (object $client): mixed => $client->closePointInTime([
            'body' => ['id' => $pitId],
        ]));
    }

    /** 打开 PIT 执行回调，并在成功或异常时通过 finally 关闭。 */
    public function using(string $index, callable $callback, string $keepAlive = '1m'): mixed
    {
        $pitId = $this->open($index, $keepAlive);
        try {
            return $callback($pitId);
        } finally {
            $this->close($pitId);
        }
    }
}
