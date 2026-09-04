# Elasticsearch 包设计总纲

## 1. 目标与边界

### 1.1 目标

提供一个 Hyperf 3.0+ 项目可用的 PHP 包，用接近 ORM 的方式完成 Elasticsearch 的：

- Document Model、索引绑定与结果对象化；
- 常用查询的链式构造；
- 原生 DSL 的组合与逃生；
- 搜索、写入、批量操作；
- 索引、mapping、alias 的基础生命周期管理；
- 可替换的客户端/Transport。Hyperf 优先复用官方 `hyperf/elasticsearch` 工厂及其协程 Handler，避免重复实现客户端和阻塞 Worker。

### 1.2 明确不做的事情

- 不实现 MySQL/关系型数据库到 ES 的自动同步、Observer、队列一致性和双写；
- 不承诺完全复刻 Eloquent 的关系、事务和懒加载语义；
- 不隐藏 Elasticsearch 的全部能力，复杂功能必须能通过 raw DSL 使用；
- 第一版只支持 Elasticsearch 9.x；ES 8/7 和 OpenSearch 留作后续适配。
- 第一版不实现 Laravel 集成。

## 2. 兼容矩阵（第一版）

| 组件 | 第一版支持 | 说明 |
| --- | --- | --- |
| PHP | `>=8.1` | 代码避免使用 PHP 8.2 专属语法，便于 Hyperf 3.0 与 Laravel 10；Laravel 11 项目使用 PHP 8.2+。 |
| Elasticsearch | 首发 9.x；规划 8.x、7.x | 首发以官方 `elasticsearch/elasticsearch` 9.x 为基线，同时从 Core 契约开始隔离 7/8/9 的客户端差异。 |
| Hyperf | `^3.0`（包括 3.0+ 后续小版本） | 通过 ConfigProvider、DI；框架版本差异放入 Bridge 层。 |
| HTTP/客户端 | 官方 `elasticsearch/elasticsearch:^9` + Hyperf Guzzle 协程 Handler | 通过 Transport 契约隔离；不把 `hyperf/elasticsearch` 作为首版硬依赖。 |

ES 7/8/9 作为独立客户端主版本适配，不在运行时混装。OpenSearch 仍需另建兼容层和 CI 矩阵，不在上述承诺内。

## 3. 总体架构

采用“核心包 + 框架桥接 + Elasticsearch 主版本适配 + 可替换 Transport”的结构。即使首版发布为一个 Composer 包，代码目录也应按下面的边界组织。

```text
src/
├── Core/                 # 与框架无关
│   ├── Contracts/        # Client、Transport、Model、Query、Response
│   ├── Builder/          # Query/Write/DSL 节点构造
│   ├── Model/            # DocumentModel、属性转换、索引元数据
│   ├── Response/         # SearchResponse、Document、Aggregation
│   ├── Index/            # IndexManager、Mapping、Alias
│   ├── Bulk/             # Bulk 请求和结果
│   └── Exception/
├── Transport/
│   ├── Contracts/        # Client/Transport 主版本无关契约
│   ├── Elastic7/         # elasticsearch-php 7 endpoint adapter
│   ├── Elastic8/         # elasticsearch-php 8 endpoint adapter
│   ├── Elastic9/         # elasticsearch-php 9 endpoint adapter
│   ├── Laravel/          # 官方客户端适配
│   └── Hyperf/           # hyperf/elasticsearch 工厂或 PSR 协程适配
└── Hyperf/
    ├── ConfigProvider.php
    ├── Factory/
    └── Command/
```

核心模型和 Builder 不引用 `Illuminate/*`；Hyperf 层只负责容器绑定、配置合并、命令和生命周期适配。

## 4. API 设计原则

### 4.1 三层入口

1. 高频场景：Model/Query Builder 链式 API；
2. 复杂场景：Builder 回调和可组合 DSL 节点；
3. 特殊场景：`rawDsl()` 直接传入原生数组。

示意 API：

```php
Article::query()
    ->where('status', 'published')
    ->whereMatch('title', 'PHP Elasticsearch')
    ->whereBetween('published_at', [$from, $to])
    ->whereNested('author', fn ($q) => $q->where('author.id', 1001))
    ->orderBy('_score', 'desc')
    ->highlight(['title', 'content'])
    ->aggs('category_count', ['terms' => ['field' => 'category_id']])
    ->search();
```

复杂查询必须可以不受限地写成：

```php
Article::query()->rawDsl([
    'query' => ['bool' => ['must' => []]],
    'pit' => ['id' => $pitId, 'keep_alive' => '1m'],
])->search();
```

