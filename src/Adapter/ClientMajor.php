<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Adapter;

/** 本包支持的 Elasticsearch 官方 PHP 客户端主版本。 */
final class ClientMajor
{
    public const ES7 = 7;

    public const ES8 = 8;

    public const ES9 = 9;

    /** @var list<int> */
    public const SUPPORTED = [self::ES7, self::ES8, self::ES9];

    private function __construct()
    {
    }
}
