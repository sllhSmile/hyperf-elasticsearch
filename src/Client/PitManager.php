<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Client;

use Psr\Log\LoggerInterface;
use SllhSmile\Elasticsearch\Contract\ClientInterface;
use Throwable;

/** 管理 Point In Time 搜索上下文，并保证使用结束后释放资源。 */
final class PitManager
{
    /** 绑定客户端和可选日志器；PIT 支持由真实服务端响应决定。 */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** 打开指定索引的 PIT，并返回后续搜索所需的 PIT ID。 */
    public function open(string $index, string $keepAlive = '1m'): string
    {
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
        return $this->client->call('pit.close', ['body' => ['id' => $pitId]]);
    }

    /**
     * 在回调期间持有 PIT，并始终尝试关闭。
     *
     * 回调和关闭同时失败时保留业务异常；关闭异常仅用于诊断，不能覆盖主失败原因。
     */
    public function using(string $index, callable $callback, string $keepAlive = '1m'): mixed
    {
        $pitId = $this->open($index, $keepAlive);
        $callbackException = null;
        $result = null;
        try {
            $result = $callback($pitId);
        } catch (Throwable $exception) {
            $callbackException = $exception;
        }

        try {
            $this->close($pitId);
        } catch (Throwable $closeException) {
            if ($callbackException === null) {
                throw $closeException;
            }
            $this->logCloseFailure($index, $pitId, $closeException);
        }

        if ($callbackException !== null) {
            throw $callbackException;
        }
        return $result;
    }

    /** 记录清理失败；日志组件自身异常不能覆盖原始回调异常。 */
    private function logCloseFailure(string $index, string $pitId, Throwable $exception): void
    {
        if ($this->logger === null) {
            return;
        }
        try {
            $this->logger->warning('Failed to close Elasticsearch PIT after callback failure.', [
                'index' => $index,
                'pit_id_hash' => hash('sha256', $pitId),
                'exception' => $exception,
            ]);
        } catch (Throwable) {
        }
    }
}