### 4.2 不模拟关系型语义

- `where` 默认映射为 filter/term 语义时要明确命名，全文检索使用 `whereMatch` 等方法；
- `save()` 表示写入 ES 文档，不表示数据库事务；
- `paginate()` 只适合浅分页，深分页必须显式使用 `searchAfter()` 或 PIT；
- nested、聚合、script、collapse 等高级能力不承诺全部“魔法化”。

### 4.3 显式 mapping 优先

Model 可以声明 `indexName()`、`mapping()`、`settings()`。第一版不根据 PHP 属性类型自动推断完整 mapping，因为 `text/keyword`、日期格式、nested 和多字段无法可靠推断。

## 5. 核心模块职责

### 5.1 Document Model

- `$documentId` 与 `_source` 映射；
- `getIndexName()`、`mapping()`、`settings()`；
- 属性白名单/黑名单和基本 cast；
- `newFromHit()` 将搜索 hit 转成模型；
- 明确区分 `_source`、`_id`、`_score`、`sort`、highlight 和 aggregation。

### 5.2 Query Builder

内部维护不可变或可安全复制的请求状态，最终编译成 Elasticsearch request body。至少包含：

- term、terms、match、matchPhrase、range、exists、prefix、wildcard；
- bool（must/filter/mustNot/should、minimumShouldMatch）；
- nested；
- source includes/excludes、sort、size/from；
- search_after、PIT、scroll（首版可分阶段交付）；
- highlight、collapse、runtime mappings；
- aggregation 原样节点和常用快捷方法；
- `rawDsl()` 合并策略与冲突校验。

### 5.3 Write/Bulk

- index、create、update、delete；
- bulk 操作构造、分块、响应逐项解析；
- 不在第一版内置队列同步；
- 对 429/5xx 提供可配置重试策略，但不做无限重试。

### 5.4 Index Manager

- create/delete/exists；
- get/put mapping；
- settings；
- alias 切换；
- 基础 reindex 请求封装（不负责业务数据同步）。

### 5.5 Transport 与 Hyperf 协程客户端决策

定义统一传输契约；当前公开客户端按 HTTP 动词提供 `requestGet/requestPost/requestPut/requestDelete`，内部仍由统一 dispatch 处理 endpoint 路由。

**采用官方实现，而不是重写 Handler。** Hyperf 官方文档表明，`hyperf/elasticsearch` 的 `ClientBuilderFactory` 会基于 `elasticsearch-php` 创建客户端；在协程环境中会自动使用 `hyperf/guzzle` 的协程 Handler。本包不再暴露连接池配置。

因此第一版的实现策略是：

1. Core 只依赖 `ClientInterface`/`TransportInterface`，不依赖框架和某个 ES 主版本；
2. Hyperf Bridge 面向 Hyperf 框架 `^3.0`，直接使用官方 ES9 `ClientBuilder`；
4. 若宿主的 `hyperf/elasticsearch` 包版本与目标 ES PHP client 主版本存在 Composer 冲突，本包直接适配官方 `elasticsearch/elasticsearch`，复用 Hyperf Guzzle 工厂，不把 `hyperf/elasticsearch` 作为硬依赖；
5. 这使 Hyperf 3.0+ 可以按同一套 Core API 连接 ES 7/8/9，官方工厂和独立 adapter 的实际组合必须分别跑集成测试；
6. Handler 不作为业务配置项，统一复用官方 Hyperf Guzzle 工厂的自动选择逻辑；
7. 只有官方客户端无法满足某个扩展点时，才实现薄的 adapter，不复制 HTTP、认证、节点选择和重试逻辑。

这里要区分两个口径：Hyperf 官方 `CoroutineHandler` 实际走的是底层 Swoole/Swow 协程 HTTP Client；如果部署环境启用了 `SWOOLE_HOOK_NATIVE_CURL`，普通 cURL 也可能被运行时 hook。第一版统一把“协程化”定义为请求不阻塞 Worker，并优先走官方 Handler；若用户有“必须使用 native cURL hook”的硬要求，需在阶段 0 单独做基准测试，不能仅凭类名或配置宣称满足。必须用并发、超时、取消、连接复用和 Worker 长驻测试证明这一点。

## 6. 参考包评估与取舍

