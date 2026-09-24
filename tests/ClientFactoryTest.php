<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use Elastic\Elasticsearch\Client;
use GuzzleHttp\Client as HttpClient;
use Hyperf\Guzzle\ClientFactory as GuzzleClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SllhSmile\Elasticsearch\Config\ConnectionConfig;
use SllhSmile\Elasticsearch\Hyperf\Factory\ClientFactory;

final class ClientFactoryTest extends TestCase
{
    /** 无 Hyperf Elasticsearch 工厂绑定时仍应用 HTTP 配置。 */
    public function testElastic8Or9KeepsTimeoutTlsAndHeadersOnHyperfClient(): void
    {
        $container = $this->container();

        $client = (new ClientFactory($container))->make(new ConnectionConfig(
            hosts: ['https://example.test'],
            timeout: 17,
            verifyTls: __FILE__,
            headers: ['X-Test' => 'factory'],
        ))->execute(static fn(object $official): object => $official);
        self::assertInstanceOf(Client::class, $client);
        $http = $client->getTransport()->getClient();
        self::assertInstanceOf(HttpClient::class, $http);
        $httpConfig = $http->getConfig();

        self::assertSame(17, $httpConfig['timeout']);
        self::assertSame(__FILE__, $httpConfig['verify']);
        self::assertArrayNotHasKey('X-Test', $httpConfig['headers']);
        self::assertSame('factory', $client->getTransport()->getHeaders()['X-Test']);
        self::assertFalse($httpConfig['swoole']['ssl_allow_self_signed']);
    }

    /** Builder 构造后零重试和认证仍保持配置值。 */
    public function testZeroRetriesAndAuthenticatedHeaderSurviveBuilderDefaults(): void
    {
        $client = (new ClientFactory($this->container()))->make(new ConnectionConfig(
            hosts: ['https://example.test'],
            apiKey: 'encoded-key',
            retries: 0,
            headers: ['X-Test' => 'factory'],
        ))->execute(static fn(object $official): object => $official);
        self::assertInstanceOf(Client::class, $client);

        self::assertSame(0, $client->getTransport()->getRetries());
        self::assertSame('ApiKey encoded-key', $client->getTransport()->getHeaders()['Authorization']);
    }

    /** Basic Auth 与显式关闭 TLS 在 SDK 8/9 中保持一致。 */
    public function testBasicAuthAndDisabledTlsAreApplied(): void
    {
        $client = (new ClientFactory($this->container()))->make(new ConnectionConfig(
            hosts: ['https://example.test'],
            username: 'test-user',
            password: 'test-password',
            verifyTls: false,
        ))->execute(static fn(object $official): object => $official);
        self::assertInstanceOf(Client::class, $client);

        $transport = $client->getTransport();
        $user = new \ReflectionProperty($transport, 'user');
        $password = new \ReflectionProperty($transport, 'password');
        self::assertSame('test-user', $user->getValue($transport));
        self::assertSame('test-password', $password->getValue($transport));
        $http = $transport->getClient();
        self::assertInstanceOf(HttpClient::class, $http);
        self::assertFalse($http->getConfig('verify'));
    }

