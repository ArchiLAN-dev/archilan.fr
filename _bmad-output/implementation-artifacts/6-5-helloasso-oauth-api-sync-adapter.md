# Story 6.5 - HelloAsso OAuth/API Sync Adapter

Status: done

## Review Findings

- The sync handler treated missing client id/secret as configuration errors, but did not validate `HELLOASSO_ORGANIZATION_SLUG` before deciding whether a failure should skip retry or be retried by Messenger.
- The server-side adapter and handler had no end-to-end test proving OAuth token retrieval, HelloAsso item fetch, internal order mapping, success logging, and transient failure retry behavior.

## Corrections

- Updated `SyncHelloAssoFormHandler` to call `HelloAssoConfig::assertApiAccessConfigured()` before any network call.
- Added `HelloAssoSyncHandlerTest` with `MockHttpClient` coverage for:
  - OAuth token retrieval through the server-side adapter;
  - HelloAsso form item retrieval;
  - internal `HelloAssoOrder` persistence without exposing raw API secrets;
  - success sync log persistence;
  - HTTP 503 failure log persistence;
  - rethrowing transient fetch failures so Messenger can retry.

## Validation

- `composer test -- tests/Functional/HelloAssoSyncHandlerTest.php tests/Functional/HelloAssoSyncTest.php`
- `composer phpstan`
- `php vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Payments/Application/SyncHelloAssoFormHandler.php tests/Functional/HelloAssoSyncHandlerTest.php tests/Functional/HelloAssoSyncTest.php`
