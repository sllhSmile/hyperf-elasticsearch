<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Adapter;

/** Elasticsearch PHP Client 7 adapter. */
final class Elastic7Adapter extends OfficialClientAdapter
{
    /** 将官方客户端绑定到 ES7 的响应和异常兼容语义。 */
    public function __construct(object $client)
    {
        parent::__construct($client, ClientMajor::ES7);
    }
}
