<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Exception;

/** 当前客户端/服务端版本不支持请求的能力。 */
final class UnsupportedCapabilityException extends ElasticsearchException
{
}
