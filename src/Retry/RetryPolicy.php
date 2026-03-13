<?php

declare(strict_types=1);

namespace LPhenom\Queue\Retry;

use LPhenom\Queue\Job;

/**
 * Retry policy with exponential backoff.
 *
 * Delay formula: baseDelaySeconds * 2^(attempts)
 *   attempts=0 → baseDelaySeconds
 *   attempts=1 → 2 * baseDelaySeconds
 *   attempts=2 → 4 * baseDelaySeconds
 *
 * KPHP-compatible:
 *   - No pow() (returns float) — uses integer multiplication loop
 *   - No union types
 *   - Explicit property declarations
 *
 * @lphenom-build shared,kphp
 */
final class RetryPolicy
{
    /** @var int */
    private int $maxAttempts;

    /** @var int */
    private int $baseDelaySeconds;

    public function __construct(int $maxAttempts = 3, int $baseDelaySeconds = 1)
    {
        $this->maxAttempts      = $maxAttempts;
        $this->baseDelaySeconds = $baseDelaySeconds;
    }

    /**
     * Check whether the job should be retried based on its current attempt count.
     */
    public function shouldRetry(Job $job): bool
    {
        return $job->getAttempts() < $this->maxAttempts;
    }

    /**
     * Calculate the next retry delay using exponential backoff.
     *
     * Uses an integer multiplication loop instead of pow() to stay KPHP-compatible
     * (pow() returns float which may cause type inference issues in KPHP).
     */
    public function getNextDelaySeconds(Job $job): int
    {
        $attempts = $job->getAttempts();
        $delay    = $this->baseDelaySeconds;
        $i        = 0;

        while ($i < $attempts) {
            $delay = $delay * 2;
            $i++;
        }

        return $delay;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getBaseDelaySeconds(): int
    {
        return $this->baseDelaySeconds;
    }
}
