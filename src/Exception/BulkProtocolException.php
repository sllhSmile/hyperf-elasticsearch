<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Exception;

/** Bulk 响应缺少可信的逐项结果时抛出，不能将该块计为成功。 */
final class BulkProtocolException extends BulkExecutionException {}
