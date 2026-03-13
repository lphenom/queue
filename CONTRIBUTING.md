# Contributing to lphenom/queue

Thank you for your interest in contributing! 🎉

## Getting Started

```bash
git clone git@github.com:lphenom/queue.git
cd queue
make install
```

## Development Environment

All tools run inside Docker — no local PHP/Composer required:

```bash
make up        # Start MySQL + Redis
make install   # Install composer dependencies
make test      # Run PHPUnit tests
make lint      # Check code style
make lint-fix  # Auto-fix code style
make analyse   # PHPStan static analysis
make check     # lint + analyse + test
make kphp-check # KPHP binary build + PHAR verification
```

## Code Standards

- PHP >= 8.1
- `declare(strict_types=1);` in every file
- **KPHP-compatible** — see [docs/kphp-compatibility.md](docs/kphp-compatibility.md)
  - No `str_starts_with()` / `str_ends_with()` / `str_contains()`
  - No `JSON_THROW_ON_ERROR`
  - No `readonly` properties
  - No constructor property promotion
  - No `match` expressions (use `if/elseif`)
  - No `callable` in typed arrays
  - No trailing commas in function call argument lists
  - No `__destruct()`
- PSR-12 code style
- PHPStan level 8

## Commits

Small, focused commits. Conventional commit format:

```
feat(queue): add redis queue driver
fix(queue): handle blpop timeout correctly
test(queue): add retry policy edge cases
docs(queue): update queue.md with examples
chore: bump phpunit to 10.5
```

## Pull Requests

1. Fork the repo
2. Create a feature branch: `git checkout -b feat/my-feature`
3. Make small commits
4. Ensure `make check` passes
5. Ensure `make kphp-check` passes
6. Open a PR against `main`

## Questions

Open a GitHub Discussion or email popkovd.o@yandex.ru.

