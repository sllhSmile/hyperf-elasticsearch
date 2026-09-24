# 使用文档

本文以 Hyperf 3.0+ 和 Elasticsearch 8/9 为目标，示例均假定已在容器中取得 `Manager`。除特别说明外，调用链上的方法只是在内存中累积 DSL，`search`、`index`、`create`、`update`、`delete`、Bulk、索引和 PIT 方法才会发起网络请求。

## 1. 安装与配置

根据 Elasticsearch Server 主版本选择对应 SDK：

```bash
composer require sllhsmile/hyperf-elasticsearch "elasticsearch/elasticsearch:^8"
# 或
composer require sllhsmile/hyperf-elasticsearch "elasticsearch/elasticsearch:^9"

php bin/hyperf.php vendor:publish sllhsmile/hyperf-elasticsearch --id=elasticsearch-config
```

配置文件为 `config/autoload/elasticsearch.php`。至少配置 `hosts`，云服务建议使用 ApiKey：

```dotenv
ELASTICSEARCH_HOST=https://cluster.example.com:443
ELASTICSEARCH_API_KEY=base64-key
ELASTICSEARCH_RETRIES=2
ELASTICSEARCH_VERIFY_TLS=true
```

不要把密钥提交到 Git 或打印到日志。`api_key` 填写 Elasticsearch 创建 API Key 响应中的 base64 `encoded` 值。本包保留 Hyperf 3.0/3.1/3.2 支持，通过官方 SDK Builder 与 Hyperf Guzzle 工厂构建客户端，不依赖 `hyperf/elasticsearch`。宿主按服务端版本约束 `elasticsearch/elasticsearch:^8` 或 `^9`。配置只暴露跨版本可验证的总 `timeout`、TLS 和重试语义，不透传任意底层 client options。

连接字段如下。未知字段、错误类型、无效 URL、认证冲突会在 `Manager` 构造时抛出 `ConfigurationException`，此时还不会访问网络。

| 字段 | 类型与默认值 | 说明 |
| --- | --- | --- |
| `hosts` | `list<string>`，必填 | 一个或多个不含凭据、query、fragment 的 HTTP/HTTPS URL |
| `api_key` | `?string`，`null` | Elasticsearch 返回的 base64 `encoded` API Key |
| `username` / `password` | `?string`，`null` | 必须成对配置，并与 `api_key` 互斥 |
| `timeout` | 正整数，`10` | 单次请求总超时，单位为秒 |
| `retries` | 非负整数，`1` | 官方 Transport 的网络失败重试次数；`0` 表示禁用 |
| `verify_tls` | `bool|string`，`true` | TLS 校验开关，或可读 CA 文件/目录路径 |
| `headers` | `array<string,string>`，`[]` | 附加请求 Header，不允许覆盖 `Authorization` |

多连接通过 `connections` 配置，顶层 `default` 必须指向其中一个连接：

```php
return [
    'default' => 'primary',
    'connections' => [
        'primary' => [
            'hosts' => ['http://127.0.0.1:9200'],
            'timeout' => 10,
            'retries' => 1,
            'verify_tls' => true,
            'headers' => [],
        ],
        'archive' => [
            'hosts' => ['https://archive.example.com:9200'],
            'api_key' => env('ELASTICSEARCH_ARCHIVE_API_KEY'),
            'timeout' => 20,
            'retries' => 1,
            'verify_tls' => true,
            'headers' => [],
        ],
    ],
];
```

## 2. 客户端与连接管理

```php
$manager = $container->get(\SllhSmile\Elasticsearch\Hyperf\Manager::class);
$client = $manager->connection();                 // 顶层 default 指向的连接
$archive = $manager->connection('archive');       // 命名连接
$manager->purge('archive');                       // 丢弃一个缓存客户端
$manager->purge();                                // 丢弃全部客户端
```

包只向 Hyperf 容器绑定 `Manager` 和它内部使用的 `ClientFactory`，不绑定“默认”
`ElasticsearchClient` 或 `ClientInterface`。业务服务应注入 `Manager`，再显式调用 `connection()`，
这样命名连接不会因容器别名而变得含糊。

Manager 构造时会一次性校验顶层字段、默认连接、全部命名连接及认证/TLS 等配置，但不会创建
HTTP Client 或访问网络。`connection(?string $name): ElasticsearchClient` 按名称懒加载并缓存客户端；
配置不存在时抛出 `ConfigurationException`。`purge` 不访问网络，只清理 Worker 内的客户端引用，
不会重新读取 Hyperf 配置。配置文件修改后仍需 reload/restart Worker。多个连接互相隔离，适合不同集群或凭据。

Hyperf Manager 使用 `ElasticsearchClient::fromFactory()` 创建可恢复客户端。由于 Hyperf Guzzle
在普通/native-cURL 与 `CoroutineHandler` 场景使用不同传输方式，同一个连接分别缓存这两个
执行作用域的官方客户端，避免 Worker 首次调用发生在哪种环境就永久污染后续请求。包捕获明确的
网络或节点故障后只丢弃当前作用域的实例，下一次请求重新创建 client/node pool；旧协程的失败
也不会清除其他协程刚重建的健康实例。调用方通过 `ElasticsearchClient::fromClient()` 包装的
固定官方客户端不会被替换。包不叠加额外重试，但官方 Transport 会按 `retries` 重试网络失败，
可能重放服务端已经执行的写请求，因此写入应使用稳定 ID 或其他幂等设计。

