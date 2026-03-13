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
// JobHandlerInterface depends on Job (parameter type in handle())
require_once __DIR__ . '/../src/JobHandlerInterface.php';

// ── lphenom/queue: retry policy ───────────────────────────────────────────
require_once __DIR__ . '/../src/Retry/RetryPolicy.php';

// ── lphenom/queue: drivers ────────────────────────────────────────────────
require_once __DIR__ . '/../src/Driver/Schema/DbSchema.php';
require_once __DIR__ . '/../src/Driver/DbQueue.php';
require_once __DIR__ . '/../src/Driver/RedisQueue.php';

// ── lphenom/queue: worker (consumer loop) ─────────────────────────────────
// Worker depends on QueueInterface + JobHandlerInterface
require_once __DIR__ . '/../src/Worker.php';

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

// ── Verify Worker + JobHandlerInterface ───────────────────────────────────

// Minimal in-memory queue stub for testing Worker dispatch
final class StubQueue implements \LPhenom\Queue\QueueInterface
{
    /** @var \LPhenom\Queue\Job[] */
    private array $jobs = [];
    /** @var string[] */
    public array $acked = [];
    /** @var string[] */
    public array $failed = [];

    public function push(\LPhenom\Queue\Job $job): void
    {
        $this->jobs[] = $job;
    }

    public function reserve(int $timeoutSeconds): ?\LPhenom\Queue\Job
    {
        if (count($this->jobs) === 0) {
            return null;
        }
        $job = $this->jobs[0];
        array_shift($this->jobs);
        return $job;
    }

    public function ack(\LPhenom\Queue\Job $job): void
    {
        $this->acked[] = $job->getId();
    }

    public function fail(\LPhenom\Queue\Job $job, string $reason): void
    {
        $this->failed[] = $job->getId() . ':' . $reason;
    }
}

// Handler stub
final class StubHandler implements \LPhenom\Queue\JobHandlerInterface
{
    /** @var string[] */
    public array $handled = [];

    public function handle(\LPhenom\Queue\Job $job): void
    {
        $this->handled[] = $job->getId();
    }
}

$stubQueue   = new StubQueue();
$stubHandler = new StubHandler();
$worker      = new \LPhenom\Queue\Worker($stubQueue);
$worker->register('noop', $stubHandler);

// Push one job and process it
$stubQueue->push(\LPhenom\Queue\Job::create('noop', '{}', 1700000000));
$didProcess = $worker->runOnce(0);

if ($didProcess !== true) {
    echo 'worker.runOnce: FAILED (expected true)' . PHP_EOL;
    exit(1);
}
if (count($stubHandler->handled) !== 1) {
    echo 'worker.handler.called: FAILED' . PHP_EOL;
    exit(1);
}
if (count($stubQueue->acked) !== 1) {
    echo 'worker.ack: FAILED' . PHP_EOL;
    exit(1);
}
echo 'worker.dispatch: OK' . PHP_EOL;

// Verify runOnce returns false when queue is empty
$noJob = $worker->runOnce(0);
if ($noJob !== false) {
    echo 'worker.runOnce.empty: FAILED' . PHP_EOL;
    exit(1);
}
echo 'worker.empty: OK' . PHP_EOL;

// Verify unknown job name → fail() is called
$stubQueue2   = new StubQueue();
$worker2      = new \LPhenom\Queue\Worker($stubQueue2);
$stubQueue2->push(\LPhenom\Queue\Job::create('unknown_type', '{}', 1700000000));
$worker2->runOnce(0);
if (count($stubQueue2->failed) !== 1) {
    echo 'worker.nohandler.fail: FAILED' . PHP_EOL;
    exit(1);
}
echo 'worker.nohandler: OK' . PHP_EOL;

echo '=== kphp-entrypoint: OK ===' . PHP_EOL;

