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
