<?php

declare(strict_types=1);

namespace LPhenom\Db\Contract;

/**
 * KPHP-compatible stub for lphenom/db ConnectionInterface.
 *
 * The installed vendor version uses:
 *   - callable $callback (problematic in KPHP)
 *   - int|string|bool|float|null return type (5-member union)
 *
 * This stub removes transaction() (not used by DbQueue) and uses
 * KPHP-safe signatures for query() and execute().
 *
 * @lphenom-build kphp
 */
interface ConnectionInterface
{
    /**
     * @param array<string, \LPhenom\Db\Param\Param> $params
     */
    public function query(string $sql, array $params = []): ResultInterface;

    /**
     * @param array<string, \LPhenom\Db\Param\Param> $params
     */
    public function execute(string $sql, array $params = []): int;
}

