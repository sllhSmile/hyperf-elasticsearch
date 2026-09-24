<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use Elastic\Elasticsearch\Client;
use Elastic\Transport\Exception\InvalidArgumentException as OfficialInvalidArgumentException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use SllhSmile\Elasticsearch\Adapter\OfficialClientAdapter;
use SllhSmile\Elasticsearch\Exception\ElasticsearchException;
use SllhSmile\Elasticsearch\Exception\TransportException;
use SllhSmile\Elasticsearch\Tests\Support\ClientAdapterStub;

final class AdapterTest extends TestCase
{
    /** SDK 8/9 共用同一个类型明确的 Adapter。 */
    public function testOfficialClientUsesTheSingleAdapter(): void
    {
        $client = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(OfficialClientAdapter::class, new OfficialClientAdapter($client));
    }

    /** 保留数组及带有显式转换方法的响应契约。 */
    public function testArrayAndObjectResponsesAreNormalizedByTheSameContract(): void
    {
        $client = ClientAdapterStub::client(new \stdClass());
        self::assertSame(['value' => 1], $client->responseToArray(['value' => 1]));
        self::assertSame(['value' => 1], $client->responseToArray(new class {
            /** @return array<string, int> */
            public function toArray(): array
            {
                return ['value' => 1];
            }
        }));
    }

    /** 未知响应不得通过 PHP 强制转换伪造正常值。 */
    public function testUnknownResponseTypesAreRejected(): void
    {
        $client = ClientAdapterStub::client(new \stdClass());
        $this->expectException(\UnexpectedValueException::class);
        $client->responseToArray(new \stdClass());
    }

    /** SDK 参数错误是客户端错误，不是可重建的网络故障。 */
    public function testSdkArgumentFailureIsNotClassifiedAsTransportFailure(): void
    {
        $normalized = (new ClientAdapterStub(new \stdClass()))
            ->normalizeException(new OfficialInvalidArgumentException('bad payload'));
        self::assertInstanceOf(ElasticsearchException::class, $normalized);
        self::assertNotInstanceOf(TransportException::class, $normalized);
    }

    /** PSR-18 网络异常使用稳定接口识别，不依赖 Transport 的具体实现类。 */
    public function testPsrNetworkFailureIsClassifiedAsTransportFailure(): void
    {
        $failure = new class (new Request('GET', 'http://127.0.0.1:9200')) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(private readonly RequestInterface $request)
            {
                parent::__construct('network unavailable');
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };

        $normalized = (new ClientAdapterStub(new \stdClass()))->normalizeException($failure);

        self::assertInstanceOf(TransportException::class, $normalized);
        self::assertSame($failure, $normalized->getPrevious());
    }
}
