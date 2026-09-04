# 使用文档

本文以 Hyperf 3.0+ 和 Elasticsearch 9.x 为目标，示例均假定已在容器中取得 `Manager`。除特别说明外，调用链上的方法只是在内存中累积 DSL，`search`、`index`、`create`、`update`、`delete`、Bulk、索引和 PIT 方法才会发起网络请求。

## 1. 安装与配置

```bash
composer require sllhsmile/hyperf-elasticsearch
php bin/hyperf.php vendor:publish sllhsmile/hyperf-elasticsearch --id=elasticsearch-config
```

配置文件为 `config/autoload/elasticsearch.php`。至少配置 `hosts`，云服务建议使用 ApiKey：

```dotenv
ELASTICSEARCH_HOST=https://cluster.example.com:443
ELASTICSEARCH_API_KEY=base64-key
ELASTICSEARCH_RETRIES=2
ELASTICSEARCH_VERIFY_TLS=true
```

不要把密钥提交到 Git 或打印到日志。Handler 无需配置，包使用官方 `Hyperf\\Elasticsearch\\ClientBuilderFactory` 自动选择协程 HTTP Handler；这与 Hyperf 官方 Elasticsearch 客户端的行为一致。HTTP timeout/client_options 不在官方工厂配置范围内，包不会通过 `setHttpClientOptions()` 重建客户端；如需调整请在 Hyperf 全局 Guzzle/Swoole 配置中处理。

## 2. 客户端与连接管理

```php
$manager = $container->get(\SllhSmile\Elasticsearch\Hyperf\Manager::class);
$client = $manager->connection();                 // 默认 default；通常由模型自动解析
$archive = $manager->connection('archive');       // 命名连接
$manager->purge('archive');                       // 丢弃一个缓存客户端
$manager->purge();                                // 丢弃全部客户端
```

`connection(?string $name): ElasticsearchClient` 按名称懒加载并缓存客户端；配置不存在时抛出 `InvalidArgumentException`。`purge` 不访问网络，只清理 Worker 内的客户端引用。多个连接互相隔离，适合不同集群或凭据。

## 3. 协程 HTTP

在 Hyperf 协程中，官方工厂会自动使用 `CoroutineHandler`；如果 Swoole 已启用 native cURL hook，则使用 Guzzle cURL 的协程化路径。业务代码不需要手动创建 Handler 或客户端连接池。

```php
// 同一个连接名：共享 Manager 缓存的 ElasticsearchClient。
$a = Article::query()->where('type', 'a')->search();
$b = Comment::query()->where('visible', true)->search();

// 两个协程可以并发发起请求；底层并发行为由 Hyperf Handler 和 Swoole 配置管理。
Coroutine::create(fn () => Article::query()->count());
Coroutine::create(fn () => Article::query()->count());
```

HTTP 请求是否复用 TCP keep-alive 连接由 Handler 和服务端共同决定；业务代码不需要也不应该为每次查询手动 new 客户端。

### Basic Auth 配置约束

`username` 和 `password` 必须成对出现。只配置其中一个会在连接配置校验阶段抛出
`ConfigurationException`，不会以未认证请求的方式继续访问 Elasticsearch。

## 4. DocumentModel

```php
use SllhSmile\Elasticsearch\Model\DocumentModel;

final class Article extends DocumentModel
{
    protected string $index = 'articles';
    protected string $connection = 'default';
    protected array $casts = ['views' => 'int', 'published_at' => 'datetime'];

    public function mapping(): array { return ['properties' => ['title' => ['type' => 'text']]]; }
    public function settings(): array { return ['number_of_shards' => 1]; }
}

$article = new Article(['title' => 'Hello', 'views' => '2']);
$article->setAttribute('title', 'Updated');
$article->fill(['views' => 3]);
$article->getAttribute('views');
$article->toArray();                         // datetime 等按 casts 输出
$article->toDocument();                      // 写入 ES 的文档数组
Article::query();                            // 按模型 connection 自动解析
```

