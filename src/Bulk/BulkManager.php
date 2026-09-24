<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Bulk;

use SllhSmile\Elasticsearch\Contract\ClientInterface;
use SllhSmile\Elasticsearch\Exception\BulkExecutionException;
use SllhSmile\Elasticsearch\Exception\BulkProtocolException;
use Throwable;

/** Bulk 执行器：按块组织动作并汇总 ES 的逐项结果。 */
final class BulkManager
{
    /** @var positive-int */
    private readonly int $chunkSize;

    /** 绑定客户端并校验每次请求允许包含的最大操作数。 */
    public function __construct(private readonly ClientInterface $client, int $chunkSize = 500)
    {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException('Bulk chunk size must be greater than zero.');
        }
        $this->chunkSize = $chunkSize;
    }

    /**
     * 按 chunkSize 拆分操作并汇总逐项结果；不会因单个 item 失败而提前终止。
     *
     * @param list<BulkOperation> $operations
     * @param array<string, mixed> $options
     */
    public function execute(array $operations, array $options = []): BulkResult
    {
        $allItems = [];
        $errors = 0;
        $rawResponses = [];
        foreach (array_chunk($operations, $this->chunkSize) as $chunkIndex => $chunk) {
            $body = [];
            foreach ($chunk as $operation) {
                foreach ($operation->toNdjsonLines() as $line) {
                    $body[] = $line;
                }
            }
            try {
                $response = $this->client->bulk(array_replace($options, ['body' => $body]));
                $raw = $this->client->responseToArray($response);
            } catch (Throwable $exception) {
                throw new BulkExecutionException(new BulkResult($allItems, $errors, $rawResponses), $chunkIndex + 1, $exception);
            }
            // HTTP 成功不代表每个动作成功；结构不完整时失败块不能进入汇总。
            try {
                $this->validateItems($raw, $chunk);
            } catch (\UnexpectedValueException $exception) {
                throw new BulkProtocolException(new BulkResult($allItems, $errors, $rawResponses), $chunkIndex + 1, $exception);
            }
            $rawResponses[] = $raw;
            foreach ($raw['items'] as $item) {
                $detail = reset($item);
                if ($detail['status'] >= 300 || isset($detail['error'])) {
                    ++$errors;
                }
                $allItems[] = $item;
            }
        }
        return new BulkResult($allItems, $errors, $rawResponses);
    }

    /**
     * @param array<mixed> $raw
     * @param list<BulkOperation> $chunk
     */
    private function validateItems(array $raw, array $chunk): void
    {
        if (! isset($raw['items']) || ! is_array($raw['items']) || ! array_is_list($raw['items']) || count($raw['items']) !== count($chunk)) {
            throw new \UnexpectedValueException('Bulk response items count does not match the submitted operations.');
        }
        foreach ($raw['items'] as $position => $item) {
            $expected = array_key_first($chunk[$position]->toNdjsonLines()[0]);
            if (! is_array($item) || count($item) !== 1 || ! isset($item[$expected]) || ! is_array($item[$expected])) {
                throw new \UnexpectedValueException('Bulk response item action does not match the submitted operation.');
            }
            $status = $item[$expected]['status'] ?? null;
            if (! is_int($status) || $status < 100 || $status > 599) {
                throw new \UnexpectedValueException('Bulk response item status must be a valid HTTP status code.');
            }
        }
    }
}
