#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PHAR smoke-test: require the built PHAR and verify autoloading works.
 *
 * Usage: php build/smoke-test-phar.php /path/to/lphenom-queue.phar
 *
 * NOTE: This is a library PHAR — dependencies (lphenom/db, lphenom/redis)
 * are NOT included. The smoke-test only verifies that the queue source files
 * are autoloaded correctly from the PHAR archive.
 */

$pharFile = $argv[1] ?? dirname(__DIR__) . '/lphenom-queue.phar';

if (!file_exists($pharFile)) {
    fwrite(STDERR, 'PHAR not found: ' . $pharFile . PHP_EOL);
    exit(1);
}

require $pharFile;

// Verify Job DTO autoloads correctly from PHAR
$job = new \LPhenom\Queue\Job('smoke-id', 'test_job', '{"key":"value"}', 0, 1700000000, null);
assert($job->getId() === 'smoke-id', 'Job::getId() failed');
assert($job->getName() === 'test_job', 'Job::getName() failed');
assert($job->getAttempts() === 0, 'Job::getAttempts() failed');
assert($job->getReservedAt() === null, 'Job::getReservedAt() failed');
echo 'smoke-test: job ok' . PHP_EOL;

// Verify Job::create() factory
$created = \LPhenom\Queue\Job::create('noop', '{}', 1700000000);
assert(strlen($created->getId()) > 0, 'Job::create() id failed');
assert($created->getAttempts() === 0, 'Job::create() attempts failed');
echo 'smoke-test: job factory ok' . PHP_EOL;

// Verify immutability
$updated = $job->withAttempts(3);
assert($job->getAttempts() === 0, 'Job is not immutable');
assert($updated->getAttempts() === 3, 'withAttempts failed');
echo 'smoke-test: job immutability ok' . PHP_EOL;

// Verify RetryPolicy autoloads and works
$policy = new \LPhenom\Queue\Retry\RetryPolicy(3, 1);
assert($policy->getMaxAttempts() === 3, 'RetryPolicy::getMaxAttempts() failed');
assert($policy->shouldRetry($job) === true, 'RetryPolicy::shouldRetry() failed');
assert($policy->getNextDelaySeconds($job) === 1, 'RetryPolicy::getNextDelaySeconds(0) failed');
echo 'smoke-test: retry policy ok' . PHP_EOL;

// Verify DbSchema autoloads
$createSql = \LPhenom\Queue\Driver\Schema\DbSchema::createTable('jobs');
assert(strlen($createSql) > 10, 'DbSchema::createTable() failed');
assert(strpos($createSql, 'jobs') !== false, 'DbSchema table name missing');
echo 'smoke-test: db schema ok' . PHP_EOL;

// Verify QueueException autoloads
$ex = new \LPhenom\Queue\Exception\QueueException('test error');
assert($ex->getMessage() === 'test error', 'QueueException failed');
echo 'smoke-test: queue exception ok' . PHP_EOL;

echo '=== PHAR smoke-test: OK ===' . PHP_EOL;

