# Совместимость с KPHP — lphenom/queue

Этот документ описывает все ограничения и решения, применённые в пакете `lphenom/queue` для совместимости с KPHP.

Полное руководство по совместимости с KPHP для LPhenom: [lphenom/core kphp-compatibility.md](https://github.com/lphenom/core/blob/main/docs/kphp-compatibility.md).

---

## Как работает компиляция KPHP

KPHP (`vkcom/kphp`) компилирует PHP-код в статический C++-бинарь. При компиляции:
- KPHP имеет собственный PHP-парсер — **не использует** PHP runtime
- Все типы должны быть статически выводимы (нельзя вызывать методы на `mixed` без `instance_cast`)
- Скомпилированный бинарь работает **без установленного PHP**

---

## Правила, применённые в этом пакете

### ❌ Нет `str_starts_with()` / `str_ends_with()` / `str_contains()`

Не поддерживается в KPHP. Используйте `substr()` / `strpos()`:

```php
// ❌ KPHP отклоняет
if (str_starts_with($key, 'queue:')) { ... }

// ✅ KPHP-совместимо
if (substr($key, 0, 6) === 'queue:') { ... }
```

### ❌ Нет `JSON_THROW_ON_ERROR`

Не поддерживается. Проверяйте возвращаемое значение явно:

```php
// ❌ KPHP отклоняет
$data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

// ✅ KPHP-совместимо
$data = json_decode($value, true);
if (!is_array($data)) {
    throw new QueueException('Некорректный JSON');
}
```

### ❌ Нет `json_encode(array<string, mixed>)` — используем конкатенацию строк

Вывод типов KPHP может не справиться с `array<string, mixed>` в `json_encode()`.
`RedisQueue::serialize()` строит JSON через конкатенацию строк для избежания этой проблемы:

```php
// ❌ Потенциальная проблема вывода типов KPHP
$json = json_encode(['id' => $id, 'attempts' => $attempts]);

// ✅ KPHP-совместимо: однозначные типы для каждого поля
$json = '{"id":' . json_encode($id)
    . ',"attempts":' . $attempts
    . '}';
```

### ❌ Нет `pow()` для экспоненциального backoff

`pow()` возвращает `float`, что вызывает проблемы вывода типов KPHP при использовании как `int`.
`RetryPolicy` использует целочисленный цикл умножения:

```php
// ❌ pow() возвращает float
$delay = (int) pow(2, $attempts) * $base;

// ✅ Чистая целочисленная арифметика
$delay = $base;
$i     = 0;
while ($i < $attempts) {
    $delay = $delay * 2;
    $i++;
}
```

### ❌ Нет `readonly`-свойств

```php
// ❌ KPHP отклоняет
final class Job {
    public function __construct(
        private readonly string $id
    ) {}
}

// ✅ Явное объявление + присваивание
final class Job {
    private string $id;

    public function __construct(string $id) {
        $this->id = $id;
    }
}
```

### ❌ Нет constructor property promotion

См. выше. Все свойства объявляются явно в теле класса и присваиваются в теле конструктора.

### ❌ Нет `match`-выражений

```php
// ❌ Проблемы вывода типов KPHP с match
$status = match($attempts) { 0 => 'new', default => 'retry' };

// ✅ if/elseif
if ($attempts === 0) {
    $status = 'new';
} else {
    $status = 'retry';
}
```

### ❌ Нет trailing comma в аргументах вызовов функций

```php
// ❌ KPHP отклоняет trailing comma в вызовах
foo($arg1, $arg2,);

// ✅
foo($arg1, $arg2);
```

### ❌ Нет `__destruct()`

KPHP не поддерживает `__destruct()`. В этом пакете не используется.

### ❌ Нет union-типов вида `int|string|bool|float|null`

Сложные union-типы (4+ члена) вызывают проблемы вывода типов KPHP:

```php
// ❌ KPHP не справляется со сложным union return type
public function getValue(): int|string|bool|float|null {}

// ✅ Используйте mixed или разделите на конкретные методы
/** @return mixed */
public function getValue(): mixed {}
```

### ✅ `?int` nullable — поддерживается

Одиночные nullable-типы (`?int`, `?string`, `?MyClass`) полностью поддерживаются.

### ✅ Паттерн `?? null` для доступа к массиву

KPHP не сужает тип после `!isset() + throw`. Используйте `?? null` + явную проверку:

```php
// ❌ KPHP не сужает тип после !isset
if (!isset($row['id'])) { throw new Exception(); }
$id = $row['id']; // KPHP: тип всё ещё включает null

// ✅ KPHP понимает этот паттерн
$idRaw = $row['id'] ?? null;
if ($idRaw === null) { throw new Exception(); }
$id = (string) $idRaw; // явно не null
```

---

## KPHP Entrypoint

KPHP не поддерживает Composer autoloading. Все файлы должны быть подключены явно:

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

**Порядок важен:** интерфейсы и исключения — до классов, которые их используют.

---

## Проверка компиляции

```bash
# Собрать KPHP binary + PHAR (оба должны завершиться успешно)
make kphp-check
# или
docker build -f Dockerfile.check -t lphenom-queue-check .
```

---

## Ссылки

- [KPHP vs PHP differences](https://vkcom.github.io/kphp/kphp-language/kphp-vs-php/whats-the-difference.html)
- [vkcom/kphp Docker image](https://hub.docker.com/r/vkcom/kphp)
- [lphenom/core — полное руководство KPHP](https://github.com/lphenom/core/blob/main/docs/kphp-compatibility.md)
