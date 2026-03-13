<?php

declare(strict_types=1);

namespace LPhenom\Queue\Driver;

use LPhenom\Db\Contract\ConnectionInterface;
use LPhenom\Db\Param\ParamBinder;
use LPhenom\Queue\Job;
use LPhenom\Queue\QueueInterface;
use LPhenom\Queue\Retry\RetryPolicy;

/**
 * Database-backed queue driver (shared hosting compatible).
 *
 * Jobs are stored in a SQL table (see DbSchema::createTable()).
 * Reservation uses optimistic locking:
 *   1. SELECT the first available job
 *   2. UPDATE reserved_at WHERE id = ? AND reserved_at IS NULL
 *   3. If 0 rows updated — another worker got it first, return null
 *
 * Identical QueueInterface as RedisQueue — swap drivers without changing business logic.
 *
 * KPHP-compatible:
 *   - No reflection, no anonymous functions stored in arrays
 *   - Explicit type coercions from DB rows
 *   - Uses ?? null pattern instead of !isset + throw
 *
 * @lphenom-build shared,kphp
 */
final class DbQueue implements QueueInterface
{
    /** @var ConnectionInterface */
    private ConnectionInterface $connection;

    /** @var string */
    private string $table;

    /** @var RetryPolicy */
    private RetryPolicy $retryPolicy;

    public function __construct(
        ConnectionInterface $connection,
        string $table,
        RetryPolicy $retryPolicy
    ) {
        $this->connection  = $connection;
        $this->table       = $table;
        $this->retryPolicy = $retryPolicy;
    }

    /**
     * Insert a new job into the queue table.
     */
    public function push(Job $job): void
    {
        $this->connection->execute(
            'INSERT INTO `' . $this->table . '`'
            . ' (id, name, payload_json, attempts, available_at, reserved_at)'
            . ' VALUES (:id, :name, :payload_json, :attempts, :available_at, NULL)',
            [
                'id'           => ParamBinder::str($job->getId()),
                'name'         => ParamBinder::str($job->getName()),
                'payload_json' => ParamBinder::str($job->getPayloadJson()),
                'attempts'     => ParamBinder::int($job->getAttempts()),
                'available_at' => ParamBinder::int($job->getAvailableAt()),
            ]
        );
    }

    /**
     * Find and reserve the next available job using optimistic locking.
     *
     * $timeoutSeconds is unused in the DB driver (polling mode).
     * Returns null when no job is currently available.
     */
    public function reserve(int $timeoutSeconds): ?Job
    {
        $now = (int) time();

        $result = $this->connection->query(
            'SELECT id, name, payload_json, attempts, available_at'
            . ' FROM `' . $this->table . '`'
            . ' WHERE reserved_at IS NULL AND available_at <= :now'
            . ' ORDER BY available_at ASC LIMIT 1',
            ['now' => ParamBinder::int($now)]
        );

        $row = $result->fetchOne();
        if ($row === null) {
            return null;
        }

        // KPHP-safe: use ?? null then explicit null check
        $idRaw = $row['id'] ?? null;
        $id    = $idRaw !== null ? (string) $idRaw : '';

        if ($id === '') {
            return null;
        }

        // Optimistic lock: only update if still unreserved
        $affected = $this->connection->execute(
            'UPDATE `' . $this->table . '`'
            . ' SET reserved_at = :reserved'
            . ' WHERE id = :id AND reserved_at IS NULL',
            [
                'reserved' => ParamBinder::int($now),
                'id'       => ParamBinder::str($id),
            ]
        );

        if ($affected === 0) {
            // Another worker reserved it first
            return null;
        }

        return $this->rowToJob($row, $now);
    }

    /**
     * Permanently remove a successfully processed job.
     */
    public function ack(Job $job): void
    {
        $this->connection->execute(
            'DELETE FROM `' . $this->table . '` WHERE id = :id',
            ['id' => ParamBinder::str($job->getId())]
        );
    }

    /**
     * Handle job failure.
     *
     * If retries remain: increment attempts, clear reserved_at, set next available_at.
     * If max attempts exceeded: permanently remove the job.
     */
    public function fail(Job $job, string $reason): void
    {
        $incrementedJob = $job->withAttempts($job->getAttempts() + 1);

        if ($this->retryPolicy->shouldRetry($incrementedJob)) {
            $delay       = $this->retryPolicy->getNextDelaySeconds($incrementedJob);
            $availableAt = (int) time() + $delay;

            $this->connection->execute(
                'UPDATE `' . $this->table . '`'
                . ' SET attempts = :attempts, reserved_at = NULL, available_at = :available_at'
                . ' WHERE id = :id',
                [
                    'attempts'     => ParamBinder::int($incrementedJob->getAttempts()),
                    'available_at' => ParamBinder::int($availableAt),
                    'id'           => ParamBinder::str($job->getId()),
                ]
            );
        } else {
            // Max attempts exceeded — remove from queue permanently
            $this->connection->execute(
                'DELETE FROM `' . $this->table . '` WHERE id = :id',
                ['id' => ParamBinder::str($job->getId())]
            );
        }
    }

    /**
     * Map a DB row to a Job instance.
     *
     * KPHP-safe: all values cast explicitly via ?? null pattern.
     *
     * @param array<string, mixed> $row
     */
    private function rowToJob(array $row, int $reservedAt): Job
    {
        $nameRaw        = $row['name'] ?? null;
        $payloadRaw     = $row['payload_json'] ?? null;
        $attemptsRaw    = $row['attempts'] ?? null;
        $availableAtRaw = $row['available_at'] ?? null;
        $idRaw          = $row['id'] ?? null;

        $id          = $idRaw !== null ? (string) $idRaw : '';
        $name        = $nameRaw !== null ? (string) $nameRaw : '';
        $payloadJson = $payloadRaw !== null ? (string) $payloadRaw : '{}';
        $attempts    = $attemptsRaw !== null ? (int) $attemptsRaw : 0;
        $availableAt = $availableAtRaw !== null ? (int) $availableAtRaw : 0;

        return new Job(
            $id,
            $name,
            $payloadJson,
            $attempts,
            $availableAt,
            $reservedAt
        );
    }
}
