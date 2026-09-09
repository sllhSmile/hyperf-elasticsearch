<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Adapter;

/** Elasticsearch PHP Client 8 adapter. */
final class Elastic8Adapter extends OfficialClientAdapter
{
    public function __construct(object $client)
    {
        parent::__construct($client, ClientMajor::ES8);
    }
}
