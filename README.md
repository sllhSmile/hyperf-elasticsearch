# Sllhsmile Hyperf Elasticsearch

面向 Hyperf 3.0+ 的 Elasticsearch 9 ORM 风格客户端。它在官方 `elasticsearch/elasticsearch:^9` 之上提供 Document Model、链式 Query Builder、Bulk、索引管理、PIT 和 Hyperf 官方协程 HTTP 接入。

它不是关系型数据库 ORM，也不负责 MySQL 到 Elasticsearch 的自动同步。复杂 DSL 始终可以通过 `rawDsl()` 直接传递。

## 支持范围

| 项目 | 当前版本 |
| --- | --- |
| PHP | `>=8.1` |
| Hyperf | `^3.0` |
| Elasticsearch Server | 9.x（已按 9.6 API 设计） |
| 官方 PHP Client | `elasticsearch/elasticsearch:^9` |
| Laravel | 当前版本不支持 |
| Elasticsearch 7/8 | 预留，当前版本不支持 |
| MySQL 自动同步 | 不支持 |

## 使用文档

- [完整使用文档](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md)：每项公开功能的安装、配置、调用和注意事项。
- 设计、开发阶段和进度文档仅在本地维护，不作为 Composer 用户文档发布。

发布说明：GitHub 保留 README 和 USAGE；Composer dist 按发布规则仅保留 README，USAGE 通过上方 GitHub 链接访问。

## 安装

```bash
composer require sllhsmile/hyperf-elasticsearch
```

如果项目此前使用旧包名 `sllhsmile/elasticsearch`，请先移除旧依赖再安装新包名：

```bash
composer remove sllhsmile/elasticsearch
composer require sllhsmile/hyperf-elasticsearch
```

包内 PHP 命名空间 `SllhSmile\Elasticsearch\` 保持不变，因此现有 `use` 引用无需修改。

包会自动通过 Hyperf `ConfigProvider` 注册配置和依赖。需要手动发布配置时执行：

```bash
php bin/hyperf.php vendor:publish sllhsmile/hyperf-elasticsearch --id=elasticsearch-config
```

完整配置和 API 用法请参阅 GitHub 上的 [USAGE.md](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md)。

## 最小配置

```dotenv
ELASTICSEARCH_HOST=https://your-cluster.example.com:443
ELASTICSEARCH_API_KEY=your-api-key
ELASTICSEARCH_RETRIES=2
ELASTICSEARCH_VERIFY_TLS=true
```

Handler 无需配置。包通过官方 `Hyperf\\Elasticsearch\\ClientBuilderFactory` 自动选择协程 Handler 或 cURL 路径。连接配置支持 hosts、认证、retries、TLS 和自定义 headers。

不要把 ApiKey 写入 PHP 文件、Git、日志或异常信息。真实云端凭据必须通过未提交的环境变量注入。

## 配置参数

配置文件发布到 `config/autoload/elasticsearch.php`。顶层 `default` 选择默认连接，
`connections` 下的每个键代表一个独立的 Elasticsearch 连接。模型的 `$connection`
或 `Manager::connection('name')` 可以选择指定连接。

| 参数 | 位置 | 作用 | 默认值/说明 |
| --- | --- | --- | --- |
| `default` | 顶层 | 未指定连接名时使用的连接名称 | `default` |
| `connections` | 顶层 | 定义一个或多个命名连接 | 至少包含被使用的连接 |
| `hosts` | 连接内 | Elasticsearch 节点 URL 列表 | `http://127.0.0.1:9200` |
| `api_key` | 连接内 | API Key 认证 | 空；不能与 Basic Auth 同时配置 |
| `username` | 连接内 | Basic Auth 用户名 | 空；需与 `password` 同时配置 |
| `password` | 连接内 | Basic Auth 密码 | 空；请使用环境变量 |
| `retries` | 连接内 | 节点请求失败后的重试次数 | `1`；`0` 表示不重试 |
| `verify_tls` | 连接内 | HTTPS 证书校验；可填布尔值或 CA 文件路径 | `true` |
| `headers` | 连接内 | 追加到每个 ES 请求的自定义 Header | `[]` |

示例：

```php
return [
    'default' => 'default',
    'connections' => [
        'default' => [
            'hosts' => [env('ELASTICSEARCH_HOST')],
            'api_key' => env('ELASTICSEARCH_API_KEY'),
            'retries' => 2,
            'verify_tls' => true,
            'headers' => ['X-Request-Source' => 'my-service'],
        ],
        'archive' => [
            'hosts' => [env('ELASTICSEARCH_ARCHIVE_HOST')],
            'username' => env('ELASTICSEARCH_ARCHIVE_USERNAME'),
            'password' => env('ELASTICSEARCH_ARCHIVE_PASSWORD'),
        ],
    ],
];
```

`handler`、连接池和 `client_options` 不属于当前发布配置。HTTP 客户端由 Hyperf
官方 `ClientBuilderFactory` 管理，并在协程环境中自动使用协程 Handler；不要在业务代码
中自行设置 Handler 或为每次查询创建客户端。

## 快速开始

```php
use SllhSmile\Elasticsearch\Model\DocumentModel;

final class Article extends DocumentModel
{
    protected string $index = 'articles';
    protected string $connection = 'default';

    protected array $casts = ['views' => 'int'];
}

$response = Article::query()
    ->where('status', 'published')
    ->whereMatch('title', 'Hyperf')
    ->orderBy('_score', 'desc')
    ->size(20)
    ->search();

foreach ($response as $article) {
    echo $article->title;
}
```

## 功能总览

| 功能 | 入口 | 教程 |
| --- | --- | --- |
| 多连接与客户端 | `Manager::connection()` / `purge()` | [客户端连接](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#2-客户端与连接管理) |
| 协程 HTTP | 官方 Hyperf Guzzle 工厂 | [协程请求](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#3-协程-http) |
| Document Model | `DocumentModel` | [模型](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#4-documentmodel) |
| 基础查询 | `where`、`whereIn`、`whereNot` | [基础查询](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#5-querybuilder-基础条件) |
| 全文查询 | `whereMatch`、`wherePhrase` | [全文查询](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#6-全文bool-与-nested) |
| Bool/Nested | `whereBool`、`should`、`whereNested` | [高级查询](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#6-全文bool-与-nested) |
| 排序/分页/字段 | `orderBy`、`from`、`size`、`select`、`source` | [查询选项](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#7-排序分页高亮聚合和-raw-dsl) |
| Highlight/Aggregation | `highlight`、`aggs` | [查询选项](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#7-排序分页高亮聚合和-raw-dsl) |
| 原始 DSL | `rawDsl`、`replaceDsl` | [原始 DSL](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#7-排序分页高亮聚合和-raw-dsl) |
| 深分页/PIT | `searchAfter`、`pit`、`PitManager` | [深分页](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#8-深分页与-pit) |
| 写入 | `index`、`create`、`update`、`delete` | [文档写入](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#9-单文档写入) |
| Bulk | `BulkOperation`、`BulkManager` | [Bulk](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#10-bulk-批量操作) |
| 索引管理 | `IndexManager` | [索引管理](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#11-索引与-alias-管理) |
| 响应对象 | `SearchResponse`、`SearchHit` | [响应处理](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#12-响应与异常) |

每项方法的参数、返回值和请求体位置都在 USAGE.md 中给出。



## 许可证

MIT
