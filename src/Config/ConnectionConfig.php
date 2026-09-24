<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Config;

use SllhSmile\Elasticsearch\Exception\ConfigurationException;

/**
 * 单个连接的不可变配置；只接受包能跨 SDK 版本兑现的选项。
 */
final class ConnectionConfig
{
    /** @var list<string> */
    private const KEYS = [
        'hosts', 'api_key', 'username', 'password', 'timeout', 'retries', 'verify_tls', 'headers',
    ];

    /**
     * 构造时校验连接和认证，避免错误配置首次发出请求才暴露。
     *
     * @param array<mixed> $hosts
     * @param array<mixed> $headers
     */
    public function __construct(
        public readonly array $hosts,
        public readonly ?string $apiKey = null,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly int $timeout = 10,
        public readonly int $retries = 1,
        public readonly bool|string $verifyTls = true,
        public readonly array $headers = [],
    ) {
        if ($this->hosts === []) {
            throw new ConfigurationException('Elasticsearch hosts cannot be empty.');
        }
        foreach ($this->hosts as $host) {
            if (! is_string($host) || filter_var($host, FILTER_VALIDATE_URL) === false
                || ! in_array(parse_url($host, PHP_URL_SCHEME), ['http', 'https'], true)
                || parse_url($host, PHP_URL_HOST) === null
                || parse_url($host, PHP_URL_USER) !== null
                || parse_url($host, PHP_URL_PASS) !== null
                || parse_url($host, PHP_URL_QUERY) !== null
                || parse_url($host, PHP_URL_FRAGMENT) !== null) {
                throw new ConfigurationException('Elasticsearch hosts must be valid http/https URLs without credentials, query or fragment.');
            }
        }
        if ($this->timeout < 1 || $this->retries < 0) {
            throw new ConfigurationException('Elasticsearch timeout must be positive and retries must be non-negative.');
        }
        if ($this->apiKey !== null && ($this->apiKey === '' || trim($this->apiKey) !== $this->apiKey)) {
            throw new ConfigurationException('Elasticsearch API key cannot be empty or contain surrounding whitespace.');
        }
        if ($this->apiKey !== null && ($this->username !== null || $this->password !== null)) {
            throw new ConfigurationException('ApiKey and basic authentication cannot be configured together.');
        }
        if (($this->username === null) !== ($this->password === null)
            || $this->username === '' || $this->password === '') {
            throw new ConfigurationException('Basic authentication requires both a non-empty username and password.');
        }
        if (is_string($this->verifyTls)
            && ($this->verifyTls === '' || ! file_exists($this->verifyTls) || ! is_readable($this->verifyTls)
                || (! is_file($this->verifyTls) && ! is_dir($this->verifyTls)))) {
            throw new ConfigurationException('Elasticsearch TLS CA path must be an existing readable file or directory.');
        }
        foreach ($this->headers as $name => $value) {
            if (! is_string($name) || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1
                || strcasecmp($name, 'Authorization') === 0
                || ! is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new ConfigurationException('Elasticsearch headers must be valid strings and cannot override Authorization.');
            }
        }
    }

    /**
     * 严格读取配置；旧的无效选项显式失败，避免误以为仍然生效。
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw new ConfigurationException("Unknown Elasticsearch connection option [{$key}].");
            }
        }
        $hosts = $config['hosts'] ?? null;
        $headers = $config['headers'] ?? [];
        $timeout = $config['timeout'] ?? 10;
        $retries = $config['retries'] ?? 1;
        $verifyTls = $config['verify_tls'] ?? true;
        if (! is_array($hosts) || ! array_is_list($hosts) || ! is_array($headers)
            || ! is_int($timeout) || ! is_int($retries) || (! is_bool($verifyTls) && ! is_string($verifyTls))) {
            throw new ConfigurationException('Elasticsearch hosts, timeout, retries, verify_tls or headers have invalid types.');
        }
        foreach (['api_key', 'username', 'password'] as $key) {
            if (isset($config[$key]) && ! is_string($config[$key])) {
                throw new ConfigurationException("Elasticsearch option [{$key}] must be a string or null.");
            }
        }

        return new self(
            hosts: $hosts,
            apiKey: $config['api_key'] ?? null,
            username: $config['username'] ?? null,
            password: $config['password'] ?? null,
            timeout: $timeout,
            retries: $retries,
            verifyTls: $verifyTls,
            headers: $headers,
        );
    }
}
