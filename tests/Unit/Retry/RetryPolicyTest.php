<?php

declare(strict_types=1);

namespace LPhenom\Queue\Tests\Unit\Retry;

use LPhenom\Queue\Job;
use LPhenom\Queue\Retry\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RetryPolicy.
 */
final class RetryPolicyTest extends TestCase
{
    public function testShouldRetryReturnsTrueWhenAttemptsLessThanMax(): void
    {
        $policy = new RetryPolicy(3, 1);
        $job    = new Job('id', 'task', '{}', 0, 1000000, null);

        $this->assertTrue($policy->shouldRetry($job));
    }

    public function testShouldRetryReturnsTrueWhenAttemptsIsMax(): void
    {
        $policy = new RetryPolicy(3, 1);
        $job    = new Job('id', 'task', '{}', 2, 1000000, null);

        // 2 < 3 → still should retry
        $this->assertTrue($policy->shouldRetry($job));
    }

    public function testShouldRetryReturnsFalseWhenAttemptsEqualsMax(): void
    {
        $policy = new RetryPolicy(3, 1);
        $job    = new Job('id', 'task', '{}', 3, 1000000, null);

        // 3 < 3 = false
        $this->assertFalse($policy->shouldRetry($job));
    }

    public function testShouldRetryReturnsFalseWhenAttemptsExceedsMax(): void
    {
        $policy = new RetryPolicy(3, 1);
        $job    = new Job('id', 'task', '{}', 10, 1000000, null);

        $this->assertFalse($policy->shouldRetry($job));
    }

    public function testGetNextDelaySecondsAttempts0(): void
    {
        $policy = new RetryPolicy(5, 2);
        $job    = new Job('id', 'task', '{}', 0, 1000000, null);

        // 2 * 2^0 = 2
        $this->assertSame(2, $policy->getNextDelaySeconds($job));
    }

    public function testGetNextDelaySecondsAttempts1(): void
    {
        $policy = new RetryPolicy(5, 2);
        $job    = new Job('id', 'task', '{}', 1, 1000000, null);

        // 2 * 2^1 = 4
        $this->assertSame(4, $policy->getNextDelaySeconds($job));
    }

    public function testGetNextDelaySecondsAttempts2(): void
    {
        $policy = new RetryPolicy(5, 2);
        $job    = new Job('id', 'task', '{}', 2, 1000000, null);

        // 2 * 2^2 = 8
        $this->assertSame(8, $policy->getNextDelaySeconds($job));
    }

    public function testGetNextDelaySecondsAttempts3(): void
    {
        $policy = new RetryPolicy(5, 1);
        $job    = new Job('id', 'task', '{}', 3, 1000000, null);

        // 1 * 2^3 = 8
        $this->assertSame(8, $policy->getNextDelaySeconds($job));
    }

    public function testExponentialGrowth(): void
    {
        $policy  = new RetryPolicy(10, 1);
        $delays  = [];
        $i       = 0;

        while ($i < 5) {
            $job      = new Job('id', 'task', '{}', $i, 1000000, null);
            $delays[] = $policy->getNextDelaySeconds($job);
            $i++;
        }

        // 1, 2, 4, 8, 16
        $this->assertSame([1, 2, 4, 8, 16], $delays);
    }

    public function testGetMaxAttempts(): void
    {
        $policy = new RetryPolicy(5, 10);

        $this->assertSame(5, $policy->getMaxAttempts());
    }

    public function testGetBaseDelaySeconds(): void
    {
        $policy = new RetryPolicy(3, 10);

        $this->assertSame(10, $policy->getBaseDelaySeconds());
    }

    public function testDefaultValues(): void
    {
        $policy = new RetryPolicy();

        $this->assertSame(3, $policy->getMaxAttempts());
        $this->assertSame(1, $policy->getBaseDelaySeconds());
    }
}
