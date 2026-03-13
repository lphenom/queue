<?php

declare(strict_types=1);

namespace LPhenom\Db\Contract;

/**
 * KPHP-compatible stub for lphenom/db ResultInterface.
 *
 * @lphenom-build kphp
 */
interface ResultInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function fetchOne(): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array;
}