`getIndexName(): string` 返回索引名；未定义索引时抛出 `LogicException`。`getKey`、`exists`、`getScore`、`getSortValues`、`getHighlight` 分别读取 `_id`、命中状态、相关性分数、排序值和高亮结果。`fromSearchHit(SearchHit $hit): static` 会把 `_source` 和 hit 元数据转换为已存在模型。支持 `int`、`float`、`bool`、`array/json`、`datetime` casts。

## 5. QueryBuilder 基础条件

```php
$query = Article::query()
    ->where('status', 'published')              // filter.term
    ->whereIn('category_id', [1, 2])             // filter.terms
    ->whereNot('deleted', true)                  // must_not.term
    ->whereExists('title')
    ->wherePrefix('slug', 'hyperf-')
    ->whereWildcard('title', '*ES*');
```

`filterTerm`、`mustNotTerm` 是 `where`、`whereNot` 的语义别名。`whereBetween($field, [$from, $to])` 生成 `gte/lte`；`whereRange($field, ['gte' => 10, 'lt' => 20])` 允许完整 range 操作符。

## 6. 全文、Bool 与 Nested

```php
$response = Article::query()
    ->whereMatch('title', '协程', ['operator' => 'and'])
    ->mustMatch('body', 'Elasticsearch')
    ->wherePhrase('title', 'Hyperf Elasticsearch')
    ->whereNested('comments', fn ($q) => $q->whereMatch('comments.text', 'good'))
    ->whereBool(fn ($q) => $q->where('status', 'published')->should(fn ($s) => $s->where('featured', true)))
    ->should(fn ($q) => $q->whereMatch('title', 'PHP'))
    ->minimumShouldMatch('50%')
    ->search();
```

`minimumShouldMatch(int|string)` 保留 ES 的百分比和条件表达式；整数不能为负，空字符串会抛 `InvalidArgumentException`。`should`、`whereBool` 和 `whereNested` 的回调接收独立 builder，最终嵌入父 DSL。

## 7. 排序、分页、高亮、聚合和 raw DSL

```php
$response = Article::query()
    ->select(['title', 'views'])
    ->source(['title'], ['body'])
    ->orderBy('_score', 'desc')
    ->from(0)->size(20)->limit(20)
    ->highlight(['title'], ['pre_tags' => ['<em>'], 'post_tags' => ['</em>']])
    ->aggs('by_status', ['terms' => ['field' => 'status.keyword']])
    ->trackTotalHits(true)
    ->rawDsl(['runtime_mappings' => ['day' => ['type' => 'keyword']]])
    ->toDsl();
```

`orderBy($field, $direction, $options)` 只接受 `asc` 或 `desc`（大小写不敏感）。
即使 `$options` 中包含 `order`，也不会覆盖已经校验的 `$direction`；其他排序选项如
`mode`、`missing` 会原样保留。

`toDsl(): array` 只编译，不访问网络；`replaceDsl` 会替换全部 raw DSL，`rawDsl` 则递归合并。`search(): SearchResponse` 将 DSL 放在 ES9 `body` 中；`get()` 是别名，`first()` 自动 `size(1)`，`count()` 自动 `size(0)` 并返回 `hits.total`。

## 8. 深分页与 PIT

```php
$pit = $client->pitManager()->open('articles', '2m');
try {
    $page = Article::query()->pit($pit, '2m')
        ->orderBy('_shard_doc')->size(100)->search();
    $next = Article::query()->pit($pit, '2m')
        ->orderBy('_shard_doc')->searchAfter($page->first()?->getSortValues() ?? [])->size(100)->search();
} finally {
    $client->pitManager()->close($pit);
}

$client->pitManager()->using('articles', fn (string $id) => Article::query()->pit($id)->size(10)->search());
```

`open`、`close`、`using` 都访问网络；关闭请求把 PIT ID 放入 `body.id`。`using` 使用 `finally`，即回调抛异常也会释放 PIT。

## 9. 单文档写入

```php
$client->index(['index' => 'articles', 'id' => 'a-1', 'body' => ['title' => 'Hello']]);
$client->create(['index' => 'articles', 'id' => 'a-2', 'body' => ['title' => 'New']]);
$client->update(['index' => 'articles', 'id' => 'a-1', 'body' => ['doc' => ['views' => 4]]]);
$client->delete(['index' => 'articles', 'id' => 'a-2']);
```

