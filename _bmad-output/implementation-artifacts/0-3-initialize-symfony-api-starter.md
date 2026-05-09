# Story 0.3: Initialize Symfony API Starter

Status: done

## Story

As a developer,
I want a Symfony LTS API initialized in `api/`,
so that backend use cases can be implemented on the approved DDD/N-Tier stack.

## Acceptance Criteria

1. Given the repository baseline exists, when the Symfony API starter is initialized, then `api/` contains a Symfony 7.4 LTS skeleton project.
2. Required bundles are installed: ORM pack, security bundle, Lexik JWT auth bundle, serializer pack, Messenger, Mailer, PHPStan, PHP CS Fixer, and test pack.
3. `composer validate`, PHPUnit, PHPStan, and PHP CS Fixer commands are available.
4. No business-domain code beyond starter-safe framework files is implemented.

## Tasks / Subtasks

- [x] Initialize the approved Symfony starter (AC: 1, 4)
  - [x] Confirm Story 0.1 baseline is present before starting.
  - [x] Confirm Story 0.2 frontend exists and remains untouched except for cross-story documentation if required.
  - [x] Create `api/` using `symfony new api --version=lts`.
  - [x] Confirm generated Symfony version is 7.4 LTS.
  - [x] Ensure no business-domain controllers, entities, services, or DDD contexts are introduced.
- [x] Install required runtime bundles (AC: 2, 4)
  - [x] Install `symfony/orm-pack`.
  - [x] Install `symfony/security-bundle`.
  - [x] Install `lexik/jwt-authentication-bundle`.
  - [x] Install `symfony/serializer-pack`.
  - [x] Install `symfony/messenger`.
  - [x] Install `symfony/mailer`.
  - [x] Do not install API Platform in this story; architecture defers it for v1 unless later justified.
- [x] Install required quality tooling (AC: 2, 3)
  - [x] Install `phpstan/phpstan` as dev dependency.
  - [x] Install `friendsofphp/php-cs-fixer` as dev dependency.
  - [x] Install `symfony/test-pack` as dev dependency.
  - [x] Add Composer scripts for `test`, `phpstan`, and `cs-fixer` dry-run if not generated.
- [x] Validate backend starter (AC: 1, 3, 4)
  - [x] Run `composer validate`.
  - [x] Run PHPUnit.
  - [x] Run PHPStan.
  - [x] Run PHP CS Fixer dry-run.
  - [x] Confirm no `api/src/Controller` business endpoint is generated beyond framework-safe placeholders.
  - [x] Update this story file with commands run, validation results, and file list.

## Dev Notes

This story initializes the backend starter only. It must not implement ArchiLAN authentication, users, events, registrations, payments, realtime endpoints, email workflows, DDD bounded contexts, database schema, migrations, or API response formats. Those belong to later stories.

### Prerequisites

Required baseline files from Story 0.1:

- `README.md`
- `.gitattributes`
- `.editorconfig`
- `.gitignore`
- `.env.example`
- `docker-compose.yml`

Required frontend baseline from Story 0.2:

- `frontend/package.json`
- `frontend/components.json`
- `frontend/src/app`

Story 0.2 review corrections from Claude must be preserved:

- `frontend/.gitignore` keeps `!.env.example`.
- `frontend/next.config.ts` documents the `turbopack.root` workaround.
- `frontend/pnpm-workspace.yaml` documents migration debt to a future root workspace.
- `frontend/README.md` reflects the real stack.
- `frontend/AGENTS.md` and `frontend/CLAUDE.md` intentionally provide Next 16 agent guidance.

### Required Starter Command

Run from repository root:

```bash
symfony new api --version=lts
```

If Symfony CLI is unavailable, use Composer with the exact current LTS constraint:

```bash
composer create-project symfony/skeleton:"7.4.*" api
```

### Required Bundles

Run from `api/` after starter creation:

```bash
composer require symfony/orm-pack
composer require symfony/security-bundle
composer require lexik/jwt-authentication-bundle
composer require symfony/serializer-pack
composer require symfony/messenger
composer require symfony/mailer
composer require --dev phpstan/phpstan
composer require --dev friendsofphp/php-cs-fixer
composer require --dev symfony/test-pack
```

Do not install `api-platform/core` in this story. Architecture explicitly defers API Platform for v1.

### Quality Commands

Ensure `api/composer.json` exposes:

- `test`
- `phpstan`
- `cs-fixer`

Preferred scripts:

```json
{
  "scripts": {
    "test": "php bin/phpunit",
    "phpstan": "php vendor/bin/phpstan analyse src tests",
    "cs-fixer": "php vendor/bin/php-cs-fixer fix --dry-run --diff"
  }
}
```

If PHPStan needs minimal config, add `api/phpstan.neon` with practical starter scope. Avoid failing on missing future DDD directories.

If PHP CS Fixer needs config, add `api/.php-cs-fixer.dist.php` targeting generated PHP files only.

### Testing Requirements

No user-facing feature tests are required because this story only initializes the starter. Required validation:

- `composer validate` passes.
- `composer test` or equivalent PHPUnit command passes.
- `composer phpstan` or equivalent passes.
- `composer cs-fixer` or equivalent dry-run passes.
- `api/composer.json` contains required packages.
- `api/src` contains starter-safe Symfony files only.
- No ArchiLAN business-domain backend code is introduced.

### Latest Technical Information

