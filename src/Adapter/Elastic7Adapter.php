<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Adapter;

/** Elasticsearch PHP Client 7 adapter. */
final class Elastic7Adapter extends OfficialClientAdapter
{
    public function __construct(object $client)
    {
        parent::__construct($client, ClientMajor::ES7);
    }
}
