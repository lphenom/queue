<?php

declare(strict_types=1);

namespace LPhenom\Queue\Tests\Unit\Driver;

use LPhenom\Db\Contract\ConnectionInterface;
use LPhenom\Db\Contract\ResultInterface;
use LPhenom\Queue\Driver\DbQueue;
use LPhenom\Queue\Job;
use LPhenom\Queue\Retry\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DbQueue driver.
 *
 * Uses PHPUnit mocks for ConnectionInterface and ResultInterface.
 */
final class DbQueueTest extends TestCase
{
    private ConnectionInterface $connection;
    private DbQueue $queue;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->queue      = new DbQueue($this->connection, 'jobs', new RetryPolicy(3, 1));
    }

    public function testPushExecutesInsertQuery(): void
    {
        $job = new Job('uuid-1', 'send_email', '{"to":"a@b.com"}', 0, 1700000000, null);

        $this->connection
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('INSERT INTO `jobs`'),
                $this->isType('array')
            )
            ->willReturn(1);

        $this->queue->push($job);
    }

    public function testReserveReturnsNullWhenNoJobAvailable(): void
    {
        $result = $this->createMock(ResultInterface::class);
        $result->expects($this->once())
            ->method('fetchOne')
            ->willReturn(null);

        $this->connection
            ->expects($this->once())
            ->method('query')
            ->willReturn($result);

        $job = $this->queue->reserve(5);

        $this->assertNull($job);
    }

    public function testReserveReturnsJobWhenAvailable(): void
    {
        $row = [
            'id'           => 'uuid-2',
            'name'         => 'process_order',
            'payload_json' => '{"order_id":42}',
            'attempts'     => '1',
            'available_at' => '1700000000',
        ];

        $result = $this->createMock(ResultInterface::class);
        $result->expects($this->once())
            ->method('fetchOne')
            ->willReturn($row);

        $this->connection
            ->expects($this->once())
            ->method('query')
            ->willReturn($result);

        $this->connection
            ->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('UPDATE'), $this->isType('array'))
            ->willReturn(1);

        $job = $this->queue->reserve(5);

        $this->assertNotNull($job);
        $this->assertSame('uuid-2', $job->getId());
        $this->assertSame('process_order', $job->getName());
        $this->assertSame('{"order_id":42}', $job->getPayloadJson());
        $this->assertSame(1, $job->getAttempts());
    }

    public function testReserveReturnsNullWhenAnotherWorkerReservedFirst(): void
    {
        $row = [
            'id'           => 'uuid-3',
            'name'         => 'task',
            'payload_json' => '{}',
            'attempts'     => '0',
            'available_at' => '1700000000',
        ];

        $result = $this->createMock(ResultInterface::class);
        $result->expects($this->once())
            ->method('fetchOne')
            ->willReturn($row);

        $this->connection
            ->expects($this->once())
            ->method('query')
            ->willReturn($result);

        // 0 affected rows — another worker got it
        $this->connection
            ->expects($this->once())
            ->method('execute')
            ->willReturn(0);

        $job = $this->queue->reserve(5);

        $this->assertNull($job);
    }

    public function testAckDeletesJob(): void
    {
        $job = new Job('uuid-4', 'task', '{}', 0, 1700000000, 1700000010);

        $this->connection
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('DELETE FROM'),
                $this->isType('array')
            )
            ->willReturn(1);

        $this->queue->ack($job);
    }

    public function testFailWithRetriesRemainingUpdatesJob(): void
    {
        // attempts=0, maxAttempts=3 → after incrementing attempts=1 → shouldRetry=true
        $job = new Job('uuid-5', 'task', '{}', 0, 1700000000, 1700000010);

        $this->connection
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE'),
                $this->isType('array')
            )
            ->willReturn(1);

        $this->queue->fail($job, 'Something went wrong');
    }

    public function testFailWithMaxAttemptsExceededDeletesJob(): void
    {
        // attempts=3 (max=3) → after incrementing attempts=4 → shouldRetry=false
        $job = new Job('uuid-6', 'task', '{}', 3, 1700000000, 1700000010);

        $this->connection
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('DELETE FROM'),
                $this->isType('array')
            )
            ->willReturn(1);

        $this->queue->fail($job, 'Permanent failure');
    }
}
