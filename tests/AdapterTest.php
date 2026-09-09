<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Tests;

use PHPUnit\Framework\TestCase;
use SllhSmile\Elasticsearch\Adapter\ClientMajor;
use SllhSmile\Elasticsearch\Adapter\Elastic7Adapter;
use SllhSmile\Elasticsearch\Adapter\Elastic8Adapter;
use SllhSmile\Elasticsearch\Adapter\Elastic9Adapter;
use SllhSmile\Elasticsearch\Exception\ResponseException;

final class AdapterTest extends TestCase
{
    public function testAllSupportedAdapterMajorsExposeCommonCapabilities(): void
    {
        self::assertSame(ClientMajor::ES7, (new Elastic7Adapter(new \stdClass()))->capabilities()->clientMajor);
        self::assertSame(ClientMajor::ES8, (new Elastic8Adapter(new \stdClass()))->capabilities()->clientMajor);
        self::assertSame(ClientMajor::ES9, (new Elastic9Adapter(new \stdClass()))->capabilities()->clientMajor);
    }

    public function testArrayAndObjectResponsesAreNormalizedByTheSameContract(): void
    {
        $adapter = new Elastic8Adapter(new \stdClass());
        self::assertSame(['value' => 1], $adapter->responseToArray(['value' => 1]));
        self::assertSame(['value' => 1], $adapter->responseToArray(new class {
            public function toArray(): array { return ['value' => 1]; }
        }));
    }

    public function testElastic7HttpExceptionsAreNormalizedWhenAvailable(): void
    {
        if (! class_exists('Elasticsearch\\Common\\Exceptions\\Missing404Exception')) {
            self::markTestSkipped('The ES7 exception classes are not installed.');
        }

        $exceptionClass = 'Elasticsearch\\Common\\Exceptions\\Missing404Exception';
        $normalized = (new Elastic7Adapter(new \stdClass()))
            ->normalizeException(new $exceptionClass('missing', 404));

        self::assertInstanceOf(ResponseException::class, $normalized);
        self::assertSame(404, $normalized->statusCode());
    }
}
