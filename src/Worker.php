<?php

declare(strict_types=1);

namespace LPhenom\Queue;

/**
 * Worker — consumer loop that dispatches jobs to registered handlers.
 *
 * ## Producer side (push a job)
 *
 *   $job = Job::create('send_email', json_encode(['to' => 'user@example.com']));
 *   $queue->push($job);
 *
 * ## Consumer side (run a worker)
 *
 *   $worker = new Worker($queue);
 *   $worker->register('send_email',    new SendEmailHandler());
 *   $worker->register('process_order', new ProcessOrderHandler());
 *   $worker->run();   // blocks forever (use in KPHP binary or PHP-CLI daemon)
 *
 * ## Cron mode (DB queue, run once per cron tick)
 *
 *   $worker = new Worker($queue);
 *   $worker->register('send_email', new SendEmailHandler());
 *   $worker->runOnce(0);  // non-blocking: process one job if available, then exit
 *
 * ## Dispatch flow
 *
 *   reserve() → find handler by job name → handler->handle($job)
 *     success → ack()
 *     exception → fail()  (RetryPolicy applied inside the driver)
 *     no handler → fail() permanently
 *
 * KPHP-compatible:
 *   - Handlers stored as array<string, JobHandlerInterface> (not callable/Closure)
 *   - try/catch calls fail() directly from catch — no null|string variable across boundary
 *     (KPHP cannot infer union types set in catch branches vs. outer scope)
 *   - No reflection, no dynamic dispatch
 *   - Explicit null checks via ?? null pattern
 *
 * @lphenom-build shared,kphp
 */
final class Worker
{
    /** @var QueueInterface */
    private QueueInterface $queue;

    /**
     * Registered handlers indexed by job name.
     *
     * @var array<string, JobHandlerInterface>
     */
    private array $handlers;

    public function __construct(QueueInterface $queue)
    {
        $this->queue    = $queue;
        $this->handlers = [];
    }

    /**
     * Register a handler for the given job name.
     *
     * Multiple calls with the same name overwrite the previous handler.
     */
    public function register(string $jobName, JobHandlerInterface $handler): void
    {
        $this->handlers[$jobName] = $handler;
    }

    /**
     * Run the consumer loop.
     *
     * Blocks indefinitely (or until $maxJobs processed).
     * Use $maxJobs > 0 to process a fixed number of jobs and exit — useful for
     * memory-bounded workers (restart after N jobs) or testing.
     *
     * @param int $timeoutSeconds Passed to reserve() — how long to block per iteration
     *                            (DB driver ignores this; Redis BLPOP uses it)
     * @param int $maxJobs        Stop after processing this many jobs (0 = unlimited)
     */
    public function run(int $timeoutSeconds = 5, int $maxJobs = 0): void
    {
        $processed = 0;

        while (true) {
            $didProcess = $this->runOnce($timeoutSeconds);

            if ($didProcess) {
                $processed++;
            }

            if ($maxJobs > 0 && $processed >= $maxJobs) {
                break;
            }
        }
    }

    /**
     * Try to process one job.
     *
     * Returns true if a job was dequeued (and handled or failed).
     * Returns false if no job was available within the timeout.
     *
     * This is the building block for cron-based workers:
     *
     *   // cron.php — runs every minute
     *   $worker->runOnce(0);
     */
    public function runOnce(int $timeoutSeconds = 5): bool
    {
        $job = $this->queue->reserve($timeoutSeconds);

        if ($job === null) {
            return false;
        }

        $jobName = $job->getName();
        $handler = $this->handlers[$jobName] ?? null;

        if ($handler === null) {
            // No handler registered for this job type — fail immediately (not re-queued)
            $this->queue->fail($job, 'No handler registered for job: ' . $jobName);
            return true;
        }

        // KPHP-safe: no variable holds a null|string union across a try/catch boundary.
        // On exception → fail() and return immediately from inside catch.
        // On success → ack() is called after the try/catch (unreachable after catch).
        try {
            $handler->handle($job);
        } catch (\Exception $e) {
            $this->queue->fail($job, $e->getMessage());
            return true;
        }

        $this->queue->ack($job);
        return true;
    }
}
