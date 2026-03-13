<?php

declare(strict_types=1);

namespace LPhenom\Queue\Tests\Unit;

use LPhenom\Queue\Job;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Job DTO.
 */
final class JobTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $job = new Job('uuid-1', 'send_email', '{"to":"a@b.com"}', 2, 1000000, 1000010);

        $this->assertSame('uuid-1', $job->getId());
        $this->assertSame('send_email', $job->getName());
        $this->assertSame('{"to":"a@b.com"}', $job->getPayloadJson());
        $this->assertSame(2, $job->getAttempts());
        $this->assertSame(1000000, $job->getAvailableAt());
        $this->assertSame(1000010, $job->getReservedAt());
    }

    public function testReservedAtCanBeNull(): void
    {
        $job = new Job('uuid-2', 'process', '{}', 0, 1000000, null);

        $this->assertNull($job->getReservedAt());
    }

    public function testCreateFactoryMethod(): void
    {
        $job = Job::create('send_email', '{"to":"x@y.com"}', 1700000000);

        $this->assertNotEmpty($job->getId());
        $this->assertSame('send_email', $job->getName());
        $this->assertSame('{"to":"x@y.com"}', $job->getPayloadJson());
        $this->assertSame(0, $job->getAttempts());
        $this->assertSame(1700000000, $job->getAvailableAt());
        $this->assertNull($job->getReservedAt());
    }

    public function testCreateUsesCurrentTimeWhenAvailableAtIsZero(): void
    {
        $before = (int) time();
        $job    = Job::create('noop', '{}');
        $after  = (int) time();

        $this->assertGreaterThanOrEqual($before, $job->getAvailableAt());
        $this->assertLessThanOrEqual($after, $job->getAvailableAt());
    }

    public function testCreateGeneratesUniqueIds(): void
    {
        $job1 = Job::create('noop', '{}');
        $job2 = Job::create('noop', '{}');

        $this->assertNotSame($job1->getId(), $job2->getId());
    }

    public function testWithAttempts(): void
    {
        $job      = new Job('uuid-3', 'task', '{}', 0, 1000000, null);
        $updated  = $job->withAttempts(3);

        $this->assertSame(0, $job->getAttempts());
        $this->assertSame(3, $updated->getAttempts());
        $this->assertSame('uuid-3', $updated->getId());
    }

    public function testWithAvailableAt(): void
    {
        $job     = new Job('uuid-4', 'task', '{}', 0, 1000000, null);
        $updated = $job->withAvailableAt(9999999);

        $this->assertSame(1000000, $job->getAvailableAt());
        $this->assertSame(9999999, $updated->getAvailableAt());
    }

    public function testWithReservedAt(): void
    {
        $job     = new Job('uuid-5', 'task', '{}', 0, 1000000, null);
        $updated = $job->withReservedAt(2000000);

        $this->assertNull($job->getReservedAt());
        $this->assertSame(2000000, $updated->getReservedAt());
    }

    public function testImmutability(): void
    {
        $original = new Job('uuid-6', 'task', '{"key":"val"}', 1, 1000000, 1000010);
        $modified = $original->withAttempts(5);

        // Original is unchanged
        $this->assertSame(1, $original->getAttempts());
        // New instance has the change
        $this->assertSame(5, $modified->getAttempts());
        // Other fields are preserved
        $this->assertSame('uuid-6', $modified->getId());
        $this->assertSame('{"key":"val"}', $modified->getPayloadJson());
    }
}
