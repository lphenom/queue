<?php

declare(strict_types=1);

/**
 * KPHP entrypoint for lphenom/queue.
 *
 * KPHP does not support Composer PSR-4 autoloading.
 * All source files must be explicitly required in dependency order:
 *   External interfaces → Queue exceptions → Queue DTO → Queue interfaces → Drivers
 *
 * Dependency versions required: lphenom/db ^0.3, lphenom/redis ^0.3
 * Both are KPHP-compatible as of v0.3 — no stubs needed.
 *
 * Compatible with PHP 8.1+ and KPHP.
 *
 * @lphenom-build kphp
 */

// ── lphenom/db v0.3: contracts and value objects (KPHP-compatible) ────────
// ResultInterface has no dependencies — include first
require_once __DIR__ . '/../vendor/lphenom/db/src/Contract/ResultInterface.php';
// ConnectionInterface depends on ResultInterface and TransactionCallbackInterface
require_once __DIR__ . '/../vendor/lphenom/db/src/Contract/ConnectionInterface.php';
// TransactionCallbackInterface references ConnectionInterface
require_once __DIR__ . '/../vendor/lphenom/db/src/Contract/TransactionCallbackInterface.php';
// Param — KPHP-compatible since v0.3 (string $value, explicit fields, no union types)
require_once __DIR__ . '/../vendor/lphenom/db/src/Param/Param.php';
// ParamBinder — factory for Param instances
require_once __DIR__ . '/../vendor/lphenom/db/src/Param/ParamBinder.php';

// ── lphenom/redis v0.3: pipeline + client (KPHP-compatible) ──────────────
require_once __DIR__ . '/../vendor/lphenom/redis/src/Pipeline/RedisPipelineDriverInterface.php';
require_once __DIR__ . '/../vendor/lphenom/redis/src/Pipeline/RedisPipeline.php';
require_once __DIR__ . '/../vendor/lphenom/redis/src/Client/RedisClientInterface.php';

// ── lphenom/queue: exception ──────────────────────────────────────────────
require_once __DIR__ . '/../src/Exception/QueueException.php';

// ── lphenom/queue: core ───────────────────────────────────────────────────
require_once __DIR__ . '/../src/Job.php';
require_once __DIR__ . '/../src/QueueInterface.php';

// ── lphenom/queue: retry policy ───────────────────────────────────────────
require_once __DIR__ . '/../src/Retry/RetryPolicy.php';

// ── lphenom/queue: drivers ────────────────────────────────────────────────
require_once __DIR__ . '/../src/Driver/Schema/DbSchema.php';
require_once __DIR__ . '/../src/Driver/DbQueue.php';
require_once __DIR__ . '/../src/Driver/RedisQueue.php';

// ── Smoke test ────────────────────────────────────────────────────────────

// Verify Job construction and getters
$job = new \LPhenom\Queue\Job('test-id', 'send_email', '{"to":"x@y.com"}', 0, 1700000000, null);
echo 'job.id: ' . $job->getId() . PHP_EOL;
echo 'job.name: ' . $job->getName() . PHP_EOL;
echo 'job.attempts: ' . $job->getAttempts() . PHP_EOL;

// Verify immutable with* methods
$job2 = $job->withAttempts(2);
echo 'job2.attempts: ' . $job2->getAttempts() . PHP_EOL;
echo 'job.attempts (unchanged): ' . $job->getAttempts() . PHP_EOL;

// Verify RetryPolicy
$policy = new \LPhenom\Queue\Retry\RetryPolicy(3, 1);

$shouldRetry = $policy->shouldRetry($job);
if ($shouldRetry) {
    echo 'retry.shouldRetry: true' . PHP_EOL;
} else {
    echo 'retry.shouldRetry: false' . PHP_EOL;
}

$delay0 = $policy->getNextDelaySeconds($job);
echo 'retry.delay[0]: ' . $delay0 . PHP_EOL;

$delay2 = $policy->getNextDelaySeconds($job2);
echo 'retry.delay[2]: ' . $delay2 . PHP_EOL;

// Verify DbSchema generates non-empty DDL
$createSql = \LPhenom\Queue\Driver\Schema\DbSchema::createTable('jobs');
echo 'schema.length: ' . strlen($createSql) . PHP_EOL;

$dropSql = \LPhenom\Queue\Driver\Schema\DbSchema::dropTable('jobs');
echo 'drop.length: ' . strlen($dropSql) . PHP_EOL;

// Verify Job::create() factory
$createdJob = \LPhenom\Queue\Job::create('noop', '{}', 1700000000);
echo 'created.id.length: ' . strlen($createdJob->getId()) . PHP_EOL;

echo '=== kphp-entrypoint: OK ===' . PHP_EOL;

