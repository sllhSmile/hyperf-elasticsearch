<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Response;

/** 描述 Elasticsearch 返回的总命中数是精确值还是下界。 */
enum TotalHitsRelation: string
{
    /** value 等于精确命中总数。 */
    case Eq = 'eq';

    /** 真实命中总数大于或等于 value。 */
    case Gte = 'gte';
}
