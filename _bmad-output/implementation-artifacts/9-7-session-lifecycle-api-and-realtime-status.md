# Story 9.7 - Session Lifecycle API and Realtime Status

Status: done

## Review Findings

- The session domain accepted a transition to `running` without connection details.
- A malformed runner callback could persist `running` with `host`, `port`, or `password` missing.
- That state would still publish Mercure updates and could trigger player notifications with empty connection data.
- The admin transition endpoint also discarded optional connection details, so tests could only drive `running` with null values.

## Corrections

- The `Session` aggregate now rejects `running` unless host, positive port, and password are provided.
- The admin transition endpoint forwards optional `host`, `port`, and `password` fields into the lifecycle manager.
- Functional tests now cover rejection of a runner `running` callback without connection details.
- Existing state-machine tests now use a helper that supplies connection details when a test intentionally moves to `running`.

## Validation

- `php bin/phpunit tests/Functional/SessionLifecycleTest.php`
- `vendor/bin/phpstan analyse src/Sessions/Domain/Session.php src/Sessions/Presentation/AdminSessionController.php src/Sessions/Presentation/RunnerCallbackController.php tests/Functional/SessionLifecycleTest.php --level=6`
- `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Sessions/Domain/Session.php src/Sessions/Presentation/AdminSessionController.php tests/Functional/SessionLifecycleTest.php`
- `python -m pytest runner/tests/test_api_notifier.py`