## 3. 协程 HTTP

在 Hyperf 协程中，Hyperf Guzzle 工厂会自动使用 `CoroutineHandler`；如果 Swoole 已启用 native cURL hook，则使用 Guzzle cURL 的协程化路径。业务代码不需要手动创建 Handler 或客户端连接池。

```php
// 同一个连接名：共享 Manager 缓存的 ElasticsearchClient。
$a = Article::query()->where('type', 'a')->search();
$b = Comment::query()->where('visible', true)->search();

// 两个协程可以并发发起请求；底层并发行为由 Hyperf Handler 和 Swoole 配置管理。
Coroutine::create(fn () => Article::query()->count());
Coroutine::create(fn () => Article::query()->count());
```

HTTP 请求是否复用 TCP keep-alive 连接由 Handler 和服务端共同决定；业务代码不需要也不应该为每次查询手动 new 客户端。

`Manager` 和它缓存的 `ElasticsearchClient` 设计为 Worker 内复用。`DocumentModel` 与 `QueryBuilder`
保存可变的属性或 DSL 状态，只应在单次业务操作内使用；不要把它们放入单例属性，也不要让多个协程
同时修改同一个实例。并发查询应像上例一样在各协程内分别调用 `Article::query()`。

TLS 默认严格校验证书和主机名，并拒绝自签名证书。私有 CA 可以通过 `verify_tls` 指定可读的 CA 文件或目录；只有受控测试环境才应显式设为 `false`。`Authorization` Header 禁止通过 `headers` 覆盖，必须使用 `api_key` 或 Basic Auth 配置。

### Basic Auth 配置约束

`username` 和 `password` 必须成对出现。只配置其中一个会在连接配置校验阶段抛出
`ConfigurationException`，不会以未认证请求的方式继续访问 Elasticsearch。

## 4. DocumentModel

```php
use SllhSmile\Elasticsearch\Model\DocumentModel;

final class Article extends DocumentModel
{
    protected string $index = 'articles';
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

模型不声明 `$connection` 时使用顶层 `default`；声明非空连接名时固定使用该命名连接。

`getIndexName(): string` 返回索引名；未定义索引时抛出 `LogicException`。`getKey`、`exists`、`getScore`、`getSortValues`、`getHighlight` 分别读取 `_id`、命中状态、相关性分数、排序值和高亮结果。`fromSearchHit(SearchHit $hit): static` 会把 `_source` 和 hit 元数据转换为已存在模型。支持 `int`、`float`、`bool`、`array/json`、`datetime` casts。

`mapping()` 和 `settings()` 只是供模型声明索引元数据的扩展钩子；模型 CRUD 不会读取它们，
也不会自动创建或迁移索引。请在部署脚本或独立命令中把这些定义交给 `IndexManager` 执行。

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

`create()` 和 `save()` 返回保留当前属性的模型，并将服务端返回的 `_id` 写回模型。已有 ID
的 `save()` 使用 `index` 覆盖写入；没有 ID 时由 Elasticsearch 生成 ID。`update()` 是填充属性后
再次保存完整文档的便捷方法，不模拟关系型数据库事务。`delete()` 成功后将 `exists()` 设为
`false`。`find()` 会把 `_source` hydrate 到模型并执行入站 casts。只有响应明确包含
`found: false` 的 HTTP 404 会转换为 `null`；索引不存在、无法识别的 404 和其他响应错误继续抛出
`ResponseException`，避免把部署故障伪装成文档未命中。

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

`size()` 只限制当前页命中数，`count($response)` 是当前页数量。`SearchResponse::total()` 返回
`?TotalHits`：`value` 是 Elasticsearch 报告的数量，`relation` 为 `TotalHitsRelation::Eq` 时是
精确总数，为 `TotalHitsRelation::Gte` 时表示“至少有 value 条”。未请求总数时可能返回 `null`。
需要精确总数时调用 `trackTotalHits(true)`，或直接使用 Builder 的 `count()`。深分页使用
`searchAfter()`/PIT；不要把 `from`/`size` 当作无限分页方案。

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
    ->source(['title'], ['body'])
    ->orderBy('_score', 'desc')
    ->from(0)->size(20)->limit(20)
    ->highlight(['title'], ['pre_tags' => ['<em>'], 'post_tags' => ['</em>']])
    ->aggs('by_status', ['terms' => ['field' => 'status.keyword']])
    ->trackTotalHits(true)
    ->rawDsl(['runtime_mappings' => ['day' => ['type' => 'keyword']]])
    ->toDsl();
```

只需要 includes 时可使用 `select(['title', 'views'])`；它与 `source()` 都设置 `_source`，后调用的
方法会覆盖前一次设置，因此不要在同一调用链中同时使用。

