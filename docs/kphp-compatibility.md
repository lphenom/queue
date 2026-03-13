# KPHP Compatibility — lphenom/queue

This document describes all KPHP constraints and solutions applied in the `lphenom/queue` package.

For the full LPhenom KPHP compatibility guide, see [lphenom/core kphp-compatibility.md](https://github.com/lphenom/core/blob/main/docs/kphp-compatibility.md).

---

## How KPHP Compilation Works

KPHP (`vkcom/kphp`) compiles PHP source to a static C++ binary. At compile time:
- KPHP has its own PHP parser — it does **not** use the PHP runtime
- All types must be statically resolvable (no `mixed` in method calls without `instance_cast`)
- The compiled binary runs **without PHP** installed

---

## Rules Applied in This Package

### ❌ No `str_starts_with()` / `str_ends_with()` / `str_contains()`

Not supported in KPHP. Use `substr()` / `strpos()`:

```php
// ❌ KPHP rejects
if (str_starts_with($key, 'queue:')) { ... }

// ✅ KPHP-safe
if (substr($key, 0, 6) === 'queue:') { ... }
```

### ❌ No `JSON_THROW_ON_ERROR`

Not supported. Check return value explicitly:

```php
// ❌ KPHP rejects
$data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

// ✅ KPHP-safe
$data = json_decode($value, true);
if (!is_array($data)) {
    throw new QueueException('Invalid JSON');
}
```

### ❌ No `json_encode(array<string, mixed>)` — uses string concatenation

KPHP's type inference can struggle with `array<string, mixed>` in `json_encode()`.
`RedisQueue::serialize()` builds JSON via string concatenation to avoid this:

```php
// ❌ Potential KPHP type inference issue
$json = json_encode(['id' => $id, 'attempts' => $attempts]);

// ✅ KPHP-safe: unambiguous types per field
$json = '{"id":' . json_encode($id)
    . ',"attempts":' . $attempts
    . '}';
```

### ❌ No `pow()` for exponential backoff

`pow()` returns `float`, which causes KPHP type inference issues when used as `int`.
`RetryPolicy` uses an integer multiplication loop:

```php
// ❌ pow() returns float
$delay = (int) pow(2, $attempts) * $base;

// ✅ Pure integer arithmetic
$delay = $base;
$i     = 0;
while ($i < $attempts) {
    $delay = $delay * 2;
    $i++;
}
```

### ❌ No `readonly` properties

```php
// ❌ KPHP rejects
final class Job {
    public function __construct(
        private readonly string $id
    ) {}
}

// ✅ Explicit declaration + assignment
final class Job {
    private string $id;

    public function __construct(string $id) {
        $this->id = $id;
    }
}
```

### ❌ No constructor property promotion

See above. All properties declared explicitly in class body and assigned in constructor body.

### ❌ No `match` expressions

```php
// ❌ KPHP type inference issues with match
$status = match($attempts) { 0 => 'new', default => 'retry' };

// ✅ if/elseif
if ($attempts === 0) {
    $status = 'new';
} else {
    $status = 'retry';
}
```

### ❌ No trailing commas in function call arguments

```php
// ❌ KPHP rejects trailing comma in calls
foo($arg1, $arg2,);

// ✅
foo($arg1, $arg2);
```

### ❌ No `__destruct()`

KPHP does not support `__destruct()`. Not used in this package.

### ❌ No union types `int|string|bool|float|null`

Complex union types (4+ members) cause KPHP type inference issues:

```php
// ❌ KPHP struggles with complex union return type
public function getValue(): int|string|bool|float|null {}

// ✅ Use mixed or split into specific methods
/** @return mixed */
public function getValue(): mixed {}
```

### ✅ `?int` nullable — supported

Single nullable types (`?int`, `?string`, `?MyClass`) are fully supported.

### ✅ `?? null` pattern for array access

KPHP does not narrow types after `!isset() + throw`. Use `?? null` + explicit check:

```php
// ❌ KPHP doesn't narrow type after !isset
if (!isset($row['id'])) { throw new Exception(); }
$id = $row['id']; // KPHP: type still includes null

// ✅ KPHP understands this pattern
$idRaw = $row['id'] ?? null;
if ($idRaw === null) { throw new Exception(); }
$id = (string) $idRaw; // clearly non-null
```

---

## KPHP Entrypoint

KPHP does not support Composer autoloading. All files must be included explicitly:

```php
// build/kphp-entrypoint.php
require_once __DIR__ . '/../vendor/lphenom/db/src/Contract/ResultInterface.php';
require_once __DIR__ . '/../vendor/lphenom/db/src/Contract/ConnectionInterface.php';
require_once __DIR__ . '/../vendor/lphenom/db/src/Contract/TransactionCallbackInterface.php';
require_once __DIR__ . '/../vendor/lphenom/db/src/Param/Param.php';
require_once __DIR__ . '/../vendor/lphenom/redis/src/Pipeline/RedisPipelineDriverInterface.php';
require_once __DIR__ . '/../vendor/lphenom/redis/src/Pipeline/RedisPipeline.php';
require_once __DIR__ . '/../vendor/lphenom/redis/src/Client/RedisClientInterface.php';
require_once __DIR__ . '/../src/Exception/QueueException.php';
require_once __DIR__ . '/../src/Job.php';
require_once __DIR__ . '/../src/QueueInterface.php';
require_once __DIR__ . '/../src/Retry/RetryPolicy.php';
require_once __DIR__ . '/../src/Driver/Schema/DbSchema.php';
require_once __DIR__ . '/../src/Driver/DbQueue.php';
require_once __DIR__ . '/../src/Driver/RedisQueue.php';
```

**Order matters:** interfaces and exceptions before the classes that use them.

---

## Verifying Compilation

```bash
# Build KPHP binary + PHAR (both must succeed)
make kphp-check
# or
docker build -f Dockerfile.check -t lphenom-queue-check .
```

---

## Links

- [KPHP vs PHP differences](https://vkcom.github.io/kphp/kphp-language/kphp-vs-php/whats-the-difference.html)
- [vkcom/kphp Docker image](https://hub.docker.com/r/vkcom/kphp)
- [lphenom/core — full KPHP guide](https://github.com/lphenom/core/blob/main/docs/kphp-compatibility.md)

