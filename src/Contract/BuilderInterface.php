<?php

declare(strict_types=1);

namespace SllhSmile\Elasticsearch\Contract;

/**
 * 查询构造器契约：把链式调用累积的状态编译为 Elasticsearch DSL。
 */
interface BuilderInterface
{
    /**
     * 编译 DSL；仅处理内存状态，不发起网络请求。
     */
    public function toDsl(): array;
}
