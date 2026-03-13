<?php

declare(strict_types=1);

namespace LPhenom\Queue\Exception;

use RuntimeException;

/**
 * Base exception for the lphenom/queue package.
 *
 * KPHP-compatible: extends RuntimeException, no reflection.
 *
 * @lphenom-build shared,kphp
 */
final class QueueException extends RuntimeException
{
}
