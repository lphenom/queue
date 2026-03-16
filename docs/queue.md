# lphenom/queue

**LPhenom Queue** — KPHP-совместимый пакет очередей для фреймворка LPhenom.

Предоставляет Job DTO, интерфейс очереди, драйвер DB (shared hosting), драйвер Redis (production)
и политику повторных попыток с экспоненциальной задержкой.

## Возможности

- ✅ **DB-очередь** (`DbQueue`) — работает на любом shared hosting с MySQL
- ✅ **Redis-очередь** (`RedisQueue`) — высокопроизводительное реактивное потребление через BLPOP
- ✅ **Единый интерфейс** — смена драйвера без изменения бизнес-логики
- ✅ **Экспоненциальный backoff** — настраиваемое максимальное число попыток
- ✅ **KPHP-совместимость** — компилируется в статический бинарь через `vkcom/kphp`
- ✅ **PHP 8.1+** — минимальная требуемая версия

## Установка

```bash
composer require lphenom/queue
```

## Быстрый старт

### 1. Создание задачи (Job)

```php
use LPhenom\Queue\Job;

// Создать задачу для немедленного выполнения
$job = Job::create('send_email', json_encode(['to' => 'user@example.com', 'subject' => 'Привет']));

// Создать задачу с отсрочкой (Unix timestamp)
$delayed = Job::create('generate_report', json_encode(['month' => '2026-03']), time() + 3600);
```

### 2. Настройка драйвера

#### DB-очередь (Shared Hosting)

```php
use LPhenom\Db\Driver\PdoMySqlConnection;
use LPhenom\Queue\Driver\DbQueue;
use LPhenom\Queue\Driver\Schema\DbSchema;
use LPhenom\Queue\Retry\RetryPolicy;

$connection = new PdoMySqlConnection('mysql:host=localhost;dbname=mydb', 'user', 'pass');

// Создать таблицу один раз (например, в миграции)
$connection->execute(DbSchema::createTable('jobs'));

$queue = new DbQueue(
    $connection,
    'jobs',
    new RetryPolicy(3, 2)
);
```

#### Redis-очередь (Production / KPHP)

```php
use LPhenom\Redis\Client\RespRedisClient;
use LPhenom\Redis\Connection\RedisConnectionConfig;
use LPhenom\Queue\Driver\RedisQueue;
use LPhenom\Queue\Retry\RetryPolicy;

$config = new RedisConnectionConfig('localhost', 6379);
$client = new RespRedisClient($config);

$queue = new RedisQueue(
    $client,
    'queue:default',
    new RetryPolicy(5, 1)
);
```

---

## Паттерн «Продюсер / Консьюмер»

### Продюсер — отправка задачи в очередь

Продюсер создаёт `Job` и вызывает `push()`. Одинаково для DB и Redis:

```php
use LPhenom\Queue\Job;

$job = Job::create('send_email', json_encode(['to' => 'user@example.com']));
$queue->push($job);
```

### Консьюмер — обработка задач через Worker

Консьюмер реализует `JobHandlerInterface` для каждого типа задачи и запускает `Worker`:

```php
use LPhenom\Queue\Job;
use LPhenom\Queue\JobHandlerInterface;
use LPhenom\Queue\Worker;

// 1. Реализовать обработчик для каждого типа джоба
final class SendEmailHandler implements JobHandlerInterface
{
    public function handle(Job $job): void
    {
        $payload = json_decode($job->getPayloadJson(), true);
        $to = is_array($payload) ? (string)($payload['to'] ?? '') : '';
        // ... отправить письмо ...
        echo 'Email отправлен на: ' . $to . PHP_EOL;
    }
}

final class GenerateReportHandler implements JobHandlerInterface
{
    public function handle(Job $job): void
    {
        // ... генерировать отчёт ...
    }
}

// 2. Собрать Worker
$worker = new Worker($queue);
$worker->register('send_email',      new SendEmailHandler());
$worker->register('generate_report', new GenerateReportHandler());

// 3. Запустить цикл консьюмера
$worker->run();  // блокирует навсегда (Redis BLPOP) или поллит (DB)
```

### Схема диспетчеризации

```
Продюсер               Очередь (Redis/DB)         Консьюмер (Worker)
   │                          │                          │
   │  push(Job('send_email')) │                          │
   │─────────────────────────>│                          │
   │                          │                          │
   │                          │   reserve()              │
   │                          │<─────────────────────────│
   │                          │   Job('send_email')      │
   │                          │─────────────────────────>│
   │                          │                          │
   │                          │       handler->handle(job)
   │                          │                          │
   │                          │   ack(job)  [успех]      │
   │                          │<─────────────────────────│
   │                          │          ИЛИ             │
   │                          │   fail(job) [ошибка]     │
   │                          │<─────────────────────────│
   │                          │   (применяется RetryPolicy)│
```

