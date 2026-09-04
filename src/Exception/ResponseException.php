<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Exception;

/** Elasticsearch 返回非成功状态码或错误响应。 */
class ResponseException extends ElasticsearchException
{
    /** 保存状态码和原始响应，便于业务按错误类型处理。 */
    public function __construct(string $message, private readonly int $statusCode = 0, private readonly mixed $response = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
    }

    /** 返回服务端 HTTP 状态码。 */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** 返回官方客户端解析后的原始错误响应。 */
    public function response(): mixed
    {
        return $this->response;
    }
}
