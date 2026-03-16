# lphenom/queue

**LPhenom Queue** — KPHP-совместимый пакет очередей для фреймворка LPhenom.

Предоставляет единый интерфейс `QueueInterface` с драйверами для БД (shared hosting) и Redis (production),
политику повторных попыток с экспоненциальной задержкой и полную поддержку KPHP.

## Документация

- [docs/queue.md](docs/queue.md) — Руководство по использованию
- [docs/kphp-compatibility.md](docs/kphp-compatibility.md) — Ограничения KPHP

## Быстрый старт

```bash
composer require lphenom/queue
make up && make test
```

## Лицензия

MIT — см. [LICENSE](LICENSE)
