<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Hyperf\Factory;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Guzzle\ClientFactory as GuzzleClientFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Config\ConnectionConfig;
use SllhSmile\Elasticsearch\Exception\ConfigurationException;
use Swoole\Runtime;

/** 通过官方 SDK 8/9 Builder 与 Hyperf Guzzle 创建协程客户端。 */
final class ClientFactory
{
    /** 保存容器供首次请求解析 Guzzle 工厂，构造阶段不创建 HTTP 客户端。 */
    public function __construct(private readonly ContainerInterface $container) {}

    /** 返回可延迟构建并在传输故障后重建的客户端。 */
    public function make(ConnectionConfig $config): ElasticsearchClient
    {
        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class) : null;
        return ElasticsearchClient::fromFactory(
            fn(): Client => $this->build($config),
            fn(): string => $this->transportScope(),
            $logger instanceof LoggerInterface ? $logger : null,
        );
    }

    /** 首次请求所在协程决定 HTTP Handler，同时应用连接、认证与重试配置。 */
    private function build(ConnectionConfig $config): Client
    {
        // 直接创建官方 Builder，解除旧版 hyperf/elasticsearch 对 SDK 7 的依赖。
        $builder = ClientBuilder::create();
        $builder->setHosts($config->hosts);
        // 保留 Hyperf 容器创建 Guzzle 的路径，使协程选择与宿主 AOP 继续生效。
        $builder->setHttpClient($this->resolveGuzzleFactory()->create([
            'timeout' => $config->timeout,
            'verify' => $config->verifyTls,
            // Hyperf 协程 Handler 的默认值允许自签名证书，需显式覆盖。
            'swoole' => ['ssl_allow_self_signed' => false],
        ]));
        if ($config->apiKey !== null) {
            $builder->setApiKey($config->apiKey);
        } elseif ($config->username !== null && $config->password !== null) {
            $builder->setBasicAuthentication($config->username, $config->password);
        }

        $client = $builder->build();
        $transport = $client->getTransport();
        // SDK Builder 把 0 当成节点数默认值；构建后恢复才能真正禁用重试。
        $transport->setRetries($config->retries);
        foreach ($config->headers as $name => $value) {
            $transport->setHeader($name, $value);
        }
        return $client;
    }

    /** 解析 Hyperf Guzzle 工厂，并将容器绑定错误统一报告为配置异常。 */
    private function resolveGuzzleFactory(): GuzzleClientFactory
    {
        if (! $this->container->has(GuzzleClientFactory::class)) {
            throw new ConfigurationException('Unable to resolve Hyperf Guzzle ClientFactory.');
        }
        try {
            $factory = $this->container->get(GuzzleClientFactory::class);
        } catch (\Throwable $exception) {
            throw new ConfigurationException('Unable to resolve Hyperf Guzzle ClientFactory.', 0, $exception);
        }
        if (! $factory instanceof GuzzleClientFactory) {
            throw new ConfigurationException('Hyperf Guzzle ClientFactory binding is invalid.');
        }
        return $factory;
    }

    /**
     * 返回与 Hyperf Guzzle 选择逻辑一致的缓存作用域。
     *
     * 协程内开启 native cURL hook 时仍使用普通 Guzzle Handler，只有实际使用
     * CoroutineHandler 时才需要独立官方客户端，避免首次调用环境污染 Worker。
     */
    private function transportScope(): string
    {
        if (! extension_loaded('swoole') || ! Coroutine::inCoroutine()) {
            return 'default';
        }
        $nativeCurlHook = defined('SWOOLE_HOOK_NATIVE_CURL') ? (int) constant('SWOOLE_HOOK_NATIVE_CURL') : 0;
        if ($nativeCurlHook !== 0 && (Runtime::getHookFlags() & $nativeCurlHook) !== 0) {
            return 'default';
        }
        return 'coroutine';
    }
}
