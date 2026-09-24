# Changelog

## 0.2.0 - 2026-09-24

### Compatibility and design

- 仅支持 Elasticsearch Server / PHP SDK 8、9，取消 SDK 7 与 RingPHP 传输分支。
- 保留 PHP ≥8.1、Hyperf 3.0/3.1/3.2；Hyperf 3.2 自身要求 PHP ≥8.2。
- 删除 `hyperf/elasticsearch` 依赖：旧版工厂绑定 SDK 7，包内改用官方 SDK 8/9 Builder，继续通过 Hyperf Guzzle 工厂保留协程 Handler 和容器 AOP。
- SDK 8/9 共用官方 `Client`、endpoint 与响应契约，不再探测 SDK 主版本或保留空壳版本 Adapter。
- 删除 RingPHP TLS Handler 及相关测试，保留 Hyperf Guzzle 的严格 TLS、认证、Header、超时和零重试行为。
- CI 中旧 Hyperf 改为覆盖 SDK 8/9，真实 ES8 测试通过 Hyperf 3.1 的包内工厂在协程中执行。

### Changed

- `SearchResponse::total()` 改为返回 `?TotalHits`，同时保留 Elasticsearch 的 `value` 与 `eq/gte` 关系；`QueryBuilder::count()` 继续返回精确整数。
- `DocumentModel::find()` 仅将明确的文档未命中转换为 `null`，索引缺失和无法识别的 404 继续抛出 `ResponseException`。
- PIT 查询不再同时发送 `index`，并修正深分页游标示例。
- TLS、认证、总超时和重试配置改为严格校验；`retries=0` 会真正禁用 Transport 重试。
- Manager 构造时校验完整配置但保持网络懒加载，并成为唯一对业务公开的 DI 生命周期入口。
- 固定官方客户端与可重建工厂通过 `ElasticsearchClient::fromClient()`、`fromFactory()` 明确区分。
- 普通/native-cURL 与 `CoroutineHandler` 使用独立 Adapter 缓存；传输失败只淘汰当前失败实例，避免跨协程竞态误删健康客户端。
- 只有确定的网络/节点异常会触发客户端重建；SDK 参数和序列化错误、业务异常保持各自语义。
- 未知响应类型不再通过 PHP 强制转换静默接受。
- `DocumentModel` 未声明连接时遵循 Manager 默认连接，显式 `null` 属性及 nullable casts 保持 `null`。
- 静态分析提升至 PHPStan level 8，并覆盖 `src` 与 `tests`。
- 新增 PHP/Hyperf/SDK 分层 CI、ES8/9 真实服务冒烟测试和 Swoole 并发基线脚本。

### Removed

- 删除与 `rawDsl()` 能力重复且语义含糊的 `QueryBuilder::replaceDsl()`；完整 DSL 使用全新 Builder 调用 `rawDsl()`。
- 删除无法在协程路径稳定兑现的 `connect_timeout` 和不受控的 `client_options`。
- 删除默认 `ElasticsearchClient`/`ClientInterface` 容器绑定，仅保留 `Manager` 连接入口。
- 删除 `DocumentModel::setClient()`、`AdapterFactory`、`ClientMajor`、`ClientCapabilities`、三个空壳版本 Adapter 及无消费者接口。
- 删除有限 REST 路由语法糖和直接 `raw()`/`indices()` 入口；高级 endpoint 使用 `execute()`。

这些变更属于 0.x 阶段的公共契约收敛，后续发布时应使用新的次版本号。
