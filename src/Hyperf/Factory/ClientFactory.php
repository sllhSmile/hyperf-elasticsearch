<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Hyperf\Factory;

use Hyperf\Coroutine\Coroutine;
use Hyperf\Elasticsearch\ClientBuilderFactory;
use Hyperf\Guzzle\ClientFactory as GuzzleClientFactory;
use Hyperf\Guzzle\RingPHP\CoroutineHandler;
use Psr\Container\ContainerInterface;
use SllhSmile\Elasticsearch\Adapter\AdapterFactory;
use SllhSmile\Elasticsearch\Adapter\ClientMajor;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Config\ConnectionConfig;
use SllhSmile\Elasticsearch\Exception\ConfigurationException;

/** 通过匹配当前 Hyperf 版本的官方工厂创建 ES7/8/9 客户端。 */
final class ClientFactory
{
    public function __construct(private readonly ?ContainerInterface $container = null)
    {
    }

    /** 返回延迟初始化的客户端，实际 Builder 在第一次请求时创建。 */
    public function make(ConnectionConfig $config): ElasticsearchClient
    {
        return new ElasticsearchClient(fn (): object => $this->build($config));
    }

    private function build(ConnectionConfig $config): object
    {
        $major = AdapterFactory::detectMajor();
        $builder = $this->resolveBuilderFactory()->create();
        $builder->setHosts($config->hosts);
        $builder->setRetries($config->retries);

        if ($major === ClientMajor::ES7) {
            $this->configureElastic7($builder, $config);
        } else {
            $this->configureElastic8Or9($builder, $config);
        }

        $client = $builder->build();
        if ($major !== ClientMajor::ES7) {
            $transport = $client->getTransport();
            foreach ($config->headers as $name => $value) {
                if (is_string($name) && (is_string($value) || is_numeric($value))) {
                    $transport->setHeader($name, (string) $value);
                }
            }
        }
        return $client;
    }

    private function resolveBuilderFactory(): ClientBuilderFactory
    {
        if ($this->container === null) {
            throw new ConfigurationException(
                'Hyperf Elasticsearch ClientBuilderFactory requires a Hyperf DI container.'
            );
        }

        try {
            $factory = $this->container->get(ClientBuilderFactory::class);
        } catch (\Throwable $exception) {
            throw new ConfigurationException(
                'Unable to resolve Hyperf Elasticsearch ClientBuilderFactory.',
                0,
                $exception,
            );
        }
        if (! $factory instanceof ClientBuilderFactory) {
            throw new ConfigurationException('Hyperf Elasticsearch ClientBuilderFactory binding is invalid.');
        }
        return $factory;
    }

    /** ES7 使用 RingPHP handler；连接参数必须在认证设置之前写入。 */
    private function configureElastic7(object $builder, ConnectionConfig $config): void
    {
        $headers = $this->normalizeElastic7Headers($config->headers);
        if ($config->apiKey !== null) {
            // ES7 setApiKey() 要求 id、secret 两个参数；直接使用跨版本一致的 encoded key。
            $headers['Authorization'] = ['ApiKey ' . $config->apiKey];
        }
        $clientOptions = array_replace($config->clientOptions, [
            'timeout' => $config->timeout,
            'connect_timeout' => $config->connectTimeout,
            'headers' => array_replace(
                (array) ($config->clientOptions['headers'] ?? []),
                $headers,
            ),
        ]);
        $builder->setConnectionParams(['client' => $clientOptions]);

        if ($config->username !== null && $config->password !== null) {
            $builder->setBasicAuthentication($config->username, $config->password);
        }
        $builder->setSSLVerification($config->verifyTls);

        // Hyperf 3.0/3.1 的 CoroutineHandler 只从构造参数读取请求超时。
        if (class_exists(Coroutine::class) && Coroutine::inCoroutine()) {
            $builder->setHandler(new CoroutineHandler(['timeout' => $config->timeout]));
        }
    }

    /** ES8/9 必须一次性创建带 Handler、超时和 TLS 的 Guzzle client。 */
    private function configureElastic8Or9(object $builder, ConnectionConfig $config): void
    {
        $httpOptions = array_replace($config->clientOptions, [
            'timeout' => $config->timeout,
            'connect_timeout' => $config->connectTimeout,
            'verify' => $config->verifyTls,
            'headers' => array_replace(
                (array) ($config->clientOptions['headers'] ?? []),
                $config->headers,
            ),
        ]);
        $builder->setHttpClient($this->resolveGuzzleFactory()->create($httpOptions));

        if ($config->apiKey !== null) {
            $builder->setApiKey($config->apiKey);
        } elseif ($config->username !== null && $config->password !== null) {
            $builder->setBasicAuthentication($config->username, $config->password);
        }
    }

    private function resolveGuzzleFactory(): GuzzleClientFactory
    {
        if ($this->container === null || ! $this->container->has(GuzzleClientFactory::class)) {
            throw new ConfigurationException('Unable to resolve Hyperf Guzzle ClientFactory.');
        }
        $factory = $this->container->get(GuzzleClientFactory::class);
        if (! $factory instanceof GuzzleClientFactory) {
            throw new ConfigurationException('Hyperf Guzzle ClientFactory binding is invalid.');
        }
        return $factory;
    }

    /** @return array<string, list<string>> */
    private function normalizeElastic7Headers(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (! is_string($name) || (! is_string($value) && ! is_numeric($value))) {
                continue;
            }
            $normalized[$name] = [(string) $value];
        }
        return $normalized;
    }
}
