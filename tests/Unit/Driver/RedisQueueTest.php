<?php

declare(strict_types=1);

namespace LPhenom\Queue\Tests\Unit\Driver;

use LPhenom\Queue\Driver\RedisQueue;
use LPhenom\Queue\Exception\QueueException;
use LPhenom\Queue\Job;
use LPhenom\Queue\Retry\RetryPolicy;
use LPhenom\Redis\Client\RedisClientInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RedisQueue driver.
 *
 * Uses PHPUnit mocks for RedisClientInterface.
 */
final class RedisQueueTest extends TestCase
{
    private RedisClientInterface $client;
    private RedisQueue $queue;

    protected function setUp(): void
    {
        $this->client = $this->createMock(RedisClientInterface::class);
        $this->queue  = new RedisQueue($this->client, 'queue:default', new RetryPolicy(3, 1));
    }

    public function testPushCallsLpush(): void
    {
        $job = new Job('uuid-1', 'send_email', '{"to":"a@b.com"}', 0, 1700000000, null);

        $this->client
            ->expects($this->once())
            ->method('lpush')
            ->with('queue:default', $this->stringContains('"uuid-1"'));

        $this->queue->push($job);
    }

    public function testPushSerializesAllJobFields(): void
    {
        $job = new Job('my-id', 'my-name', '{"k":"v"}', 2, 1700000000, null);

        $captured = '';

        $this->client
            ->expects($this->once())
            ->method('lpush')
            ->willReturnCallback(function (string $key, string $value) use (&$captured): void {
                $captured = $value;
            });

        $this->queue->push($job);

        $this->assertStringContainsString('"my-id"', $captured);
        $this->assertStringContainsString('"my-name"', $captured);
        $this->assertStringContainsString('"attempts":2', $captured);
        $this->assertStringContainsString('"available_at":1700000000', $captured);

        // payload_json is encoded as a JSON string within the outer JSON object
        // so {"k":"v"} becomes "{\"k\":\"v\"}" in the serialized output
        $decoded = json_decode($captured, true);
        $this->assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        $this->assertSame('{"k":"v"}', (string) ($decoded['payload_json'] ?? ''));
    }

    public function testReserveReturnsNullOnTimeout(): void
    {
        $this->client
            ->expects($this->once())
            ->method('blpop')
            ->with('queue:default', 5)
            ->willReturn(null);

        $job = $this->queue->reserve(5);

        $this->assertNull($job);
    }

    public function testReserveDeserializesJob(): void
    {
        $payload = '{"id":"uuid-2","name":"process","payload_json":"{\"x\":1}","attempts":1,"available_at":1700000000}';

        $this->client
            ->expects($this->once())
            ->method('blpop')
            ->willReturn($payload);

        $job = $this->queue->reserve(5);

        $this->assertNotNull($job);
        $this->assertSame('uuid-2', $job->getId());
        $this->assertSame('process', $job->getName());
        $this->assertSame(1, $job->getAttempts());
        $this->assertNotNull($job->getReservedAt());
    }

    public function testReserveThrowsOnInvalidJson(): void
    {
        $this->client
            ->expects($this->once())
            ->method('blpop')
            ->willReturn('not-valid-json');

        $this->expectException(QueueException::class);

        $this->queue->reserve(5);
    }

    public function testAckIsNoOp(): void
    {
        $job = new Job('uuid-3', 'task', '{}', 0, 1700000000, 1700000010);

        // No calls to client expected
        $this->client->expects($this->never())->method('lpush');
        $this->client->expects($this->never())->method('del');

        $this->queue->ack($job);
    }

    public function testFailWithRetriesRemainingRequeuesJob(): void
    {
        // attempts=0, maxAttempts=3 → after incrementing attempts=1 → shouldRetry=true
        $job = new Job('uuid-4', 'task', '{}', 0, 1700000000, 1700000010);

        $this->client
            ->expects($this->once())
            ->method('lpush')
            ->with('queue:default', $this->stringContains('"attempts":1'));

        $this->queue->fail($job, 'error');
    }

    public function testFailWithMaxAttemptsDropsJob(): void
    {
        // attempts=3, maxAttempts=3 → after incrementing attempts=4 → shouldRetry=false
        $job = new Job('uuid-5', 'task', '{}', 3, 1700000000, 1700000010);

        $this->client->expects($this->never())->method('lpush');

        $this->queue->fail($job, 'too many attempts');
    }

    public function testFailUpdatesAvailableAtWithBackoff(): void
    {
        $before = (int) time();
        $job    = new Job('uuid-6', 'task', '{}', 1, 1700000000, 1700000010);

        $capturedPayload = '';
        $this->client
            ->expects($this->once())
            ->method('lpush')
            ->willReturnCallback(function (string $key, string $value) use (&$capturedPayload): void {
                $capturedPayload = $value;
            });

        $this->queue->fail($job, 'retry me');

        // Decode and verify available_at is in the future (at least baseDelay * 2^2 = 4s)
        $data = json_decode($capturedPayload, true);
        $this->assertIsArray($data);

        /** @var array<string, mixed> $data */
        $availableAt = (int) ($data['available_at'] ?? 0);
        $this->assertGreaterThanOrEqual($before + 4, $availableAt);
        $this->assertSame(2, (int) ($data['attempts'] ?? 0));
    }
}
