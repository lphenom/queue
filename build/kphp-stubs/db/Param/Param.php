<?php

declare(strict_types=1);

namespace LPhenom\Db\Param;

/**
 * KPHP-compatible stub for lphenom/db Param.
 *
 * Replaces the vendor version which uses:
 *   - Constructor property promotion (not KPHP-compatible)
 *   - readonly properties (not KPHP-compatible)
 *   - int|string|bool|float|null 5-member union (problematic in KPHP)
 *
 * This stub uses:
 *   - Explicit property declarations
 *   - mixed $value (KPHP supports mixed)
 *   - No readonly
 *
 * @lphenom-build kphp
 */
final class Param
{
    /** @var mixed */
    public mixed $value;

    /** @var int PDO::PARAM_* constant */
    public int $type;

    public function __construct(mixed $value, int $type)
    {
        $this->value = $value;
        $this->type  = $type;
    }
}

