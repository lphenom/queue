# lphenom/queue

**LPhenom Queue** — KPHP-compatible queue package for the LPhenom framework.

Provides a unified `QueueInterface` with DB (shared hosting) and Redis (production) drivers, exponential backoff retry policy, and full KPHP support.

## Documentation

- [docs/queue.md](docs/queue.md) — Usage guide
- [docs/kphp-compatibility.md](docs/kphp-compatibility.md) — KPHP constraints

## Quick Start

```bash
composer require lphenom/queue
make up && make test
```

## License

MIT — see [LICENSE](LICENSE)
