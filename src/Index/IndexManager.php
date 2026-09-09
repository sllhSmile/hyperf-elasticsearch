<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Index;

use SllhSmile\Elasticsearch\Contract\ClientInterface;

/** 索引、mapping、settings 与 alias 管理器。 */
final class IndexManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function create(string $index, array $settings = [], array $mappings = []): mixed
    {
        $body = array_filter(['settings' => $settings, 'mappings' => $mappings], static fn (mixed $v): bool => $v !== []);
        return $this->client->call('indices.create', ['index' => $index, 'body' => $body]);
    }

    public function delete(string $index): mixed
    {
        return $this->client->call('indices.delete', ['index' => $index]);
    }

    public function exists(string $index): bool
    {
        return $this->client->responseToBool($this->client->call('indices.exists', ['index' => $index]));
    }

    public function getMapping(string $index): mixed
    {
        return $this->client->call('indices.getMapping', ['index' => $index]);
    }

    public function putMapping(string $index, array $properties, array $options = []): mixed
    {
        return $this->client->call('indices.putMapping', array_replace($options, [
            'index' => $index,
            'body' => ['properties' => $properties],
        ]));
    }

    public function getSettings(string $index): mixed
    {
        return $this->client->call('indices.getSettings', ['index' => $index]);
    }

    public function putSettings(string $index, array $settings): mixed
    {
        return $this->client->call('indices.putSettings', ['index' => $index, 'body' => $settings]);
    }

    public function addAlias(string $index, string $alias, array $options = []): mixed
    {
        $add = array_replace($options, ['index' => $index, 'alias' => $alias]);
        return $this->client->call('indices.updateAliases', ['body' => ['actions' => [['add' => $add]]]]);
    }

    public function removeAlias(string $index, string $alias): mixed
    {
        return $this->client->call('indices.updateAliases', ['body' => ['actions' => [[
            'remove' => ['index' => $index, 'alias' => $alias],
        ]]]]);
    }

    public function switchAlias(string $alias, string $oldIndex, string $newIndex): mixed
    {
        return $this->client->call('indices.updateAliases', ['body' => ['actions' => [
            ['remove' => ['index' => $oldIndex, 'alias' => $alias]],
            ['add' => ['index' => $newIndex, 'alias' => $alias]],
        ]]]);
    }
}
