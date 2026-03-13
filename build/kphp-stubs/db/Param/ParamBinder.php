<?php

declare(strict_types=1);

namespace LPhenom\Db\Param;

/**
 * KPHP-compatible stub for lphenom/db ParamBinder.
 *
 * @lphenom-build kphp
 */
final class ParamBinder
{
    private function __construct()
    {
    }

    public static function int(int $value): Param
    {
        return new Param($value, 1);
    }

    public static function str(string $value): Param
    {
        return new Param($value, 2);
    }

    public static function bool(bool $value): Param
    {
        return new Param($value, 5);
    }

    public static function null(): Param
    {
        return new Param(null, 0);
    }

    public static function float(float $value): Param
    {
        return new Param((string) $value, 2);
    }
}

