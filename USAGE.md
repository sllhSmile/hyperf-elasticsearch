# 使用文档

本文以 Hyperf 3.0+ 和 Elasticsearch 7.17/8/9 为目标，示例均假定已在容器中取得 `Manager`。除特别说明外，调用链上的方法只是在内存中累积 DSL，`search`、`index`、`create`、`update`、`delete`、Bulk、索引和 PIT 方法才会发起网络请求。

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

不要把密钥提交到 Git 或打印到日志。`api_key` 填写 Elasticsearch 创建 API Key 响应中的 base64 `encoded` 值。依赖组合为 Hyperf/`hyperf-elasticsearch` 3.0 或 3.1 + ES7，或 Hyperf/`hyperf-elasticsearch` 3.2 + ES8/9；宿主可显式约束 `elasticsearch/elasticsearch:^7.17`、`^8` 或 `^9`。包复用对应版本的官方 Hyperf 工厂；ES8/9 的 `timeout`、`connect_timeout`、TLS 和 `client_options` 会在创建协程 Guzzle client 时一次性注入。

## 2. 客户端与连接管理

```php
$manager = $container->get(\SllhSmile\Elasticsearch\Hyperf\Manager::class);
$client = $manager->connection();                 // 默认 default；通常由模型自动解析
$archive = $manager->connection('archive');       // 命名连接
$manager->purge('archive');                       // 丢弃一个缓存客户端
$manager->purge();                                // 丢弃全部客户端
```

`connection(?string $name): ElasticsearchClient` 按名称懒加载并缓存客户端；配置不存在时抛出 `InvalidArgumentException`。`purge` 不访问网络，只清理 Worker 内的客户端引用。多个连接互相隔离，适合不同集群或凭据。

官方客户端的节点池可能在连接超时后把唯一节点标记为 dead。包在捕获传输层异常时会自动
丢弃当前客户端，下一次请求重新创建 client/node pool，因此不需要重启整个 Hyperf Worker。
失败请求本身不会被包自动重放，尤其是写入请求，避免网络超时但服务端已成功处理时造成重复写入。
压测仍应保证 ES 集群、出口网络和连接/请求超时足够，并配置多个 hosts 以减少单节点故障影响。

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

### 4.1 文档模型写入、读取、更新和删除

`DocumentModel` 是包的 ORM 风格核心入口，负责索引绑定、属性 casts 和文档生命周期；底层
`ClientInterface`/官方客户端只负责传输协议，不应在控制器中绕过模型直接拼接请求。

```php
$article = Article::create([
    'title' => 'Hyperf Elasticsearch',
    'views' => '10',
    'published_at' => '2026-09-07T12:00:00+08:00',
], 'article-1');

$article->fill(['views' => 11])->setKey('article-1')->save();
$article->update(['views' => 12]);
$article->delete();

$article = Article::find('article-1'); // 不存在时返回 null
```

`create()` 和 `save()` 返回已经 hydrate 的模型，并将服务端返回的 `_id` 写回模型。已有 ID
的 `save()` 使用 `index` 覆盖写入；没有 ID 时由 Elasticsearch 生成 ID。`update()` 是填充属性后
再次保存完整文档的便捷方法，不模拟关系型数据库事务。`delete()` 成功后将 `exists()` 设为
`false`。`find()` 会把 `_source` hydrate 到模型并执行入站 casts；HTTP 404 会转换为 `null`，
其他服务端错误仍抛出 `ResponseException`。

### 4.2 QueryBuilder 写入和链式查询

模型 QueryBuilder 与模型静态 API 使用同一个连接和 adapter：

```php
$article = Article::query()->create(['title' => 'PHP', 'views' => 10], 'article-2');

$response = Article::query()
    ->where('title', 'PHP')
    ->whereMatch('content', 'Elasticsearch')
    ->whereRange('score', ['gte' => 1])
    ->orderBy('created_at', 'desc')
    ->size(20)
    ->search();

foreach ($response->hits() as $article) {
    echo $article->getKey();
}
```

`size()` 只限制当前页命中数，`SearchResponse::total()` 是 Elasticsearch 的匹配总数，
`count($response)` 是当前页数量。深分页使用 `searchAfter()`/PIT；不要把 `from`/`size` 当作
无限分页方案。

### 4.3 Hyperf 测试控制器接口

宿主示例 `IndexController` 仅用于开发验证，控制器内部只调用模型和 QueryBuilder：

```bash
curl -X POST http://127.0.0.1:9501/elasticsearch/test/write \
  -H 'Content-Type: application/json' \
  -d '{"id":"test-1","title":"Hyperf Elasticsearch","score":10,"created_at":"2026-09-07T12:00:00+08:00"}'

curl 'http://127.0.0.1:9501/elasticsearch/test/read?id=test-1'
curl 'http://127.0.0.1:9501/elasticsearch/test/search?keyword=Hyperf&min_score=1&size=20'
```

写入接口使用 `query()->create(..., ['refresh' => 'wait_for'])`，返回文档 ID、casts 后的
文档和 `exists=true`。读取接口使用 `Model::find()`，模型不存在时返回业务 HTTP 404；
搜索接口默认按 `created_at desc` 排序，`size` 最大为 100，返回 `total`、当前页 `count`、
hydrate 后的 `models` 和 `aggregations`。传入 `debug=1` 时才额外返回完整原始响应 `raw`，
便于开发排查 DSL 和 Elasticsearch 元数据；生产环境不要开启该参数。
搜索接口不读取 `id` 参数；按 ID 读取请调用 `/elasticsearch/test/read?id=...`。

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

`toDsl(): array` 只编译，不访问网络；`replaceDsl` 会替换全部 raw DSL，`rawDsl` 递归合并但不允许覆盖链式 API 已生成的顶层键。需要完全覆盖时使用 `replaceDsl()`。`search(): SearchResponse` 将 DSL 放在各版本通用的 `body` 中；`get()` 是别名，`first()` 自动使用独立查询副本设置 `size(1)`，`count()` 自动使用独立查询副本设置 `size(0)` 并返回 `hits.total`。

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

本节以下入口是需要访问未封装官方 endpoint 时使用的底层 Client API。业务控制器的常规写入
优先使用上一节的 `Model::create()`、`save()`、`update()` 和 `delete()`，这样可以保留模型
casts、索引绑定和统一异常行为。

```php
$client->index(['index' => 'articles', 'id' => 'a-1', 'body' => ['title' => 'Hello']]);
$client->create(['index' => 'articles', 'id' => 'a-2', 'body' => ['title' => 'New']]);
$client->update(['index' => 'articles', 'id' => 'a-1', 'body' => ['doc' => ['views' => 4]]]);
$client->delete(['index' => 'articles', 'id' => 'a-2']);
```

这些方法是官方 ES7/8/9 endpoint 的轻量转发，参数中的文档内容必须放 `body`；冲突、未找到或权限错误会由适配器统一转换为本包的 `ResponseException` 或 `TransportException`。

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
