<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use PHPUnit\Framework\TestCase;
use SllhSmile\Elasticsearch\Builder\QueryBuilder;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Client\PitManager;
use SllhSmile\Elasticsearch\Index\IndexManager;
use SllhSmile\Elasticsearch\Model\DocumentModel;

final class RequestBodyArticle extends DocumentModel
{
    protected string $index = 'articles';
}

final class RequestBodyTest extends TestCase
{
    public function testSearchDslIsSentAsBody(): void
    {
        $raw = new class {
            public array $params = [];
            public function search(array $params): array
            {
                $this->params = $params;
                return ['hits' => ['total' => 0, 'hits' => []]];
            }
        };
        $client = new ElasticsearchClient($raw);
        (new QueryBuilder($client, RequestBodyArticle::class, 'articles'))->where('status', 'published')->search();
        self::assertSame('articles', $raw->params['index']);
        self::assertSame('published', $raw->params['body']['query']['bool']['filter'][0]['term']['status']);
    }

    public function testIndexAndAliasRequestsUseBodies(): void
    {
        $indices = new class {
            public array $calls = [];
            public function create(array $params): array { $this->calls[] = ['create', $params]; return []; }
            public function putMapping(array $params): array { $this->calls[] = ['mapping', $params]; return []; }
            public function putSettings(array $params): array { $this->calls[] = ['settings', $params]; return []; }
            public function updateAliases(array $params): array { $this->calls[] = ['aliases', $params]; return []; }
        };
        $raw = new class($indices) {
            public function __construct(private object $indices) {}
            public function indices(): object { return $this->indices; }
        };
        $manager = new IndexManager(new ElasticsearchClient($raw));
        $manager->create('articles', ['number_of_shards' => 1], ['properties' => ['title' => ['type' => 'text']]]);
        $manager->putMapping('articles', ['status' => ['type' => 'keyword']]);
        $manager->putSettings('articles', ['refresh_interval' => '1s']);
        $manager->addAlias('articles', 'articles_current');
        self::assertSame(['settings' => ['number_of_shards' => 1], 'mappings' => ['properties' => ['title' => ['type' => 'text']]]], $indices->calls[0][1]['body']);
        self::assertSame(['properties' => ['status' => ['type' => 'keyword']]], $indices->calls[1][1]['body']);
        self::assertSame(['refresh_interval' => '1s'], $indices->calls[2][1]['body']);
        self::assertArrayHasKey('actions', $indices->calls[3][1]['body']);
    }

    public function testPitCloseUsesBody(): void
    {
        $raw = new class {
            public array $close = [];
            public function closePointInTime(array $params): array { $this->close = $params; return []; }
        };
        $manager = new PitManager(new ElasticsearchClient($raw));
        $manager->close('pit-1');
        self::assertSame(['id' => 'pit-1'], $raw->close['body']);
    }

    public function testMinimumShouldMatchPreservesPercentage(): void
    {
        $client = new ElasticsearchClient(new \stdClass());
        $dsl = (new QueryBuilder($client, RequestBodyArticle::class, 'articles'))
            ->should(fn (QueryBuilder $q) => $q->whereMatch('title', 'php'))
            ->minimumShouldMatch('50%')
            ->toDsl();
        self::assertSame('50%', $dsl['query']['bool']['minimum_should_match']);
    }

    public function testMethodSpecificRequestEntrypointsDispatchToOfficialEndpoints(): void
    {
        $raw = new class {
            public array $calls = [];
            public function info(array $params): array { $this->calls[] = ['info', $params]; return []; }
            public function bulk(array $params): array { $this->calls[] = ['bulk', $params]; return []; }
        };
        $client = new ElasticsearchClient($raw);

        $client->requestGet('/');
        $client->requestPost('/_bulk', [], [['index' => ['_index' => 'articles']]]);

        self::assertSame('info', $raw->calls[0][0]);
        self::assertSame('bulk', $raw->calls[1][0]);
        self::assertArrayHasKey('body', $raw->calls[1][1]);
    }
}
