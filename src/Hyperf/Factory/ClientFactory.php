<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Hyperf\Factory;

use Hyperf\Elasticsearch\ClientBuilderFactory;
use Psr\Container\ContainerInterface;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Config\ConnectionConfig;
use SllhSmile\Elasticsearch\Exception\ConfigurationException;

/**
 * 通过 Hyperf 官方 ClientBuilderFactory 创建 Elasticsearch 客户端。
 *
 * 官方工厂负责把 Hyperf Guzzle 客户端注入 ES Builder，并依据当前协程
 * 状态选择 CoroutineHandler/cURL 路径。本类只负责连接配置和 ORM 适配，
 * 不重复判断协程环境，也不自行创建 Guzzle Handler。
 */
final class ClientFactory
{
    public function __construct(private readonly ?ContainerInterface $container = null)
    {
    }

    /** 返回延迟初始化的适配器，不会在这里发起网络请求。 */
    public function make(ConnectionConfig $config): ElasticsearchClient
    {
        return new ElasticsearchClient(function () use ($config): object {
            return $this->build($config);
        });
    }

    /** 在第一次 endpoint 调用时使用官方 Hyperf 工厂构建 ES 客户端。 */
    private function build(ConnectionConfig $config): object
    {
        if ($this->container === null) {
            throw new ConfigurationException(
                'Hyperf Elasticsearch ClientBuilderFactory requires a Hyperf DI container.'
            );
        }

        try {
            /** @var ClientBuilderFactory $factory */
            $factory = $this->container->get(ClientBuilderFactory::class);
        } catch (\Throwable $e) {
            throw new ConfigurationException(
                'Unable to resolve Hyperf Elasticsearch ClientBuilderFactory; install hyperf/elasticsearch.',
                0,
                $e,
            );
        }

        if (! $factory instanceof ClientBuilderFactory) {
            throw new ConfigurationException('Hyperf Elasticsearch ClientBuilderFactory binding is invalid.');
        }

        $builder = $factory->create()
            ->setHosts($config->hosts)
            ->setRetries($config->retries);

        if ($config->apiKey !== null) {
            $builder->setApiKey($config->apiKey);
        } elseif ($config->username !== null && $config->password !== null) {
            $builder->setBasicAuthentication($config->username, $config->password);
        }

        if (is_bool($config->verifyTls)) {
            $builder->setSSLVerification($config->verifyTls);
        } elseif (is_string($config->verifyTls)) {
            $builder->setCABundle($config->verifyTls);
        }

        // setHttpClientOptions() 会通过适配器重建 Guzzle 客户端，可能丢失
        // Hyperf Handler 和 sdklog AOP，因此不在这里调用。自定义 headers
        // 可在 Transport 层追加，不会重建底层 HTTP 客户端。
        $client = $builder->build();
        foreach ($config->headers as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $client->getTransport()->setHeader($name, (string) $value);
            }
        }

        return $client;
    }
}
