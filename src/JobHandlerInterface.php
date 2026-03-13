<?php

declare(strict_types=1);

namespace LPhenom\Queue;

/**
 * Interface for job handlers.
 *
 * Implement this interface for each job type you want to process.
 * Register handlers with the Worker via Worker::register().
 *
 * Example:
 *
 *   final class SendEmailHandler implements JobHandlerInterface
 *   {
 *       public function handle(Job $job): void
 *       {
 *           $payload = json_decode($job->getPayloadJson(), true);
 *           // ... send email ...
 *       }
 *   }
 *
 *   $worker = new Worker($queue);
 *   $worker->register('send_email', new SendEmailHandler());
 *   $worker->run();
 *
 * KPHP-compatible:
 *   - Explicit interface, no callable/Closure types
 *   - Stored as array<string, JobHandlerInterface> in Worker
 *
 * @lphenom-build shared,kphp
 */
interface JobHandlerInterface
{
    /**
     * Handle the given job.
     *
     * Throw any \Throwable to indicate failure — Worker will call fail() and apply retry policy.
     * Return normally to indicate success — Worker will call ack().
     */
    public function handle(Job $job): void;
}
