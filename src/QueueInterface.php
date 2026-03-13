<?php

declare(strict_types=1);

namespace LPhenom\Queue;

/**
 * Queue contract.
 *
 * Implemented by DbQueue (shared hosting) and RedisQueue (production).
 * The interface is identical for both drivers — cron-based polling or
 * reactive (blocking) consumption both use the same methods.
 *
 * KPHP-compatible: no callable, no reflection.
 *
 * @lphenom-build shared,kphp
 */
interface QueueInterface
{
    /**
     * Push a job onto the queue.
     */
    public function push(Job $job): void;

    /**
     * Reserve the next available job.
     *
     * Blocks up to $timeoutSeconds for Redis driver.
     * Returns null when no job is available within the timeout.
     */
    public function reserve(int $timeoutSeconds): ?Job;

    /**
     * Acknowledge successful job completion.
     *
     * Permanently removes the job from the queue.
     */
    public function ack(Job $job): void;

    /**
     * Report job failure.
     *
     * Applies the configured RetryPolicy:
     *   - If retries remain: re-queues with incremented attempts and exponential backoff delay.
     *   - If max attempts exceeded: permanently removes the job from the queue.
     */
    public function fail(Job $job, string $reason): void;
}
