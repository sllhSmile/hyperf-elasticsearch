<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Adapter;

/** Elasticsearch PHP Client 9 adapter. */
final class Elastic9Adapter extends OfficialClientAdapter
{
    public function __construct(object $client)
    {
        parent::__construct($client, ClientMajor::ES9);
    }
}