`orderBy($field, $direction, $options)` 只接受 `asc` 或 `desc`（大小写不敏感）。
即使 `$options` 中包含 `order`，也不会覆盖已经校验的 `$direction`；其他排序选项如
`mode`、`missing` 会原样保留。

`toDsl(): array` 只编译，不访问网络。`rawDsl()` 递归合并多次传入的 raw 片段，但不允许覆盖链式 API 已生成的顶层键。需要完整控制请求体时，应在全新的 Builder 上直接调用 `rawDsl()`。链式 DSL 与 raw DSL 的同名顶层键内容不一致时，`toDsl()` 会抛出 `InvalidArgumentException`。

`search(): SearchResponse` 将 DSL 放在各版本通用的 `body` 中；`get()` 是别名。`first()` 和 `count()` 会克隆 Builder，分别设置 `size(1)`、`size(0)`，因此不会修改原实例；如果 raw DSL 已经包含不同的 `size`，冲突检查仍会生效。

## 8. 深分页与 PIT

```php
$pit = $client->pitManager()->open('articles', '2m');
try {
    $page = Article::query($client)->pit($pit, '2m')
        ->orderBy('_shard_doc')->size(100)->search();
    $last = $page->hits()[count($page) - 1] ?? null;
    $next = $last === null ? null : Article::query($client)->pit($pit, '2m')
        ->orderBy('_shard_doc')->searchAfter($last->getSortValues())->size(100)->search();
} finally {
    $client->pitManager()->close($pit);
}

$client->pitManager()->using(
    'articles',
    fn (string $id) => Article::query($client)->pit($id)->size(10)->search(),
);
```

`open`、`close`、`using` 都访问网络。PIT 搜索使用集群级 `/_search`，不会同时发送 index；关闭请求把 PIT ID 放入 `body.id`。`using` 无论回调成功或失败都会尝试关闭 PIT；若回调和关闭同时失败，保留回调异常，并通过可用的 PSR Logger 记录关闭失败。

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

这些方法是官方 ES8/9 endpoint 的轻量转发，参数中的文档内容必须放 `body`。冲突、未找到、权限错误等服务端响应会转换为 `ResponseException`；明确的网络或节点故障才会转换为 `TransportException`。

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

```php
use SllhSmile\Elasticsearch\Response\TotalHitsRelation;

$total = $response->total();
if ($total !== null) {
    echo $total->relation === TotalHitsRelation::Eq
        ? "共 {$total->value} 条"
        : "至少 {$total->value} 条";
}
```

`TotalHits::isExact()` 是判断 `relation === Eq` 的便捷方法。旧格式的整数 total 会按精确值解析；
未知 relation 或无效结构会抛出 `UnexpectedValueException`，避免静默展示错误总数。

配置错误抛 `ConfigurationException`，确定的网络/节点故障抛 `TransportException`，服务端响应错误
可捕获 `ResponseException` 并读取 `statusCode()`、`response()`。官方 SDK 或 Transport 的参数、
序列化等非网络错误会包装为 `ElasticsearchException`，但不会触发客户端重建。包内异常通过
`getPrevious()` 保留原异常。

`execute()` 回调中的业务异常、未知响应类型及不支持 operation 的 `BadMethodCallException` 保持
原类型，避免把代码错误伪装成传输故障。生产环境请记录 request id 和状态码，不要记录 ApiKey。

## 13. 高级 endpoint 与测试

```php
use Elastic\Elasticsearch\Client;

$response = $client->execute(
    static fn (Client $official) => $official->nodes()->stats(),
);
```

`execute()` 的回调接收当前主版本的官方客户端，适合调用包尚未封装的 endpoint；返回类型仍由官方 SDK 决定，官方客户端异常遵循上一节的归一化规则，回调自身抛出的业务异常保持原类型。业务代码因此会与当前安装的 SDK 主版本耦合，不应把官方客户端对象保存到请求之外。本地测试运行：

```bash
composer validate --no-check-publish --no-interaction
composer test
composer analyse
composer cs-check
find src tests benchmarks -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

CI 对 PHP/Hyperf/SDK 组合运行单元、静态分析和代码风格检查，并对 ES8、ES9 启动真实服务，通过包内工厂在协程中完成 CRUD、Bulk、Alias 和 PIT 冒烟。

需要建立部署环境的并发基线时，可在安装 Swoole 且能够访问测试集群的环境运行：

```bash
ELASTICSEARCH_BENCHMARK_HOST=http://127.0.0.1:9200 \
ELASTICSEARCH_BENCHMARK_REQUESTS=1000 \
ELASTICSEARCH_BENCHMARK_CONCURRENCY=50 \
php benchmarks/concurrent_search.php
```

脚本以固定数量的协程共享同一个客户端，输出成功数、失败数、失败异常类型、总吞吐以及成功请求的 P50/P95。它只调用 `info` endpoint，用于比较同一环境在代码或配置变更前后的基线，不代替针对真实查询 DSL 和数据规模的容量测试。
