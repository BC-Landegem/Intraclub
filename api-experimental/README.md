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
    <Domain>/Repository   PDO data access (prepared statements)
    <Domain>/Service      Use-case services + validators
  Support/     Shared pure helpers (Transformer, MatchCalculator)
  Renderer/    JsonRenderer
  Middleware/  Validation/error middleware
  Handler/     DefaultErrorHandler (maps exceptions to JSON + status code)
resources/     Database schema
tests/         PHPUnit tests
```

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

The JSON response shapes match the legacy API so existing frontends keep working
after switching the base path to `/api`.

## Tooling

| Command                  | Description                                  |
| ------------------------ | -------------------------------------------- |
| `composer cs:check`      | PHP-CS-Fixer (PSR-12) dry-run                |
| `composer cs:fix`        | PHP-CS-Fixer apply                           |
| `composer sniffer:check` | PHP_CodeSniffer (PSR-12)                      |
| `composer stan`          | PHPStan static analysis (level 5)            |
| `composer test`          | PHPUnit                                       |
| `composer test:all`      | cs:check + sniffer:check + stan + test       |

CI runs `test:all` on every push/PR (see `../.github/workflows/ci.yml`).

## Not yet migrated

The legacy API gated mutating endpoints behind Joomla authentication
(`checkAccessRights`). That host-specific check is **not** ported here; add an
authentication middleware (e.g. `tuupola/slim-basic-auth`, already a dependency)
before exposing the write endpoints publicly.
