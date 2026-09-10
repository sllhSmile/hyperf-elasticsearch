# Sllhsmile Hyperf Elasticsearch

面向 Hyperf 3.x 的 Elasticsearch 7/8/9 ORM 风格客户端。它在官方 PHP Client 之上提供 Document Model、链式 Query Builder、多连接、Bulk、索引与 Alias 管理、PIT 深分页，以及适配 Hyperf 协程环境的 HTTP 客户端。

业务代码可以通过 Model 和 QueryBuilder 完成常见的文档读写与搜索；复杂场景仍可直接传递 Elasticsearch DSL 或调用底层 endpoint。

- 使用统一 API 适配 Elasticsearch PHP Client 7、8、9。
- 通过 Hyperf 容器自动注册 Manager 和默认客户端。
- 支持属性 casts、文档生命周期和搜索结果 hydrate。
- 支持 term、match、bool、nested、排序、高亮、聚合与深分页。
- 保留 Bulk、索引管理和原始请求等底层能力。

这个包不是 Eloquent，不提供关系、事务，也不负责 MySQL 到 Elasticsearch 的自动同步。如果项目只需要少量原生 DSL，直接使用官方客户端可能更简单。

## 目录

- [兼容性](#兼容性)
- [安装](#安装)
- [配置连接](#配置连接)
- [五分钟快速开始](#五分钟快速开始)
- [常用操作](#常用操作)
- [功能导航](#功能导航)
- [Hyperf 与连接行为](#hyperf-与连接行为)
- [常见问题](#常见问题)
- [从旧包名迁移](#从旧包名迁移)

## 兼容性

请使用相互匹配的 Hyperf、`hyperf/elasticsearch`、官方 PHP Client 和 Elasticsearch Server：

| Hyperf | `hyperf/elasticsearch` | `elasticsearch/elasticsearch` | Elasticsearch Server |
| --- | --- | --- | --- |
| 3.0.x | 3.0.x | 7.17.x | 7.17.x |
| 3.1.x | 3.1.x | 7.17.x | 7.17.x |
| 3.2.x | 3.2.x | 8.x | 8.x |
| 3.2.x | 3.2.x | 9.x | 9.x |

当前版本要求 PHP `>=8.1`。同一个运行实例只能安装一个官方客户端主版本，建议显式指定目标版本，不要让 Composer 猜测 Elasticsearch Server 的版本。

## 安装

根据 Elasticsearch Server 主版本选择一条命令。

ES 7.17：

```bash
composer require sllhsmile/hyperf-elasticsearch "elasticsearch/elasticsearch:^7.17"
```

ES 8：

```bash
composer require sllhsmile/hyperf-elasticsearch "elasticsearch/elasticsearch:^8"
```

ES 9：

```bash
composer require sllhsmile/hyperf-elasticsearch "elasticsearch/elasticsearch:^9"
```

包会通过 Hyperf `ConfigProvider` 自动注册依赖。发布配置文件：

```bash
php bin/hyperf.php vendor:publish sllhsmile/hyperf-elasticsearch --id=elasticsearch-config
```

配置将写入 `config/autoload/elasticsearch.php`。

## 配置连接

连接启用了 API Key 时，在 `.env` 中配置：

```dotenv
ELASTICSEARCH_HOST=https://your-cluster.example.com:443
ELASTICSEARCH_API_KEY=base64-encoded-api-key
ELASTICSEARCH_TIMEOUT=10
ELASTICSEARCH_CONNECT_TIMEOUT=5
ELASTICSEARCH_RETRIES=1
ELASTICSEARCH_VERIFY_TLS=true
```

本地无认证节点只需要：

```dotenv
ELASTICSEARCH_HOST=http://127.0.0.1:9200
```

也可以通过 `ELASTICSEARCH_USERNAME` 和 `ELASTICSEARCH_PASSWORD` 使用 Basic Auth。API Key 与 Basic Auth 不能同时配置；API Key 应填写 Elasticsearch 创建 API Key 时返回的 base64 `encoded` 值。

不要把密钥写入 PHP 文件、提交到 Git 或打印到日志。生产环境应保持 TLS 校验开启；使用私有 CA 时可将 `verify_tls` 配置为证书文件路径。

## 五分钟快速开始

### 1. 定义模型

创建 `app/Model/Article.php`：

```php
<?php

declare(strict_types=1);

namespace App\Model;

use SllhSmile\Elasticsearch\Model\DocumentModel;

final class Article extends DocumentModel
{
    protected string $index = 'articles';

    protected string $connection = 'default';

    protected array $casts = [
        'views' => 'int',
        'published' => 'bool',
    ];
}
```

### 2. 建立索引、写入并查询

下面的服务可直接由 Hyperf 容器实例化。示例会创建索引以形成完整闭环；生产项目应把索引初始化放在部署脚本或独立命令中。

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Article;
use SllhSmile\Elasticsearch\Hyperf\Manager;

final class ArticleSearchService
{
    public function __construct(private readonly Manager $elasticsearch)
    {
    }

    public function demo(): array
    {
        $indices = $this->elasticsearch->connection()->indexManager();

        if (! $indices->exists('articles')) {
            $indices->create(
                index: 'articles',
                mappings: [
                    'properties' => [
                        'title' => ['type' => 'text'],
                        'status' => ['type' => 'keyword'],
                        'views' => ['type' => 'integer'],
                        'published' => ['type' => 'boolean'],
                    ],
                ],
            );
        }

        Article::create(
            attributes: [
                'title' => 'Hyperf Elasticsearch 入门',
                'status' => 'published',
                'views' => 10,
                'published' => true,
            ],
            id: 'article-1',
            options: ['refresh' => 'wait_for'],
        );

        $result = Article::query()
            ->where('status', 'published')
            ->whereMatch('title', 'Hyperf')
            ->orderBy('_score', 'desc')
            ->size(20)
            ->search();

        return [
            'total' => $result->total(),
            'items' => array_map(
                static fn (Article $article): array => array_merge(
                    ['id' => $article->getKey()],
                    $article->toArray(),
                ),
                $result->hits(),
            ),
        ];
    }
}
```

首次执行会返回一条包含 `article-1` 的搜索结果。示例中的 `refresh => wait_for` 用于确保写入后立即可搜索；高吞吐写入场景不应为每条文档强制刷新。

## 常用操作

### 文档生命周期

```php
$article = Article::create(['title' => 'PHP', 'views' => 10], 'article-2');

$article = Article::find('article-2'); // 文档不存在时返回 null
$article?->update(['views' => 11]);
$article?->delete();
```

`create()`、`save()` 和 `update()` 返回 hydrate 后的模型。`exists()` 表示模型是否已写入或从 Elasticsearch 命中，`getKey()` 返回文档 `_id`。

### 链式查询

```php
$result = Article::query()
    ->where('status', 'published')
    ->whereRange('views', ['gte' => 10])
    ->highlight(['title'])
    ->trackTotalHits()
    ->size(20)
    ->search();

foreach ($result as $article) {
    echo $article->title;
}
```

### 原始 DSL

```php
$result = Article::query()
    ->replaceDsl([
        'query' => [
            'function_score' => [
                'query' => ['match' => ['title' => 'Hyperf']],
                'boost_mode' => 'multiply',
            ],
        ],
        'size' => 10,
    ])
    ->search();
```

需要在链式 DSL 上追加不冲突的顶层字段时使用 `rawDsl()`；需要完全控制请求体时使用 `replaceDsl()`。

## 功能导航

| 场景 | 主要入口 | 详细文档 |
| --- | --- | --- |
| 多连接与客户端缓存 | `Manager::connection()` / `purge()` | [连接管理](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#2-客户端与连接管理) |
| Document Model | `DocumentModel` | [模型与 CRUD](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#4-documentmodel) |
| Query Builder | `where`、`whereMatch`、`whereNested` | [查询条件](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#5-querybuilder-基础条件) |
| 排序、高亮与聚合 | `orderBy`、`highlight`、`aggs` | [查询选项](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#7-排序分页高亮聚合和-raw-dsl) |
| 深分页 | `searchAfter`、`PitManager` | [PIT](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#8-深分页与-pit) |
| 批量写入 | `BulkManager`、`BulkOperation` | [Bulk](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#10-bulk-批量操作) |
| 索引与 Alias | `IndexManager` | [索引管理](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#11-索引与-alias-管理) |
| 响应与异常 | `SearchResponse`、包内异常 | [响应处理](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#12-响应与异常) |
| 未封装 endpoint | `ClientInterface::call()` | [Raw request](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#13-按-http-方法发送-raw-request-与测试) |

完整配置、所有公开方法和版本差异请查看 [USAGE.md](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md)。

## Hyperf 与连接行为

- `default` 指定默认连接；模型通过 `$connection`、其他服务通过 `Manager::connection('name')` 选择命名连接。
- `timeout` 是单次请求超时，默认 10 秒；`connect_timeout` 是建立连接超时，默认 5 秒。
- `retries` 是官方客户端的节点重试次数，默认 1；它不等同于业务写入自动重放。
- 在协程环境中，ES7 使用对应 Hyperf 版本的 RingPHP CoroutineHandler；ES8/9 使用 Hyperf Guzzle，并保留超时、TLS 和自定义 Header 配置。
- 客户端由 `Manager` 按连接名缓存。调用 `$manager->purge()` 后，下一次请求会按当前配置重建连接。
- 传输异常会清理失效客户端，但失败的写请求仍应使用稳定、幂等的文档 ID。

配置支持 `hosts`、API Key、Basic Auth、`timeout`、`connect_timeout`、`retries`、`verify_tls`、`headers` 和 `client_options`。字段结构和多连接示例见 [安装与配置](https://github.com/sllhSmile/hyperf-elasticsearch/blob/main/USAGE.md#1-安装与配置)。

## 常见问题

### Composer 报依赖冲突

先检查兼容性表中的四个版本是否属于同一组合。Hyperf 3.0/3.1 使用 Client 7.17；Hyperf 3.2 使用 Client 8 或 9。不要在同一个项目中约束多个官方客户端主版本。

### 写入成功但立即搜索不到

Elasticsearch 默认是近实时搜索。测试或必须立即搜索的场景可以为写入传递 `refresh => wait_for`；生产批量写入应遵循正常 refresh 周期。

### HTTPS 证书校验失败

生产环境不要关闭证书验证。使用私有 CA 时，将 `verify_tls` 设置为 CA 文件路径；`false` 只适合受控的本地测试环境。

### 模型提示没有可用客户端

在 Hyperf 应用内确认包的 `ConfigProvider` 已加载。脱离 Hyperf 容器进行单元测试时，可以通过 `Article::setClient($client)` 设置测试客户端。

## 从旧包名迁移

如果项目仍依赖 `sllhsmile/elasticsearch`，先移除旧包，再按目标 ES 版本安装新包：

```bash
composer remove sllhsmile/elasticsearch
composer require sllhsmile/hyperf-elasticsearch "elasticsearch/elasticsearch:^8"
```

PHP 命名空间仍为 `SllhSmile\Elasticsearch\`，现有 `use` 引用无需修改。

## 开发与反馈

```bash
composer install
composer test
composer analyse
```

发现缺陷或需要新功能，请通过 [GitHub Issues](https://github.com/sllhSmile/hyperf-elasticsearch/issues) 提交可复现示例，并注明 Hyperf、官方 PHP Client 和 Elasticsearch Server 版本。

## License

[MIT](LICENSE)