| 项目 | 可借鉴能力 | 不直接作为本包核心的原因 |
| --- | --- | --- |
| [elastic/elasticsearch-php](https://github.com/elastic/elasticsearch) | 官方 endpoint 覆盖、认证、节点和错误模型；作为底层客户端基线 | 没有 Laravel/Hyperf ORM 体验；API 偏请求端点 |
| [hyperf/elasticsearch](https://github.com/hyperf/elasticsearch) | Hyperf 容器工厂、协程 Handler 接入 | 主要是客户端创建封装，不提供 Document Model 和 ORM Query Builder |
| [basemkhirat/elasticsearch](https://github.com/basemkhirat/elasticsearch) | Laravel 风格 Model、索引和查询语法的产品参考 | 需复核当前维护状态、ES/Laravel 版本约束和 DSL 覆盖；不复制其隐式语义 |
| [ruflin/Elastica](https://github.com/ruflin/Elastica) | Query/Filter/Document/Index 的对象化 DSL，成熟的响应抽象 | 自身不是 Laravel/Hyperf 集成层；再套一层会增加依赖和抽象重叠 |
| [matchish/laravel-scout-elasticsearch](https://github.com/matchish/laravel-scout-elasticsearch) | 可参考搜索入口设计 | 本项目第一版不实现 Laravel/Scout 集成 |

取舍结论：底层使用官方 ES9 客户端；Hyperf 使用官方 Guzzle 协程 Handler；Elastica 的对象化 DSL 仅借鉴节点设计；Laravel/Scout 仅作参考，不进入首版代码。

## 7. 后续版本兼容策略

### 7.1 Composer 依赖原则（后续 ES 8/7）

不能在一个强制依赖中同时要求 `elasticsearch/elasticsearch:^7|^8|^9` 并假设 API 完全一致。第一版应采用以下任一落地方式，并在阶段 0 做 Spike 选择：

- **推荐：可选适配包**：`sllhsmile/hyperf-elasticsearch` 只依赖 PSR 契约；由宿主选择 `sllhsmile/elasticsearch-client7`、`-client8` 或 `-client9`。Laravel/Hyperf bridge 再声明对应的 `provide`/`conflict` 约束；
- **单包可选依赖**：核心包将 7/8/9 客户端列为 `suggest`，运行时检测主版本并加载对应 adapter。此方式安装简单，但静态分析和依赖冲突更难控制。

首版不应让 Composer 同时安装多个官方客户端主版本；一个运行实例只绑定一个 ES PHP 客户端主版本。

### 7.2 支持矩阵

| 框架 | ES 7 + client 7 | ES 8 + client 8 | ES 9 + client 9 |
| --- | --- | --- | --- |
| Laravel 10（PHP 8.1+） | 支持 | 支持 | 支持 |
| Laravel 11（PHP 8.2+） | 支持 | 支持 | 支持 |
| Hyperf 3.0+ | 官方 `hyperf/elasticsearch` 或本包 Hyperf 协程 adapter | 官方 `hyperf/elasticsearch`（若依赖兼容）或本包 Hyperf 协程 adapter | 官方 `hyperf/elasticsearch`（若依赖兼容）或本包 Hyperf 协程 adapter |

这里的“支持”必须以对应 CI 组合和 Docker 集成测试为准；ES 服务端与 PHP 客户端主版本不是同一个版本号，文档必须分开描述。

### 7.3 API 兼容隔离

- Core Builder 只生成请求语义，不直接调用某个客户端的 endpoint 方法；
- `ClientAdapterInterface` 负责把统一 Request 编译为 client 7/8/9 的调用方式；
- 版本差异（参数命名、响应对象/数组、异常类、bulk body）集中在 `Elastic7/8/9`；
- 能力探测使用显式 `ClientCapabilities`，不能用 `method_exists` 在业务代码中散落判断；
- 对服务端新特性，优先通过 raw DSL 发送，adapter 只处理协议层差异。

## 8. 配置与集成

统一配置概念，框架只负责加载和绑定：

```php
return [
    'default' => 'default',
    'connections' => [
        'default' => [
            'hosts' => ['https://127.0.0.1:9200'],
            'username' => env('ES_USERNAME'),
            'password' => env('ES_PASSWORD'),
            'api_key' => env('ES_API_KEY'),
            'timeout' => 10,
            'connect_timeout' => 2,
            'retries' => 2,
            'verify_tls' => true,
        ],
    ],
];
```

Hyperf 使用 `config/autoload/elasticsearch.php`、ConfigProvider、DI 定义和 `bin/hyperf.php` 命令。配置包含 hosts、认证、超时、重试和 TLS 参数，Handler 由官方 Guzzle 工厂自动选择。

## 9. 测试与质量门槛

- Core 单元测试：DSL 编译、mapping、结果对象化、异常；
- Transport 契约测试：同一组请求在 Laravel/Hyperf adapter 上行为一致；
- ES 集成测试：使用 Docker Elasticsearch 7.x、8.x、9.x，覆盖查询、bulk、alias 和 mapping；
- Hyperf 协程测试：并发、超时、取消、连接复用；
- 静态检查：PHPStan/Psalm 任选其一，PHP-CS-Fixer；
- 每个新增 DSL 节点配请求快照测试，避免链式 API 静默生成错误 JSON；
- CI 至少覆盖 PHP 8.1、8.2、8.3 与 Hyperf 3.0+ 的允许组合；首版只跑 ES 9.x 集成测试。

## 10. 关键风险与决策

| 风险 | 后果 | 应对 |
| --- | --- | --- |
| 试图完整复制 Eloquent | API 复杂且语义错误 | 只提供文档模型和查询体验，明确 ES 语义 |
| DSL 方法无限膨胀 | 维护成本高、版本滞后 | 节点对象 + raw DSL + 扩展接口 |
| PHP 类型自动推断 mapping | 线上 mapping 错误 | mapping 显式声明，提供校验而非猜测 |
| 深分页误用 | 查询变慢或超限 | 首版文档和 API 强制区分浅分页、search_after、PIT |
| Hyperf 阻塞 IO | Worker 吞吐下降 | 复用 `hyperf/elasticsearch` 官方 Guzzle Handler；并发/超时测试验证 |
| ES PHP 客户端 7/8/9 API 漂移 | 请求、响应和异常不兼容 | 版本适配层隔离；单实例只绑定一个主版本 |
| `hyperf/elasticsearch` 包版本与 client 主版本绑定 | 某些 Hyperf 3.x 项目无法直接安装 client 9 | 将官方包作为可选工厂；冲突时由本包直接注入 Hyperf 协程 Handler 到官方 client 7/8/9 |
| 包范围失控 | 迟迟无法发布 | MVP 只覆盖查询、写入、基础索引管理和 raw DSL |

## 10. 成功标准

首个可发布版本不以“覆盖所有 Elasticsearch API”为成功标准，而以以下结果为标准：

1. Hyperf 3.0+ 应用可以用 Core API 发起查询和写入；
2. Hyperf 下默认请求走协程 Handler，不阻塞 Worker；
3. 常见搜索代码明显短于手写请求数组；
4. 任意未封装 DSL 可以通过 raw DSL 完成；
5. 失败请求有可识别异常、超时和重试行为；
6. 文档、测试和版本边界清晰。

## 11. 实现流程摘要

Composer 通过 PSR-4 加载源码，并读取 `extra.hyperf.config` 指向 `src/ConfigProvider.php`。ConfigProvider 注册 Manager、ClientFactory 和默认客户端绑定，同时把 `publish/elasticsearch.php` 发布到宿主 `config/autoload/elasticsearch.php`。

Manager 按连接名懒加载并缓存 ElasticsearchClient；ClientFactory 延迟调用官方 Hyperf Guzzle 工厂，再交给官方 ES9 ClientBuilder。QueryBuilder 只累积查询状态，search 时将 DSL 放入 body；SearchResponse 再把命中转换成 SearchHit 或 DocumentModel。Bulk 使用 metadata/source 行组织 NDJSON，IndexManager 和 PitManager 按 ES9 endpoint 要求把配置和 PIT id 放在 body 中。

## 12. 分阶段计划与当前进度

| 阶段 | 内容 | 状态 |
| --- | --- | --- |
| 0 | 需求冻结、Hyperf Handler Spike | 已完成 |
| 1 | 契约、配置、ConfigProvider、Manager | 已完成 |
| 2 | ES9 ClientFactory、官方协程 Handler | 已完成 |
| 3 | DocumentModel、SearchHit、SearchResponse | 已完成 |
| 4 | QueryBuilder MVP | 已完成 |
| 5 | 文档写入、Bulk、索引和 alias | 已完成 |
| 6 | search_after、PIT 和生产参数 | 已完成 |
| 7 | Hyperf 宿主集成、README、USAGE | 已完成 |
| 8 | 默认连接与模型 `$connection` 自动解析 | 已完成 |
| 9 | requestGet/requestPost/requestPut/requestDelete API | 已完成 |

验收以 PHPUnit、PHP lint、Composer validate 和 Hyperf CLI 为准；云端 ES 9.6 与真实协程压力测试需使用用户轮换后的安全凭据。

## 13. 关键决策记录

- 首版只支持 Hyperf 3.0+、PHP 8.1+ 和官方 Elasticsearch PHP Client 9；ES 7/8 通过后续独立 adapter 扩展。
- 首版不实现 Laravel 集成和 MySQL 自动同步，不承诺 Eloquent 完整兼容。
- 默认连接名为 `default`，模型通过 `$connection` 属性选择连接，业务日常使用 `Article::query()`。
- Hyperf 协程请求使用官方 Guzzle 工厂自动选择 CoroutineHandler 或 cURL 路径。
- 客户端 endpoint 优先使用官方方法；raw request 仅提供按 HTTP 方法拆分的显式入口。
- README 和 USAGE 面向 GitHub/用户；DESIGN 仅作为本地开发文档，不进入 Composer dist。

## 14. 最新进度（2026-09-03）

- 已完成默认 `default` 连接、模型 `$connection` 自动解析和 Hyperf DI 默认客户端绑定。
- 已完成 requestGet/requestPost/requestPut/requestDelete API，以及 ConfigProvider 根路径和 publish 配置目录调整。
- 已完成 README/USAGE/DESIGN 文档归档；USAGE 与 DESIGN 通过 `.gitattributes` 排除 Composer dist，DESIGN 通过 `.gitignore` 保持本地。
- 验收：包 PHPUnit 14 tests / 40 assertions、PHP lint、Composer validate 通过；宿主 Hyperf CLI 和 autoload 通过。

## 15. 最新进度（2026-09-04）

- 已按 Hyperf 官方 `ClientBuilderFactory` 的 Guzzle 工厂路径重构 `ClientFactory`。
- 已将 `ElasticsearchClient` 改为首次 endpoint 调用时延迟初始化，避免 Worker 启动阶段固定同步 Handler。
- 已移除包配置中的连接池、`handler` 和 `max_connections` 字段；默认行为统一由官方工厂自动选择。
- 已移除宿主测试控制器中对 ES9 不存在的 `setHandler()` 和 `PoolHandler` 调用。
- 验收：包 PHPUnit 14 tests / 38 assertions、宿主 PHP lint、Composer autoload 和 Hyperf CLI 通过。
- 已进一步改为直接依赖并调用 Hyperf 官方 `ClientBuilderFactory`；不再自行 new Guzzle 客户端或判断协程环境。
- `timeout`、`connect_timeout`、`client_options` 不通过 `setHttpClientOptions()` 注入，避免 ES9 Builder 重建客户端并丢失协程 Handler/AOP；自定义 headers 通过 Transport 安全追加。

## 16. v0.0.2 修复记录（2026-09-04）

本节记录本次代码审查后的具体实现变更，README 仅保留用户安装、配置和功能入口说明。

### 16.1 请求体与 endpoint 语义

- `QueryBuilder::search()` 将完整查询 DSL 放入官方客户端参数的 `body`，避免 `query`、`aggs`、`highlight` 等字段被错误当作 URL 参数而丢失。
- `IndexManager` 的 `create`、`putMapping`、`putSettings`、`updateAliases` 统一使用 `body`；PIT 关闭请求使用 `body.id`。
- Alias 的 `is_write_index`、`filter` 等选项嵌套到 `actions[].add`/`actions[].remove`，并固定目标 `index` 与 `alias` 不允许被 options 覆盖。

### 16.2 配置校验与异常边界

- Basic Auth 的 `username`、`password` 必须成对配置；部分配置在 `ConnectionConfig` 校验阶段抛出 `ConfigurationException`，防止请求静默缺少认证。
- endpoint 调用统一经过 `ElasticsearchClient::execute()`：官方响应错误转换为包内 `ResponseException`，传输/HTTP 客户端错误转换为 `TransportException`，其余官方异常转换为基础 `ElasticsearchException`，原始异常保存在 `getPrevious()`。
- `raw()` 保留为官方客户端逃生入口，不做异常适配，便于高级用户直接使用官方异常类型。

### 16.3 通用 request 路由安全性

- `requestGet/requestPost/requestPut/requestDelete` 对根路径、search、mapping、bulk、索引和文档路径执行显式 HTTP 方法白名单校验。
- 不支持的方法统一抛出 `BadMethodCallException`，不会再把 DELETE mapping 错误分派为 `putMapping`。

### 16.4 查询参数完整性

- `minimumShouldMatch(int|string)` 保留百分比和条件表达式字符串语义。
- `orderBy()` 先校验 `$direction`，再合并额外 options；options 中的 `order` 不得覆盖已校验方向，其他排序参数仍然保留。

### 16.5 验证结果

- PHPUnit：19 tests / 45 assertions 通过。
- 关键源码 PHP lint 和 `git diff --check` 通过。
- Composer 1 环境无法解析 ES9/Hyperf 依赖，`composer validate` 仅提示锁文件与版本字段警告；依赖解析应使用 Composer 2。
