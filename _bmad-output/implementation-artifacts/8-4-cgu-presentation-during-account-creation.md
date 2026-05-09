# Story 8.4 - CGU Presentation During Account Creation

## Review

Status: done

Acceptance criteria reviewed:

- Signup form links to `/cgu`.
- CGU checkbox is not pre-checked.
- Account creation sends `acceptedCgu` to the API.
- API rejects missing acceptance with a field-level `acceptedCgu` validation error.
- Existing users store `cgu_accepted_at`.

Finding:

- The accepted CGU timestamp was stored, but the accepted CGU version was not persisted.

## Corrections

- Added `User::CURRENT_CGU_VERSION` and persisted `cgu_accepted_version` on `identity_users`.
- Added migration `Version20260502000009`.
- Added functional assertion that registration stores the current CGU version.
- Displayed the CGU version in the signup checkbox copy.
- Aligned the `/cgu` page header with the same dated version.

## Validation

- `php bin/phpunit tests/Functional/RegisterLambdaUserTest.php`
- `vendor/bin/phpstan analyse src/Identity/Domain/User.php src/Identity/Application/RegisterLambdaUser.php src/Identity/Presentation/RegisterLambdaUserController.php tests/Functional/RegisterLambdaUserTest.php --level=6`
- `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Identity/Domain/User.php tests/Functional/RegisterLambdaUserTest.php migrations/Version20260502000009.php`
- `pnpm lint -- src/features/auth/signup-form.tsx src/app/cgu/page.tsx`
- `pnpm typecheck`

