<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Bulk;

use SllhSmile\Elasticsearch\Contract\ClientInterface;

/** Bulk 执行器：按块组织动作并汇总 ES 的逐项结果。 */
final class BulkManager
{
    public function __construct(private readonly ClientInterface $client, private readonly int $chunkSize = 500)
    {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException('Bulk chunk size must be greater than zero.');
        }
    }

    /** @param list<BulkOperation> $operations */
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
            $raw = $this->client->responseToArray($response);
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