- Symfony 7.4 is the current LTS line as of 2026-04-25, with bug fixes until November 2028 and security fixes until November 2029.
- Symfony official setup docs support `symfony new my_project_directory --version=lts`; Composer fallback requires an exact version constraint such as `symfony/skeleton:"7.4.*"`.
- Symfony 7.4 requires PHP 8.2 or higher. The architecture prefers PHP 8.3+ for project implementation.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-0.3-Initialize-Symfony-API-Starter]
- [Source: _bmad-output/planning-artifacts/architecture.md#Starter-2--Symfony-74-LTS-API]
- [Source: _bmad-output/planning-artifacts/architecture.md#Core-Architectural-Decisions]
- [Source: _bmad-output/implementation-artifacts/0-1-initialize-monorepo-baseline.md]
- [Source: _bmad-output/implementation-artifacts/0-2-initialize-nextjs-frontend-starter.md]
- [Source: Symfony 7.4 release](https://symfony.com/releases/7.4)
- [Source: Symfony setup documentation](https://symfony.com/doc/current/setup.html)

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- `symfony new api --version=lts` failed in sandbox on network access to `symfony.com/all-versions.json`, then succeeded with escalation.
- Symfony CLI created a nested `api/.git`; removed it because this repository is a monorepo and the root Git repository is authoritative.
- `composer require ...` commands failed in sandbox on Composer cache/network access, then succeeded with escalation.
- Symfony Flex unpacked `symfony/orm-pack` and `symfony/serializer-pack`; their concrete packages are present in `composer.json`, `composer.lock`, and `symfony.lock`.
- Added Composer `name` and `description` because strict `composer validate` requires them.
- Ran `composer update --lock --no-interaction` to resynchronize `composer.lock` after metadata changes; no package versions changed.
- Added a starter-safe `tests/KernelTest.php` so PHPUnit validates an actual Symfony smoke test instead of reporting zero executed tests.
- Excluded generated `tests/bootstrap.php` from PHPStan because Symfony's generated bootstrap triggers a level max `method_exists()` always-true warning.
- Removed generated `api/compose.yaml` and `api/compose.override.yaml`; root `docker-compose.yml` remains the only Docker source of truth until the dedicated local environment story.

### Completion Notes List

- Initialized `api/` with Symfony `7.4.8` LTS using Symfony CLI.
- Installed backend runtime stack: Doctrine ORM/Bundle/Migrations, Symfony Security, Lexik JWT Authentication Bundle, Symfony Serializer, Messenger, and Mailer.
- Installed backend quality stack: PHPUnit, PHPStan, and PHP CS Fixer.
- Added Composer scripts: `test`, `phpstan`, and `cs-fixer`.
- Added `api/phpstan.neon` at PHPStan `level: max` for `src` and `tests`, excluding only Symfony's generated test bootstrap.
- Added `api/tests/KernelTest.php` as a framework smoke test.
- Confirmed `api/src/Controller` contains only a `.gitignore`; no business endpoint, entity, service, DDD context, or ArchiLAN-specific code was introduced.
- Confirmed API Platform was not installed.
- Generated local Symfony `.env` files contain starter/dev values and remain ignored by the root `.gitignore`; `api/.env.example` is still planned for Story 0.6.

### Validation Results

- `php -v` - PHP `8.4.12`.
- `composer --version` - Composer `2.8.3`.
- `symfony version` - Symfony CLI `5.16.1`.
- `php bin/console --version` - Symfony `7.4.8`.
- `composer validate` - passed.
- `composer test` - passed: `OK (1 test, 1 assertion)`.
- `composer phpstan` - passed: no errors.
- `composer cs-fixer` - passed dry-run; PHP CS Fixer warned that local PHP `8.4.12` is newer than the project minimum `>=8.2`.
- `Test-Path api\.git; Test-Path api\compose.yaml; Test-Path api\compose.override.yaml` - `False`, `False`, `False`.

### File List

- `api/.editorconfig`
- `api/.gitignore`
- `api/.php-cs-fixer.dist.php`
- `api/bin/console`
- `api/bin/phpunit`
- `api/composer.json`
- `api/composer.lock`
- `api/config/bundles.php`
- `api/config/packages/cache.yaml`
- `api/config/packages/doctrine.yaml`
- `api/config/packages/doctrine_migrations.yaml`
- `api/config/packages/framework.yaml`
- `api/config/packages/lexik_jwt_authentication.yaml`
- `api/config/packages/mailer.yaml`
- `api/config/packages/messenger.yaml`
- `api/config/packages/property_info.yaml`
- `api/config/packages/routing.yaml`
- `api/config/packages/security.yaml`
- `api/config/preload.php`
- `api/config/reference.php`
- `api/config/routes.yaml`
- `api/config/routes/framework.yaml`
- `api/config/routes/security.yaml`
- `api/config/services.yaml`
- `api/migrations/.gitignore`
- `api/phpstan.neon`
- `api/phpunit.dist.xml`
- `api/public/index.php`
- `api/src/Controller/.gitignore`
- `api/src/Entity/.gitignore`
- `api/src/Kernel.php`
- `api/src/Repository/.gitignore`
- `api/symfony.lock`
- `api/tests/bootstrap.php`
- `api/tests/KernelTest.php`
- `_bmad-output/implementation-artifacts/0-3-initialize-symfony-api-starter.md`

### Change Log

- 2026-04-25: Created and implemented Story 0.3 Symfony API starter, required bundles, backend quality scripts/configuration, and validation smoke test.