这些方法是官方 ES9 endpoint 的轻量转发，参数中的文档内容必须放 `body`；冲突、未找到或权限错误会由适配器统一转换为本包的 `ResponseException` 或 `TransportException`。

## 10. Bulk 批量操作

```php
use SllhSmile\Elasticsearch\Bulk\BulkOperation;

$result = $client->bulkManager(500)->execute([
    BulkOperation::index('articles', '1', ['title' => 'A']),
    BulkOperation::create('articles', '2', ['title' => 'B']),
    BulkOperation::update('articles', '1', ['views' => 2], true),
    BulkOperation::delete('articles', '3'),
]);

$result->items; $result->errors; $result->hasErrors(); $result->raw;
```

`BulkOperation::toNdjsonLines()` 返回 action 行和（除 delete 外）source 行。`BulkManager::execute` 按 chunk size 分块，每块调用一次 `bulk`；`BulkResult` 汇总所有 item 和错误数。Bulk 请求体由官方客户端编码为 NDJSON。

## 11. 索引与 Alias 管理

```php
$indices = $client->indexManager();
$indices->create('articles-v1', ['number_of_shards' => 1], ['properties' => ['title' => ['type' => 'text']]]);
$indices->exists('articles-v1');
$indices->getMapping('articles-v1');
$indices->putMapping('articles-v1', ['views' => ['type' => 'integer']]);
$indices->getSettings('articles-v1');
$indices->putSettings('articles-v1', ['refresh_interval' => '1s']);
$indices->addAlias('articles-v1', 'articles', ['is_write_index' => true]);
$indices->removeAlias('articles-v1', 'articles');
$indices->switchAlias('articles', 'articles-v1', 'articles-v2');
$indices->delete('articles-v1');
```

create、putMapping、putSettings 和 updateAliases 的配置都放在 `body`；Alias 的附加选项
（例如 `is_write_index`、`filter`）放在 `actions[].add` 或 `actions[].remove` 内部，
不会与 action 同级。示例中的 `addAlias` 最终会生成：

```php
['actions' => [['add' => [
    'index' => 'articles-v1',
    'alias' => 'articles',
    'is_write_index' => true,
]]]];
```

`switchAlias` 在一次 `updateAliases` 请求中同时 remove/add，保证原子切换。

## 12. 响应与异常

`SearchResponse` 提供 `hits()`、`first()`、`total()`、`maxScore()`、`aggregations()`、`raw()`、`count()`，可直接 `foreach`。每个 `SearchHit` 提供 `source`、`id`、`score`、`sort`、`highlight` 和 `raw` 公共只读属性。

配置错误抛 `ConfigurationException`，网络/传输错误抛 `TransportException`，服务端响应错误可捕获 `ResponseException` 并读取 `statusCode()`、`response()`。
所有通过包内 endpoint（查询、写入、Bulk、索引、PIT）发出的请求都会统一转换为这些包内异常，
并将官方异常保存在 `getPrevious()`；因此业务层可以稳定按包内类型捕获。`raw()` 是官方客户端
逃生入口，直接调用时仍会得到官方客户端异常。生产环境请记录 request id 和状态码，不要记录 ApiKey。

## 13. 按 HTTP 方法发送 raw request 与测试

```php
$client->requestGet('/');
$client->requestPost('/articles/_search', [], ['query' => ['match_all' => (object) []]]);
$client->requestPut('/articles-v2', [], ['settings' => ['number_of_shards' => 1]]);
$client->requestDelete('/articles-v2');
```

`requestGet/requestPost/requestPut/requestDelete` 会把 body 传给官方 endpoint，并对 info、search、mapping、index、document、bulk 路由做显式分派，同时严格校验 HTTP 方法：根路径只允许 GET，search 只允许 GET/POST，mapping 只允许 GET/PUT，bulk 只允许 POST，文档路径只允许 GET/PUT/POST/DELETE；不支持的组合抛 `BadMethodCallException`。本地测试运行：

```bash
composer validate --no-check-publish --no-interaction
composer test
find src tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

云端冒烟请通过临时 `ELASTICSEARCH_HOST`、`ELASTICSEARCH_API_KEY` 环境变量执行，测试索引使用唯一后缀并在结束后删除。
