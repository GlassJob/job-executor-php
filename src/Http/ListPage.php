<?php
declare(strict_types=1);

namespace JobCenter\Http;

final class ListPage
{
    /**
     * @param list<array<string,mixed>> $list
     */
    public function __construct(
        public readonly array $list,
        public readonly int $total,
    ) {
    }
}
