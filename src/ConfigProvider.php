<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch;

use Psr\Container\ContainerInterface;
use SllhSmile\Elasticsearch\Hyperf\Factory\ClientFactory;
use SllhSmile\Elasticsearch\Hyperf\Manager;

use function Hyperf\Config\config;

/** Hyperf 包配置入口：注册 DI 服务并声明配置发布文件。 */
final class ConfigProvider
{
    /** @return array<string, mixed> */
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
