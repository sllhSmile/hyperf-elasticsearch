<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Index;

use SllhSmile\Elasticsearch\Client\ElasticsearchClient;

/**
 * 索引、mapping、settings 与 alias 管理器；每个公开方法都会访问 ES。
 */
final class IndexManager
{
    /** 使用一个已构造的客户端执行索引 endpoint。 */
    public function __construct(private readonly ElasticsearchClient $client)
    {
    }

    /** 创建索引，settings/mappings 放在请求 body。 */
    public function create(string $index, array $settings = [], array $mappings = []): mixed
    {
        $body = array_filter(['settings' => $settings, 'mappings' => $mappings], static fn (mixed $v): bool => $v !== []);
        return $this->client->execute(static fn (object $client): mixed => $client->indices()->create(['index' => $index, 'body' => $body]));
    }

    /** 删除索引。 */
    public function delete(string $index): mixed
    {
        return $this->client->execute(static fn (object $client): mixed => $client->indices()->delete(['index' => $index]));
    }

    /** 检查索引是否存在，优先读取官方 response 的 asBool。 */
    public function exists(string $index): bool
    {
        $response = $this->client->execute(static fn (object $client): mixed => $client->indices()->exists(['index' => $index]));
        return method_exists($response, 'asBool') ? $response->asBool() : (bool) $response;
    }

    /** 获取索引 mapping。 */
    public function getMapping(string $index): mixed
    {
        return $this->client->execute(static fn (object $client): mixed => $client->indices()->getMapping(['index' => $index]));
    }

    /** 更新 mapping properties；properties 位于 body.properties。 */
    public function putMapping(string $index, array $properties, array $options = []): mixed
    {
        return $this->client->execute(static fn (object $client): mixed => $client->indices()->putMapping(array_replace($options, ['index' => $index, 'body' => ['properties' => $properties]])));
    }

    /** 获取索引 settings。 */
    public function getSettings(string $index): mixed
    {
        return $this->client->execute(static fn (object $client): mixed => $client->indices()->getSettings(['index' => $index]));
    }

    /** 更新索引 settings，配置位于请求 body。 */
    public function putSettings(string $index, array $settings): mixed
    {
        return $this->client->execute(static fn (object $client): mixed => $client->indices()->putSettings(['index' => $index, 'body' => $settings]));
    }

    /** 添加 alias；可通过 options 传入 is_write_index 等 ES 参数。 */
    public function addAlias(string $index, string $alias, array $options = []): mixed
    {
        // Elasticsearch 要求 is_write_index、filter 等选项位于 add 动作内部，
        // 不能与 add 处于同一层级；同时固定 index/alias，避免 options 意外改写目标。
        $add = array_replace($options, ['index' => $index, 'alias' => $alias]);
        return $this->client->execute(static fn (object $client): mixed => $client->indices()->updateAliases([
            'body' => ['actions' => [['add' => $add]]],
        ]));
    }

    /** 移除 alias。 */
    public function removeAlias(string $index, string $alias): mixed
    {
        return $this->client->execute(static fn (object $client): mixed => $client->indices()->updateAliases([
            'body' => ['actions' => [['remove' => ['index' => $index, 'alias' => $alias]]]],
        ]));
    }

    /** 在单次 updateAliases 请求中原子切换 alias。 */
    public function switchAlias(string $alias, string $oldIndex, string $newIndex): mixed
    {
        return $this->client->execute(static fn (object $client): mixed => $client->indices()->updateAliases(['body' => ['actions' => [
            ['remove' => ['index' => $oldIndex, 'alias' => $alias]],
            ['add' => ['index' => $newIndex, 'alias' => $alias]],
        ]]]));
    }
}
