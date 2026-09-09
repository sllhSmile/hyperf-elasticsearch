<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Adapter;

/** 当前官方客户端/服务端协议能力声明。 */
final class ClientCapabilities
{
    public function __construct(
        public readonly int $clientMajor,
        public readonly bool $pit = true,
        public readonly bool $searchAfter = true,
        public readonly bool $bulk = true,
    ) {
    }
}
