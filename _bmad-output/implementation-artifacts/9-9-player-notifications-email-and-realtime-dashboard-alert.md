# Story 9.9 - Player Notifications, Email and Realtime Dashboard Alert

Status: done

## Review Findings

- The dashboard alert was published on a user-specific Mercure topic, but the Mercure `Update` itself was not marked private.
- Failed async messages had a retry strategy, but no failure transport was configured, so exhausted notification deliveries were not explicitly retained as failed messages.
- The retry behavior was not covered by a focused test.

## Corrections

- Session running Mercure alerts are now private updates on `/users/{userId}/session-alerts`.
- Messenger now has an `async_failed` failure transport and uses it as the global `failure_transport`.
- `MESSENGER_FAILED_TRANSPORT_DSN` was added for local/test environments.
- Unit coverage now asserts the private Mercure update and the 3-retry exponential backoff plus failure transport configuration.
- Existing handler test typing was tightened while touching the file.

## Validation

- `php bin/phpunit tests/Unit/Communications/SessionRunningHandlerTest.php tests/Unit/Communications/SessionRunningRetryConfigurationTest.php`
- `php bin/phpunit tests/Functional/SessionLifecycleTest.php`
- `vendor/bin/phpstan analyse src/Communications/Application/SessionRunningHandler.php tests/Unit/Communications/SessionRunningHandlerTest.php tests/Unit/Communications/SessionRunningRetryConfigurationTest.php --level=6`
- `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Communications/Application/SessionRunningHandler.php tests/Unit/Communications/SessionRunningHandlerTest.php tests/Unit/Communications/SessionRunningRetryConfigurationTest.php`
