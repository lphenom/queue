<?php

declare(strict_types=1);

namespace LPhenom\Queue\Tests\Unit;

use LPhenom\Queue\Job;
use LPhenom\Queue\JobHandlerInterface;
use LPhenom\Queue\QueueInterface;
use LPhenom\Queue\Worker;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Worker (consumer loop).
 */
final class WorkerTest extends TestCase
{
    private QueueInterface $queue;
    private Worker $worker;

    protected function setUp(): void
    {
        $this->queue  = $this->createMock(QueueInterface::class);
        $this->worker = new Worker($this->queue);
    }

    // ── runOnce(): no job available ────────────────────────────────────────

    public function testRunOnceReturnsFalseWhenNoJobAvailable(): void
    {
        $this->queue
            ->expects($this->once())
            ->method('reserve')
            ->with(5)
            ->willReturn(null);

        $result = $this->worker->runOnce(5);

        $this->assertFalse($result);
    }

    // ── runOnce(): job handled successfully ───────────────────────────────

    public function testRunOnceCallsHandlerAndAcksOnSuccess(): void
    {
        $job     = new Job('id-1', 'send_email', '{}', 0, 1700000000, null);
        $handler = $this->createMock(JobHandlerInterface::class);

        $this->worker->register('send_email', $handler);

        $this->queue
            ->expects($this->once())
            ->method('reserve')
            ->willReturn($job);

        $handler
            ->expects($this->once())
            ->method('handle')
            ->with($job);

        $this->queue
            ->expects($this->once())
            ->method('ack')
            ->with($job);

        $this->queue
            ->expects($this->never())
            ->method('fail');

        $result = $this->worker->runOnce();

        $this->assertTrue($result);
    }

    // ── runOnce(): handler throws ─────────────────────────────────────────

    public function testRunOnceCallsFailWhenHandlerThrows(): void
    {
        $job     = new Job('id-2', 'send_email', '{}', 0, 1700000000, null);
        $handler = $this->createMock(JobHandlerInterface::class);

        $this->worker->register('send_email', $handler);

        $this->queue
            ->expects($this->once())
            ->method('reserve')
            ->willReturn($job);

        $handler
            ->expects($this->once())
            ->method('handle')
            ->willThrowException(new \RuntimeException('SMTP connection failed'));

        $this->queue
            ->expects($this->never())
            ->method('ack');

        $this->queue
            ->expects($this->once())
            ->method('fail')
            ->with($job, 'SMTP connection failed');

        $result = $this->worker->runOnce();

        $this->assertTrue($result);
    }

    // ── runOnce(): no handler registered ──────────────────────────────────

    public function testRunOnceCallsFailWhenNoHandlerRegistered(): void
    {
        $job = new Job('id-3', 'unknown_job', '{}', 0, 1700000000, null);

        $this->queue
            ->expects($this->once())
            ->method('reserve')
            ->willReturn($job);

        $this->queue
            ->expects($this->never())
            ->method('ack');

        $this->queue
            ->expects($this->once())
            ->method('fail')
            ->with($job, $this->stringContains('No handler registered for job: unknown_job'));

        $result = $this->worker->runOnce();

        $this->assertTrue($result);
    }

    // ── runOnce(): overwrite handler ──────────────────────────────────────

    public function testRegisterOverwritesPreviousHandler(): void
    {
        $job      = new Job('id-4', 'send_email', '{}', 0, 1700000000, null);
        $handler1 = $this->createMock(JobHandlerInterface::class);
        $handler2 = $this->createMock(JobHandlerInterface::class);

        $this->worker->register('send_email', $handler1);
        $this->worker->register('send_email', $handler2);

        $this->queue->method('reserve')->willReturn($job);

        $handler1->expects($this->never())->method('handle');
        $handler2->expects($this->once())->method('handle')->with($job);

        $this->queue->method('ack');

        $this->worker->runOnce();
    }

    // ── runOnce(): multiple handlers ─────────────────────────────────────

    public function testMultipleHandlersDispatchByJobName(): void
    {
        $emailJob  = new Job('id-5', 'send_email', '{}', 0, 1700000000, null);
        $reportJob = new Job('id-6', 'generate_report', '{}', 0, 1700000000, null);

        $emailHandler  = $this->createMock(JobHandlerInterface::class);
        $reportHandler = $this->createMock(JobHandlerInterface::class);

        $this->worker->register('send_email', $emailHandler);
        $this->worker->register('generate_report', $reportHandler);

        // Process email job
        $queue1 = $this->createMock(QueueInterface::class);
        $queue1->method('reserve')->willReturn($emailJob);
        $emailHandler->expects($this->once())->method('handle')->with($emailJob);
        $queue1->expects($this->once())->method('ack');
        $worker1 = new Worker($queue1);
        $worker1->register('send_email', $emailHandler);
        $worker1->register('generate_report', $reportHandler);
        $worker1->runOnce();

        // Process report job
        $queue2 = $this->createMock(QueueInterface::class);
        $queue2->method('reserve')->willReturn($reportJob);
        $reportHandler->expects($this->once())->method('handle')->with($reportJob);
        $queue2->expects($this->once())->method('ack');
        $worker2 = new Worker($queue2);
        $worker2->register('send_email', $emailHandler);
        $worker2->register('generate_report', $reportHandler);
        $worker2->runOnce();
    }

    // ── run(): maxJobs ─────────────────────────────────────────────────────

    public function testRunProcessesExactMaxJobs(): void
    {
        $job     = new Job('id-7', 'noop', '{}', 0, 1700000000, null);
        $handler = $this->createMock(JobHandlerInterface::class);

        $this->worker->register('noop', $handler);

        // reserve() returns a job exactly 3 times, then null
        $this->queue
            ->expects($this->exactly(3))
            ->method('reserve')
            ->willReturn($job);

        $handler->expects($this->exactly(3))->method('handle');
        $this->queue->expects($this->exactly(3))->method('ack');

        $this->worker->run(0, 3);
    }

    public function testRunSkipsNullAndCountsOnlyProcessed(): void
    {
        $job     = new Job('id-8', 'noop', '{}', 0, 1700000000, null);
        $handler = $this->createMock(JobHandlerInterface::class);

        $this->worker->register('noop', $handler);

        // null, job, null, job → 2 processed → stop
        $this->queue
            ->method('reserve')
            ->willReturnOnConsecutiveCalls(null, $job, null, $job);

        $handler->expects($this->exactly(2))->method('handle');

        $this->worker->run(0, 2);
    }
}
