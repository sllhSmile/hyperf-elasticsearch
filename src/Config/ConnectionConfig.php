<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Config;

use SllhSmile\Elasticsearch\Exception\ConfigurationException;

/**
 * 单个 ES 连接的不可变配置对象，并在构造时执行安全校验。
 */
final class ConnectionConfig
{
    /** 构造后立即校验连接参数，避免运行时才发现认证或 Handler 配置错误。 */
    public function __construct(
        public readonly array $hosts,
        public readonly ?string $apiKey = null,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly int $timeout = 10,
        public readonly int $connectTimeout = 5,
        public readonly int $retries = 1,
        public readonly bool|string $verifyTls = true,
        public readonly array $headers = [],
        public readonly array $clientOptions = [],
    ) {
        if ($this->hosts === []) {
            throw new ConfigurationException('Elasticsearch hosts cannot be empty.');
        }
        if ($this->timeout < 1 || $this->connectTimeout < 1 || $this->retries < 0) {
            throw new ConfigurationException('Invalid Elasticsearch timeout, connect_timeout or retries.');
        }
        if ($this->apiKey !== null && ($this->username !== null || $this->password !== null)) {
            throw new ConfigurationException('ApiKey and basic authentication cannot be configured together.');
        }
        $hasUsername = $this->username !== null && $this->username !== '';
        $hasPassword = $this->password !== null && $this->password !== '';
        if ($hasUsername xor $hasPassword) {
            throw new ConfigurationException('Basic authentication requires both username and password.');
        }
    }

    /** 从 Hyperf 配置数组读取并归一化连接参数。 */
    public static function fromArray(array $config): self
    {
        return new self(
            hosts: array_values($config['hosts'] ?? []),
            apiKey: isset($config['api_key']) ? (string) $config['api_key'] : null,
            username: isset($config['username']) ? (string) $config['username'] : null,
            password: isset($config['password']) ? (string) $config['password'] : null,
            timeout: (int) ($config['timeout'] ?? 10),
            connectTimeout: (int) ($config['connect_timeout'] ?? 5),
            // 与官方 ClientBuilder 默认行为保持一致：单节点默认一次重试。
            retries: (int) ($config['retries'] ?? 1),
            verifyTls: $config['verify_tls'] ?? true,
            headers: (array) ($config['headers'] ?? []),
            clientOptions: (array) ($config['client_options'] ?? []),
        );
    }
}
