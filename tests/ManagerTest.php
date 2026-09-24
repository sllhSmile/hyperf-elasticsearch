<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SllhSmile\Elasticsearch\Exception\ConfigurationException;
use SllhSmile\Elasticsearch\Hyperf\Factory\ClientFactory;
use SllhSmile\Elasticsearch\Hyperf\Manager;

final class ManagerTest extends TestCase
{
    public function testNamedConnectionsAreCachedAndPurgeCreatesNewWrapper(): void
    {
        $manager = new Manager(new ClientFactory($this->container()), [
            'default' => 'primary',
            'connections' => [
                'primary' => ['hosts' => ['https://primary.example.test']],
                'archive' => ['hosts' => ['https://archive.example.test']],
            ],
        ]);

        $primary = $manager->connection();
        self::assertSame($primary, $manager->connection('primary'));
        self::assertNotSame($primary, $manager->connection('archive'));
        $manager->purge('primary');
        self::assertNotSame($primary, $manager->connection('primary'));
        $archive = $manager->connection('archive');
        $manager->purge();
        self::assertNotSame($archive, $manager->connection('archive'));
    }

    /** 顶层默认连接必须指向已定义的命名连接。 */
    public function testUnknownDefaultConnectionIsRejectedDuringConstruction(): void
    {
        $this->expectException(ConfigurationException::class);
        new Manager(new ClientFactory($this->container()), [
            'default' => 'missing',
            'connections' => ['primary' => ['hosts' => ['https://primary.example.test']]],
        ]);
    }

    /** 返回不访问网络的最小 PSR 容器。 */
    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            /** 测试不解析任何服务。 */
            public function get(string $id): mixed
            {
                throw new \RuntimeException($id);
            }

            /** 测试容器不声明服务。 */
            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
