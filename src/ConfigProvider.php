<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch;

use Psr\Container\ContainerInterface;
use SllhSmile\Elasticsearch\Client\ElasticsearchClient;
use SllhSmile\Elasticsearch\Contract\ClientInterface;
use SllhSmile\Elasticsearch\Hyperf\Factory\ClientFactory;
use SllhSmile\Elasticsearch\Hyperf\Manager;

use function Hyperf\Config\config;

/** Hyperf 包配置入口：注册 DI 服务并声明配置发布文件。 */
final class ConfigProvider
{
    /** 返回 Hyperf 启动时合并的依赖和 publish 配置。 */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Manager::class => static function (ContainerInterface $container): Manager {
                    $config = (array) config('elasticsearch', []);
                    return new Manager($container->get(ClientFactory::class), $config);
                },
                // 显式传入容器，供官方 Hyperf Guzzle 工厂按需创建协程客户端。
                ClientFactory::class => static function (ContainerInterface $container): ClientFactory {
                    return new ClientFactory($container);
                },
                // 默认连接客户端由 Manager 缓存；业务服务可直接注入该类型。
                ElasticsearchClient::class => static function (ContainerInterface $container): ElasticsearchClient {
                    return $container->get(Manager::class)->connection();
                },
                ClientInterface::class => static function (ContainerInterface $container): ClientInterface {
                    return $container->get(ElasticsearchClient::class);
                },
            ],
            'publish' => [
                [
                    'id' => 'elasticsearch-config',
                    'description' => 'Elasticsearch configuration',
                    'source' => __DIR__ . '/../publish/elasticsearch.php',
                    'destination' => (defined('BASE_PATH') ? BASE_PATH : '') . '/config/autoload/elasticsearch.php',
                ],
            ],
        ];
    }
}