### Режим cron (DbQueue — shared hosting)

`DbQueue::reserve()` не блокирует. Для DB-очереди запускай `run()` из cron:

```php
// worker-cron.php — запускается каждую минуту через cron
$worker = new Worker($queue);
$worker->register('send_email', new SendEmailHandler());

// Обрабатывает не более 10 задач за один запуск, не блокирует
$worker->run(0, 10);
```

```crontab
* * * * * php /app/worker-cron.php
```

### Режим демона (RedisQueue — KPHP / production)

`RedisQueue::reserve()` использует **BLPOP** — блокируется до появления задачи.
Запускается как долгоживущий процесс:

```php
// worker.php — запускается как systemd/supervisor сервис
$worker = new Worker($queue);
$worker->register('send_email',      new SendEmailHandler());
$worker->register('generate_report', new GenerateReportHandler());

// Блокирует и обрабатывает задачи реактивно (без поллинга)
// Перезапускается supervisor-ом после каждых 1000 задач
$worker->run(5, 1000);
```

### Что происходит при ошибке в обработчике

Если `handle()` выбросит исключение:
1. `Worker` перехватывает его
2. Вызывается `$queue->fail($job, $exception->getMessage())`
3. Драйвер применяет `RetryPolicy`:
   - если `attempts < maxAttempts` → джоб возвращается в очередь с задержкой (экспоненциальный backoff)
   - если `attempts >= maxAttempts` → джоб удаляется навсегда

Если для `job.name` не зарегистрирован обработчик → `fail()` вызывается сразу (без повторов).

---

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

| Метод | Описание |
|-------|----------|
| `push()` | Добавить задачу в очередь |
| `reserve()` | Получить следующую доступную задачу (блокирует для Redis, поллит для DB) |
| `ack()` | Отметить задачу как успешно выполненную (удаляет из очереди) |
| `fail()` | Отметить задачу как проваленную — применяет политику повторов |

## Job DTO

```php
final class Job
{
    public function getId(): string;
    public function getName(): string;
    public function getPayloadJson(): string;
    public function getAttempts(): int;
    public function getAvailableAt(): int;     // Unix timestamp
    public function getReservedAt(): ?int;     // null — если не зарезервирован

    // Иммутабельные модификаторы
    public function withAttempts(int $attempts): self;
    public function withAvailableAt(int $availableAt): self;
    public function withReservedAt(?int $reservedAt): self;
}
```

## Политика повторных попыток (RetryPolicy)

```php
use LPhenom\Queue\Retry\RetryPolicy;

$policy = new RetryPolicy(
    3,  // maxAttempts: максимальное число попыток (по умолчанию: 3)
    1   // baseDelaySeconds: базовая задержка в секундах (по умолчанию: 1)
);

// Задержки экспоненциального backoff:
// попытка 0 → 1с
// попытка 1 → 2с
// попытка 2 → 4с
// попытка 3 → 8с
```

Метод `fail()` в обоих драйверах автоматически применяет политику повторов:
- если `job.attempts < maxAttempts`: повторно ставит в очередь с увеличенным счётчиком и задержкой `available_at`
- если `job.attempts >= maxAttempts`: окончательно удаляет задачу из очереди

## Схема базы данных

```php
use LPhenom\Queue\Driver\Schema\DbSchema;

// Получить SQL CREATE TABLE
echo DbSchema::createTable('jobs');

// Получить SQL DROP TABLE
echo DbSchema::dropTable('jobs');
```

Схема:

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

## Сравнение драйверов

| Характеристика | DbQueue | RedisQueue |
|----------------|---------|------------|
| Хранилище | Таблица MySQL | Список Redis |
| Потребление | Поллинг (cron) | Блокирующий (BLPOP) |
| Shared hosting | ✅ | ❌ требует Redis |
| Высокая нагрузка | ❌ | ✅ |
| Блокирующий `reserve()` | ❌ (возвращает null) | ✅ (блокирует до timeout) |
| Retry backoff | ✅ | ✅ |

## Совместимость с KPHP

См. [docs/kphp-compatibility.md](kphp-compatibility.md) для полного описания правил.

Ключевые ограничения этого пакета:
- Нет `str_starts_with/ends_with/contains` → используем `strpos()`/`substr()`
- Нет `JSON_THROW_ON_ERROR` → явные проверки `=== false` / `is_array()`
- Нет `readonly` / constructor property promotion
- Нет `match`-выражений
- Нет trailing comma в аргументах вызовов функций
- Нет `__destruct()`
- Целочисленная математика вместо `pow()` для экспоненциального backoff

## Разработка

```bash
make up         # Запустить MySQL + Redis
make install    # Установить зависимости composer
make test       # Запустить тесты
make lint       # Проверить стиль кода
make analyse    # Статический анализ PHPStan
make kphp-check # Проверка KPHP binary + PHAR
```