    /** 强制测试非 native cURL 的协程 Handler，验证严格 TLS 选项实际进入 Handler。 */
    public function testCoroutineHandlerUsesStrictTlsAndContainerCreation(): void
    {
        if (! function_exists('Swoole\Coroutine\run')) {
            self::markTestSkipped('Swoole is required to verify the coroutine handler.');
        }
        $previousFlags = \Swoole\Runtime::getHookFlags();
        $failure = null;
        try {
            \Swoole\Runtime::enableCoroutine(0);
            \Swoole\Coroutine\run(function () use (&$failure): void {
                try {
                    $client = (new ClientFactory($this->container()))->make(new ConnectionConfig(
                        hosts: ['https://cluster.example.test'],
                        timeout: 17,
                        verifyTls: __FILE__,
                        headers: ['X-Test' => 'transport'],
                    ))->execute(static fn(object $official): object => $official);
                    self::assertInstanceOf(Client::class, $client);
                    $http = $client->getTransport()->getClient();
                    self::assertInstanceOf(HttpClient::class, $http);
                    self::assertTrue($http->getConfig('created_by_container'));
                    self::assertSame('transport', $client->getTransport()->getHeaders()['X-Test']);
                    $stack = $http->getConfig('handler');
                    $property = new \ReflectionProperty(\GuzzleHttp\HandlerStack::class, 'handler');
                    $handler = $property->getValue($stack);
                    self::assertInstanceOf(\Hyperf\Guzzle\CoroutineHandler::class, $handler);
                    $method = new \ReflectionMethod($handler, 'getSettings');
                    $settings = $method->invoke($handler, new \GuzzleHttp\Psr7\Request('GET', 'https://cluster.example.test'), $http->getConfig());
                    self::assertIsArray($settings);
                    self::assertTrue($settings['ssl_verify_peer']);
                    self::assertFalse($settings['ssl_allow_self_signed']);
                    self::assertSame('cluster.example.test', $settings['ssl_host_name']);
                    self::assertSame(__FILE__, $settings['ssl_cafile']);
                    self::assertSame(17, $settings['timeout']);
                } catch (\Throwable $exception) {
                    $failure = $exception;
                }
            });
        } finally {
            \Swoole\Runtime::enableCoroutine($previousFlags);
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** 协程外的首次使用不得将后续协程永久固定到普通 Handler。 */
    public function testClientsAreCachedSeparatelyByTransportMode(): void
    {
        if (! function_exists('Swoole\Coroutine\run')) {
            self::markTestSkipped('Swoole is required to verify transport mode isolation.');
        }
        $client = (new ClientFactory($this->container()))->make(new ConnectionConfig(
            hosts: ['https://cluster.example.test'],
        ));
        $outside = $client->execute(static fn(object $official): object => $official);
        self::assertInstanceOf(Client::class, $outside);

        $previousFlags = \Swoole\Runtime::getHookFlags();
        $inside = null;
        $failure = null;
        try {
            \Swoole\Runtime::enableCoroutine(0);
            \Swoole\Coroutine\run(static function () use ($client, &$inside, &$failure): void {
                try {
                    $inside = $client->execute(static fn(object $official): object => $official);
                } catch (\Throwable $exception) {
                    $failure = $exception;
                }
            });
        } finally {
            \Swoole\Runtime::enableCoroutine($previousFlags);
        }
        if ($failure !== null) {
            throw $failure;
        }

        self::assertInstanceOf(Client::class, $inside);
        self::assertNotSame($outside, $inside);
        self::assertSame($outside, $client->execute(static fn(object $official): object => $official));
        $insideHttp = $inside->getTransport()->getClient();
        self::assertInstanceOf(HttpClient::class, $insideHttp);
        $stack = $insideHttp->getConfig('handler');
        $property = new \ReflectionProperty(\GuzzleHttp\HandlerStack::class, 'handler');
        self::assertInstanceOf(\Hyperf\Guzzle\CoroutineHandler::class, $property->getValue($stack));
    }

    /** 提供 Guzzle 工厂及容器 make 入口，模拟宿主 AOP 创建路径。 */
    private function container(): ContainerInterface
    {
        $container = new class implements ContainerInterface {
            /** @var array<string, object> */
            public array $services = [];

            /** 返回测试绑定，缺失服务直接失败。 */
            public function get(string $id): mixed
            {
                return $this->services[$id] ?? throw new \RuntimeException($id);
            }

            /** 仅声明显式注册的服务。 */
            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
            /**
             * 标记 HTTP 客户端确实经容器创建，保持 Hyperf Guzzle 的 AOP 入口。
             *
             * @param array{config: array<string, mixed>} $parameters
             */
            public function make(string $id, array $parameters): HttpClient
            {
                if ($id !== HttpClient::class) {
                    throw new \RuntimeException($id);
                }
                return new HttpClient($parameters['config'] + ['created_by_container' => true]);
            }
        };
        $container->services[GuzzleClientFactory::class] = new GuzzleClientFactory($container);
        return $container;
    }
}
