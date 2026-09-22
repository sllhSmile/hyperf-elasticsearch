<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Index;

use SllhSmile\Elasticsearch\Contract\ClientInterface;

/** 索引、mapping、settings 与 alias 管理器。 */
final class IndexManager
{
    /** 绑定版本无关客户端，所有索引操作仍通过统一异常边界执行。 */
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /** 创建索引，并省略空的 settings 或 mappings 节点。 */
    public function create(string $index, array $settings = [], array $mappings = []): mixed
    {
        $body = array_filter(['settings' => $settings, 'mappings' => $mappings], static fn (mixed $v): bool => $v !== []);
        return $this->client->call('indices.create', ['index' => $index, 'body' => $body]);
    }

    /** 删除指定索引。 */
    public function delete(string $index): mixed
    {
        return $this->client->call('indices.delete', ['index' => $index]);
    }

    /** 判断索引是否存在，并统一不同客户端版本的布尔响应。 */
    public function exists(string $index): bool
    {
        return $this->client->responseToBool($this->client->call('indices.exists', ['index' => $index]));
    }

    /** 获取指定索引的 mapping。 */
    public function getMapping(string $index): mixed
    {
        return $this->client->call('indices.getMapping', ['index' => $index]);
    }

    /** 更新指定索引的字段 properties，并透传额外 endpoint 参数。 */
    public function putMapping(string $index, array $properties, array $options = []): mixed
    {
        return $this->client->call('indices.putMapping', array_replace($options, [
            'index' => $index,
            'body' => ['properties' => $properties],
        ]));
    }

    /** 获取指定索引的 settings。 */
    public function getSettings(string $index): mixed
    {
        return $this->client->call('indices.getSettings', ['index' => $index]);
    }

    /** 更新指定索引的动态 settings。 */
    public function putSettings(string $index, array $settings): mixed
    {
        return $this->client->call('indices.putSettings', ['index' => $index, 'body' => $settings]);
    }

    /** 通过原子 alias action 为索引添加别名。 */
    public function addAlias(string $index, string $alias, array $options = []): mixed
    {
        $add = array_replace($options, ['index' => $index, 'alias' => $alias]);
        return $this->client->call('indices.updateAliases', ['body' => ['actions' => [['add' => $add]]]]);
    }

    /** 通过原子 alias action 从索引移除别名。 */
    public function removeAlias(string $index, string $alias): mixed
    {
        return $this->client->call('indices.updateAliases', ['body' => ['actions' => [[
            'remove' => ['index' => $index, 'alias' => $alias],
        ]]]]);
    }

    /** 在同一次 updateAliases 请求中将别名从旧索引切换到新索引。 */
    public function switchAlias(string $alias, string $oldIndex, string $newIndex): mixed
    {
        return $this->client->call('indices.updateAliases', ['body' => ['actions' => [
            ['remove' => ['index' => $oldIndex, 'alias' => $alias]],
            ['add' => ['index' => $newIndex, 'alias' => $alias]],
        ]]]);
    }
}
