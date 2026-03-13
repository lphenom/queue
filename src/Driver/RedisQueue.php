<?php

declare(strict_types=1);

namespace LPhenom\Queue\Driver;

use LPhenom\Queue\Exception\QueueException;
use LPhenom\Queue\Job;
use LPhenom\Queue\QueueInterface;
use LPhenom\Queue\Retry\RetryPolicy;
use LPhenom\Redis\Client\RedisClientInterface;

/**
 * Redis-backed queue driver (production / high-performance mode).
 *
 * Jobs are stored as JSON strings in a Redis list.
 * Uses BLPOP for reactive (blocking) consumption — no polling needed.
 *
 * Flow:
 *   push()    → LPUSH queue:{name} <serialized_job>
 *   reserve() → BLPOP queue:{name} <timeout>   (blocks until job arrives)
 *   ack()     → no-op (job removed from list atomically by BLPOP)
 *   fail()    → if retries remain: LPUSH with incremented attempts
 *
 * Identical QueueInterface as DbQueue — swap drivers without changing business logic.
 *
 * KPHP-compatible:
 *   - No callable types
 *   - JSON built with string concatenation to avoid array<string, mixed> inference
 *   - json_decode result handled with is_array() guard
 *
 * @lphenom-build shared,kphp
 */
final class RedisQueue implements QueueInterface
{
    /** @var RedisClientInterface */
    private RedisClientInterface $client;

    /** @var string */
    private string $queueKey;

    /** @var RetryPolicy */
    private RetryPolicy $retryPolicy;

    public function __construct(
        RedisClientInterface $client,
        string $queueKey,
        RetryPolicy $retryPolicy
    ) {
        $this->client      = $client;
        $this->queueKey    = $queueKey;
        $this->retryPolicy = $retryPolicy;
    }

    /**
     * Push a job onto the Redis list.
     */
    public function push(Job $job): void
    {
        $this->client->lpush($this->queueKey, $this->serialize($job));
    }

    /**
     * Block and pop the next job from the Redis list.
     *
     * Returns null when no job arrives within $timeoutSeconds.
     * Uses BLPOP for O(1) reactive consumption — no polling.
     */
    public function reserve(int $timeoutSeconds): ?Job
    {
        $value = $this->client->blpop($this->queueKey, $timeoutSeconds);
        if ($value === null) {
            return null;
        }

        return $this->deserialize($value);
    }

    /**
     * No-op for Redis driver: BLPOP already removed the job atomically.
     */
    public function ack(Job $job): void
    {
        // Job was removed from the list atomically during reserve() via BLPOP.
        // Nothing to do.
    }

    /**
     * Handle job failure with retry logic.
     *
     * If retries remain: push with incremented attempts (next available_at ignored
     * in Redis driver — job is immediately re-queued; for delayed retry use a
     * sorted set scheduler in a future version).
     * If max attempts exceeded: job is permanently dropped.
     */
    public function fail(Job $job, string $reason): void
    {
        $incrementedJob = $job->withAttempts($job->getAttempts() + 1);

        if ($this->retryPolicy->shouldRetry($incrementedJob)) {
            $delay          = $this->retryPolicy->getNextDelaySeconds($incrementedJob);
            $availableAt    = (int) time() + $delay;
            $rescheduledJob = $incrementedJob->withAvailableAt($availableAt);

            $this->client->lpush($this->queueKey, $this->serialize($rescheduledJob));
        }
        // If max attempts exceeded: drop the job permanently
    }

    /**
     * Serialize a Job to a JSON string for Redis storage.
     *
     * Uses string concatenation instead of json_encode(array) to avoid
     * KPHP type inference issues with array<string, mixed> encoding.
     *
     * KPHP-safe: json_encode(string) and json_encode(int) are unambiguous.
     */
    private function serialize(Job $job): string
    {
        $idEncoded          = json_encode($job->getId());
        $nameEncoded        = json_encode($job->getName());
        $payloadJsonEncoded = json_encode($job->getPayloadJson());

        if ($idEncoded === false) {
            $idEncoded = '""';
        }
        if ($nameEncoded === false) {
            $nameEncoded = '""';
        }
        if ($payloadJsonEncoded === false) {
            $payloadJsonEncoded = '"{}"';
        }

        return '{'
            . '"id":' . $idEncoded
            . ',"name":' . $nameEncoded
            . ',"payload_json":' . $payloadJsonEncoded
            . ',"attempts":' . $job->getAttempts()
            . ',"available_at":' . $job->getAvailableAt()
            . '}';
    }

    /**
     * Deserialize a JSON string from Redis back into a Job.
     *
     * KPHP-safe: is_array() guard before array access, explicit (string)/(int) casts.
     *
     * @throws QueueException if the JSON is invalid or malformed
     */
    private function deserialize(string $value): Job
    {
        $data = json_decode($value, true);

        if (!is_array($data)) {
            throw new QueueException('Failed to deserialize job: invalid JSON payload');
        }

        /** @var array<string, mixed> $data */
        $idRaw          = $data['id'] ?? null;
        $nameRaw        = $data['name'] ?? null;
        $payloadRaw     = $data['payload_json'] ?? null;
        $attemptsRaw    = $data['attempts'] ?? null;
        $availableAtRaw = $data['available_at'] ?? null;

        $id          = $idRaw !== null ? (string) $idRaw : '';
        $name        = $nameRaw !== null ? (string) $nameRaw : '';
        $payloadJson = $payloadRaw !== null ? (string) $payloadRaw : '{}';
        $attempts    = $attemptsRaw !== null ? (int) $attemptsRaw : 0;
        $availableAt = $availableAtRaw !== null ? (int) $availableAtRaw : 0;
        $now         = (int) time();

        return new Job(
            $id,
            $name,
            $payloadJson,
            $attempts,
            $availableAt,
            $now
        );
    }
}
