# lphenom/queue

**LPhenom Queue** — KPHP-compatible queue package for the LPhenom framework.

Provides Job DTO, queue interface, DB queue driver (shared hosting), Redis queue driver (production), and retry policy with exponential backoff.

## Features

- ✅ **DB queue** (`DbQueue`) — works on any shared hosting with MySQL
- ✅ **Redis queue** (`RedisQueue`) — high-performance reactive consumption via BLPOP
- ✅ **Identical interface** — swap drivers without changing business logic
- ✅ **Exponential backoff** retry policy with configurable max attempts
- ✅ **KPHP-compatible** — compiles to a static binary via `vkcom/kphp`
- ✅ **PHP 8.1+** minimum requirement

## Installation

```bash
composer require lphenom/queue
```

## Quick Start

### 1. Create a Job

```php
use LPhenom\Queue\Job;

// Create a job scheduled to run immediately
$job = Job::create('send_email', json_encode(['to' => 'user@example.com', 'subject' => 'Hello']));

// Schedule a job in the future (Unix timestamp)
$delayed = Job::create('generate_report', json_encode(['month' => '2026-03']), time() + 3600);
```

### 2. Configure the Driver

#### DB Queue (Shared Hosting)

```php
use LPhenom\Db\Driver\PdoMySqlConnection;
use LPhenom\Queue\Driver\DbQueue;
use LPhenom\Queue\Driver\Schema\DbSchema;
use LPhenom\Queue\Retry\RetryPolicy;

$connection = new PdoMySqlConnection('mysql:host=localhost;dbname=mydb', 'user', 'pass');

// Create the table once (e.g. in a migration)
$connection->execute(DbSchema::createTable('jobs'));

$queue = new DbQueue(
    $connection,
    'jobs',
    new RetryPolicy(maxAttempts: 3, baseDelaySeconds: 2)
);
```

#### Redis Queue (Production)

```php
use LPhenom\Redis\Client\RespRedisClient;
use LPhenom\Redis\Connection\RedisConnectionConfig;
use LPhenom\Queue\Driver\RedisQueue;
use LPhenom\Queue\Retry\RetryPolicy;

$config = new RedisConnectionConfig(host: 'localhost', port: 6379);
$client = new RespRedisClient($config);

$queue = new RedisQueue(
    $client,
    'queue:default',
    new RetryPolicy(maxAttempts: 5, baseDelaySeconds: 1)
);
```

### 3. Push a Job

```php
$queue->push($job);
```

### 4. Process Jobs

#### Polling (DB / Cron)

```php
while (true) {
    $job = $queue->reserve(0); // non-blocking for DB driver

    if ($job === null) {
        sleep(1); // wait before polling again
        continue;
    }

    try {
        processJob($job);
        $queue->ack($job);
    } catch (\Throwable $e) {
        $queue->fail($job, $e->getMessage());
    }
}
```

#### Reactive (Redis / Long-running Worker)

```php
while (true) {
    // Blocks up to 5 seconds waiting for a job
    $job = $queue->reserve(5);

    if ($job === null) {
        continue; // timeout — loop again
    }

    try {
        processJob($job);
        $queue->ack($job);
    } catch (\Throwable $e) {
        $queue->fail($job, $e->getMessage());
    }
}
```

## QueueInterface

```php
interface QueueInterface
{
    public function push(Job $job): void;
    public function reserve(int $timeoutSeconds): ?Job;
    public function ack(Job $job): void;
    public function fail(Job $job, string $reason): void;
}
```

| Method | Description |
|--------|-------------|
| `push()` | Add a job to the queue |
| `reserve()` | Get the next available job (blocks for Redis, polls for DB) |
| `ack()` | Mark job as successfully completed (removes from queue) |
| `fail()` | Mark job as failed — applies retry policy |

## Job DTO

```php
final class Job
{
    public function getId(): string;
    public function getName(): string;
    public function getPayloadJson(): string;
    public function getAttempts(): int;
    public function getAvailableAt(): int;     // Unix timestamp
    public function getReservedAt(): ?int;     // null if not reserved

    // Immutable modifiers
    public function withAttempts(int $attempts): self;
    public function withAvailableAt(int $availableAt): self;
    public function withReservedAt(?int $reservedAt): self;
}
```

## Retry Policy

```php
use LPhenom\Queue\Retry\RetryPolicy;

$policy = new RetryPolicy(
    maxAttempts: 3,       // default: 3
    baseDelaySeconds: 1   // default: 1
);

// Exponential backoff delays:
// attempt 0 → 1s
// attempt 1 → 2s
// attempt 2 → 4s
// attempt 3 → 8s
```

The `fail()` method in both drivers automatically applies the retry policy:
- If `job.attempts < maxAttempts`: re-queue with incremented attempts and delayed `available_at`
- If `job.attempts >= maxAttempts`: permanently remove from queue

## Database Schema

```php
use LPhenom\Queue\Driver\Schema\DbSchema;

// Get CREATE TABLE SQL
echo DbSchema::createTable('jobs');

// Get DROP TABLE SQL
echo DbSchema::dropTable('jobs');
```

Schema:

```sql
CREATE TABLE IF NOT EXISTS `jobs` (
  `id`           VARCHAR(36)  NOT NULL,
  `name`         VARCHAR(255) NOT NULL,
  `payload_json` TEXT         NOT NULL,
  `attempts`     INT          NOT NULL DEFAULT 0,
  `available_at` INT          NOT NULL,
  `reserved_at`  INT          DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_queue_available` (`available_at`, `reserved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Driver Comparison

| Feature | DbQueue | RedisQueue |
|---------|---------|------------|
| Storage | MySQL table | Redis list |
| Consumption | Polling (cron) | Blocking (BLPOP) |
| Shared hosting | ✅ | ❌ requires Redis |
| High throughput | ❌ | ✅ |
| `reserve()` blocking | ❌ (returns null) | ✅ (blocks up to timeout) |
| Retry backoff | ✅ | ✅ |

## KPHP Compatibility

See [docs/kphp-compatibility.md](kphp-compatibility.md) for full rules.

Key constraints applied in this package:
- No `str_starts_with/ends_with/contains` → `strpos()`/`substr()`
- No `JSON_THROW_ON_ERROR` → explicit `=== false` / `is_array()` checks
- No `readonly` / constructor property promotion
- No `match` expressions
- No trailing commas in function call arguments
- No `__destruct()`
- Integer math instead of `pow()` for exponential backoff

## Development

```bash
make up         # Start MySQL + Redis
make install    # Install composer dependencies
make test       # Run tests
make lint       # Check code style
make analyse    # PHPStan static analysis
make kphp-check # KPHP binary + PHAR verification
```

