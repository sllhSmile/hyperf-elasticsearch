<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Bulk;

use SllhSmile\Elasticsearch\Client\ElasticsearchClient;

/**
 * Bulk 执行器：按块组织动作并汇总 ES 的逐项结果。
 */
final class BulkManager
{
    /** 注入客户端并校验每个 bulk 请求的最大动作数。 */
    public function __construct(private readonly ElasticsearchClient $client, private readonly int $chunkSize = 500)
    {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException('Bulk chunk size must be greater than zero.');
        }
    }

    /**
     * 分块发送 Bulk 请求。每块 body 是按 metadata/source 顺序排列的数组，
     * 官方客户端随后将其编码为 NDJSON；该方法会发起一次或多次网络请求。
     *
     * $options 会合并到每个 Bulk 请求，例如传入 ['refresh' => 'wait_for']
     * 可确保写入完成后马上能被搜索；body 始终由本方法生成，调用方不能覆盖。
     *
     * @param list<BulkOperation> $operations
     * @param array<string, mixed> $options
     */
    public function execute(array $operations, array $options = []): BulkResult
    {
        $allItems = [];
        $errors = 0;
        $rawResponses = [];
        foreach (array_chunk($operations, $this->chunkSize) as $chunk) {
            $body = [];
            foreach ($chunk as $operation) {
                foreach ($operation->toNdjsonLines() as $line) {
                    $body[] = $line;
                }
            }
            $response = $this->client->bulk(array_replace($options, ['body' => $body]));
            $raw = is_object($response) && method_exists($response, 'asArray') ? $response->asArray() : (array) $response;
            $rawResponses[] = $raw;
            foreach ((array) ($raw['items'] ?? []) as $item) {
                $item = (array) $item;
                $action = array_key_first($item);
                $detail = (array) ($item[$action] ?? []);
                if (($detail['status'] ?? 200) >= 300 || isset($detail['error'])) {
                    $errors++;
                }
                $allItems[] = $item;
            }
        }
        return new BulkResult($allItems, $errors, $rawResponses);
    }
}
