# Intraclub API (Slim 4)

Modern rewrite of the Intraclub backend on **Slim 4** + **PHP-DI**, replacing the
legacy Slim 3 API in [`../api`](../api). It follows a single-action / domain-service
/ repository architecture (the [odan/slim4-skeleton](https://odan.github.io/slim4-skeleton/)
layout).

## Requirements

- PHP 8.1+
- MySQL / MariaDB
- [Composer](https://getcomposer.org/)

## Installation

1. `composer install`
2. Copy `config/env.example.php` to `config/env.php` and fill in the database
   credentials (this file is git-ignored). For local development you can instead
   edit `config/local.dev.php`.
3. Create the schema from `resources/schema/schema.sql`.
4. `composer start` to launch the dev server on http://localhost:8080

## Architecture

```
config/        Bootstrap, DI container, settings, routes, middleware
public/        Front controller (index.php)
src/
  Action/      One invokable class per HTTP endpoint (HTTP layer only)
  Domain/      Business logic grouped per domain:
    <Domain>/Repository   Data access via the CakePHP query builder
    <Domain>/Service      Use-case services + validators
    <Domain>/Data         Readonly DTOs / value objects (JsonSerializable)
    <Domain>/Enum         Enums (e.g. Gender)
  Support/     Shared pure helpers (MatchCalculator)
  Factory/     QueryFactory (wraps the CakePHP connection)
  Renderer/    JsonRenderer
  Middleware/  Validation/error middleware
  Handler/     DefaultErrorHandler (maps exceptions to JSON + status code)
resources/     Database schema
tests/         PHPUnit tests
```

Repositories build queries with the **CakePHP query builder** (`QueryFactory`) —
including window functions for the rankings — and return **typed readonly DTOs**
that implement `JsonSerializable`, so the JSON output is defined in one place per
resource. Dates are `DateTimeImmutable` (serialized as `Y-m-d`) and gender is a
backed `Gender` enum.

Invalid input and "not found" conditions are signalled by throwing
`DomainException` / `InvalidArgumentException`; `DefaultErrorHandler` renders
those as HTTP `400` with a JSON `error` body.

## Endpoints

All routes are prefixed with `/api`.

| Method | Path                              | Description                          |
| ------ | --------------------------------- | ------------------------------------ |
| GET    | `/players`                        | List members                         |
| POST   | `/players`                        | Create a player                      |
| GET    | `/players/{id}`                   | Player + season stats, matches, history (optional `?seasonId=`) |
| POST   | `/players/{id}`                   | Update a player                      |
| POST   | `/rounds/{id}/players/{playerId}` | Update player attendance for a round |
| POST   | `/seasons`                        | Create a season                      |
| POST   | `/seasons/calculate`              | Recalculate the current season       |
| GET    | `/seasons/latest/statistics`      | Season statistics                    |
| GET    | `/rounds`                         | Rounds of a season (optional `?seasonId=`) |
| POST   | `/rounds`                         | Create a round                       |
| GET    | `/rounds/latest`                  | Latest round                         |
| GET    | `/rounds/latestCalculated`        | Latest calculated round              |
| GET    | `/rounds/{id}`                    | Round with matches                   |
| GET    | `/rounds/{id}/matches`            | Matches of a round                   |
| POST   | `/matches`                        | Create a match                       |
| POST   | `/matches/{id}`                   | Update match scores                  |
| GET    | `/rankings`                       | All rankings (`?$top=` to limit)     |
| GET    | `/rankings/{type}`                | `general` / `women` / `veterans` / `recreants` |

The JSON output is a clean, typed redesign (camelCase keys, enums as strings,
dates as `Y-m-d`, nested value objects) defined by the DTOs in each domain's
`Data/` folder — it is **not** byte-compatible with the legacy API and targets
the new frontend.

## Tooling

| Command                  | Description                                  |
| ------------------------ | -------------------------------------------- |
| `composer cs:check`      | PHP-CS-Fixer (PSR-12) dry-run                |
| `composer cs:fix`        | PHP-CS-Fixer apply                           |
| `composer sniffer:check` | PHP_CodeSniffer (PSR-12)                      |
| `composer stan`          | PHPStan static analysis (level 5)            |
| `composer test`          | PHPUnit (unit + integration)                 |
| `composer test:all`      | cs:check + sniffer:check + stan + test       |

CI runs `test:all` on every push/PR (see `../.github/workflows/ci.yml`).

### Tests

- **Unit** (`tests/Support`, `tests/Data`) — pure logic, no database.
- **Integration** (`tests/Integration`) — HTTP-level tests that boot the real
  container and Slim app and fire PSR-7 requests through the full middleware /
  routing / action / service / repository stack against a **real database**,
  covering every endpoint (success and error paths). A fresh schema + fixture is
  loaded per test.

Integration tests need a MySQL/MariaDB database; configure it via environment
variables (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`)
with `APP_ENV=test`. CI provisions a MariaDB service automatically. To run only
the database-free tests locally: `composer test -- --testsuite Unit`.

## Not yet migrated

The legacy API gated mutating endpoints behind Joomla authentication
(`checkAccessRights`). That host-specific check is **not** ported here; add a
PSR-15 authentication middleware (e.g. a small custom middleware, or a maintained
package such as `middlewares/http-authentication`) before exposing the write
endpoints publicly.
