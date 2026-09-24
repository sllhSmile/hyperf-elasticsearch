<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // 默认连接名称；Manager::connection() 未传名称时使用该值。
    // 它必须对应 connections 中的一个键，例如 default 或 archive。
    'default' => 'default',
    'connections' => [
        'default' => [
            // Elasticsearch 节点地址列表。支持一个或多个 http/https URL；
            // 多节点时官方客户端会按节点池策略选择可用节点。
            'hosts' => [env('ELASTICSEARCH_HOST', 'http://127.0.0.1:9200')],
            // API Key 认证值，填写 Elasticsearch 返回的 base64 encoded key。
            // 与 username/password 互斥，建议仅通过环境变量注入。
            'api_key' => env('ELASTICSEARCH_API_KEY'),
            // Basic Authentication 用户名；必须与 password 一起配置。
            'username' => env('ELASTICSEARCH_USERNAME'),
            // Basic Authentication 密码；不要提交到代码仓库或日志。
            'password' => env('ELASTICSEARCH_PASSWORD'),
            // 单次请求总超时（秒）；协程 Handler 不提供独立的连接超时。
            'timeout' => (int) env('ELASTICSEARCH_TIMEOUT', 10),
            // 官方客户端在节点失败时的重试次数。0 表示不重试。
            'retries' => (int) env('ELASTICSEARCH_RETRIES', 1),
            // TLS 证书校验：true 启用校验，false 禁用校验（仅限受控测试环境），
            // 也可以填写 CA 证书文件路径。仅对 https 连接有意义。
            'verify_tls' => env('ELASTICSEARCH_VERIFY_TLS', true),
            // 追加到每个 Elasticsearch 请求的 HTTP Header，例如 X-Request-Source。
            // Authorization 禁止覆盖，应使用 api_key 或 username/password。
            'headers' => [],
            // Handler 不需要配置；Hyperf Guzzle ClientFactory 会根据当前
            // 协程环境自动选择 CoroutineHandler 或 cURL 路径。
        ],
    ],
];
