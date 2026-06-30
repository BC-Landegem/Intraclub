# Intraclub API (Slim 4)

Modern rewrite of the Intraclub backend on **Slim 4** + **PHP-DI**, replacing the
legacy Slim 3 API in [`../api`](../api). It follows a single-action / domain-service
/ repository architecture (the [odan/slim4-skeleton](https://odan.github.io/slim4-skeleton/)
layout).

## Requirements

- PHP 8.3+
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

## Authentication

Reads are public; **every mutating (POST) endpoint requires a JWT**.

1. Create users with the console command:
   `composer start` aside, run `php bin/console.php app:user:create <username> [<password>]`
   (the password is prompted securely if omitted). Passwords are stored hashed
   (`password_hash`, bcrypt).
2. `POST /api/login` with `{ "username": "...", "password": "..." }` returns
   `{ "token": "<jwt>", "expiresIn": <seconds>, "user": { "id", "username" } }`.
3. Send the token on protected requests: `Authorization: Bearer <jwt>`.

Tokens are HS256-signed; set a long random `JWT_SECRET` environment variable in
production (the app refuses to issue/validate tokens with an empty secret).
`JWT_EXPIRES_IN` (seconds, default 28800 = 8h) tunes the lifetime.

## Endpoints

All routes are prefixed with `/api`. "Auth" = requires a Bearer token.

| Method | Path                              | Auth   | Description                          |
| ------ | --------------------------------- | ------ | ------------------------------------ |
| POST   | `/login`                          | public | Obtain a JWT                         |
| GET    | `/players`                        | public | List members                         |
| GET    | `/players/{id}`                   | public | Player + season stats, matches, history (optional `?seasonId=`) |
| GET    | `/seasons/latest/statistics`      | public | Season statistics                    |
| GET    | `/rounds`                         | public | Rounds of a season (optional `?seasonId=`) |
| GET    | `/rounds/latest`                  | public | Latest round                         |
| GET    | `/rounds/latestCalculated`        | public | Latest calculated round              |
| GET    | `/rounds/{id}`                    | public | Round with matches                   |
| GET    | `/rounds/{id}/matches`            | public | Matches of a round                   |
| GET    | `/rankings`                       | public | All rankings (`?$top=` to limit)     |
| GET    | `/rankings/{type}`                | public | `general` / `women` / `veterans` / `recreants` |
| POST   | `/players`                        | token  | Create a player                      |
| POST   | `/players/{id}`                   | token  | Update a player                      |
| POST   | `/rounds/{id}/players/{playerId}` | token  | Update player attendance for a round |
| POST   | `/seasons`                        | token  | Create a season                      |
| POST   | `/seasons/calculate`              | token  | Recalculate the current season       |
| POST   | `/rounds`                         | token  | Create a round                       |
| POST   | `/matches`                        | token  | Create a match                       |
| POST   | `/matches/{id}`                   | token  | Update match scores                  |

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

## Security

Hardening applied after an OWASP Top 10 review:

- **Fail-closed environment.** `APP_ENV` defaults to `prod`, so a host that
  forgets to set it loads production settings (no error details, no built-in
  secret) rather than the development config. Set `APP_ENV=dev` for local work
  (`composer start` does this automatically).
- **Authentication.** JWT on all write routes (see above); passwords hashed with
  `password_hash` (bcrypt); login is constant-time against user enumeration.
- **Login rate limiting.** Failed logins are throttled per client IP
  (5 attempts / 15 min → HTTP 429 with `Retry-After`) and all auth events are
  logged to `logs/auth.log`.
- **Security headers** on every response (`X-Content-Type-Options`,
  `X-Frame-Options`, `Referrer-Policy`, `Content-Security-Policy`, HSTS).
- **CORS** is an explicit allow-list (no wildcard). Configure it with the
  `CORS_ALLOWED_ORIGINS` environment variable (comma-separated origins);
  empty = same-origin only.
- **Supply chain.** `composer.lock` is committed and CI runs `composer install`
  + `composer audit` so known-vulnerable dependencies fail the build.
- **Transport.** Serve the API over HTTPS only — tokens and credentials must not
  travel in clear text (the HSTS header assumes TLS).

The legacy API gated mutating endpoints behind Joomla authentication
(`checkAccessRights`); that host-specific check is replaced by the JWT
authentication above.
