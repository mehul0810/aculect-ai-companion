# Aculect AI Companion Tests

This directory contains the automated test suite for the Aculect AI Companion plugin.

## Unit Tests

Unit tests live in `tests/Unit` and run through:

```bash
composer run test:unit
```

These tests bootstrap Composer autoloading and a small set of WordPress function stubs from `tests/bootstrap.php`. They are intended for deterministic plugin behavior that does not require a WordPress database, HTTP server, or authenticated user.

## Integration Tests

Database-backed WordPress integration tests should be added separately when a flow needs real WordPress runtime behavior, for example OAuth table migrations, REST routing, or end-to-end authorization. Do not expand the unit bootstrap into a partial WordPress runtime.

`tests/Integration/ExecutionClaims/real-database-concurrency.php` is a source-external process harness for the production execution-claim installer and store. The read-only-permission `Execution Claims Concurrency` pull-request workflow runs it against isolated MySQL 8.0/InnoDB and MariaDB 10.11/InnoDB services. It accepts only the workflow-provided disposable database environment variables and proves that eight simultaneous confirmation-token workers and eight simultaneous identity-bound idempotency workers each produce one authoritative owner and one side effect.
