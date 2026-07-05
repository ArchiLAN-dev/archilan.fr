# Story 33.6 - Audit Worklist (Symfony 7 best-practices pass)

This worklist is the story scope (AC1). Findings from three parallel code audits + console checks
(2026-07-05, develop = 8648f4c), each verified in-repo before disposition. Nothing outside this
list is touched.

## A. Verified-clean axes (no work - recorded so the audit is complete)

Runtime container/service-locator usage: 0. Controllers: 129/129 plain invokables (no
`AbstractController`). Console commands: 14/14 `#[AsCommand]`. Event-subscriber layer: none
(async side effects via Messenger per AC-A4). Superglobals in src: 0. Setter injection: 0.
Deprecation-prone patterns: 0 (`getDoctrine`, `ManagerRegistry` injection, docblock annotations
- 848 attribute uses, deprecated `Security` class, deprecated serializer interfaces).
composer: symfony/* all `7.4.*` (7.4.8-7.4.13), PHP `^8.4`, no sensio/*, no doctrine/annotations,
ORM 3.6.5 / DBAL 4.4.3. phpunit.xml.dist: PHPUnit 13 schema, failOnDeprecation/Notice/Warning
all true, no `<listeners>`. Symfony 8.1 majors exist - migrations, out of scope (framework major
= its own epic).

## B. Items to FIX

| # | Item | Disposition |
|---|------|-------------|
| F1 | `Membership/Infrastructure/MembershipVoter::voteOnAttribute()` missing `?Vote $vote` param - the ONLY deprecation in `debug:container --deprecations` | Add the parameter; target: 0 remaining deprecations |
| F2 | 3 dead `@deprecated` shims: `Identity/Application/{DiscordBotStatusQuery, DiscordBotUsersQuery, DiscordResyncAllUsers}` - verified zero references (all callers use the interfaces; services.yaml:241-244 wires only the `Dbal*` impls) | Delete all 3 |
| F3 | `config/reference.php` - 101 KB auto-generated dump; sole repo reference is its own cs-fixer exclusion (`.php-cs-fixer.dist.php:9`) | Delete file + drop the `notPath` entry |
| F4 | `config/packages/validator.yaml:5-6` scaffold comment referencing non-existent `App\Entity\` | Delete the dead comment block |
| F5 | 12 non-final `#[ORM\Entity]` classes outside Sessions (Identity: `User`, `RefreshToken`, `PasswordResetToken`, `EmailConfirmationToken`, `RoleChangeAudit`, `PrivacyRightsRequest`, `DeletionAudit`, `AdminCreationAudit`; GameSelection: `Game`, `GameCatalogSync`, `GameRequest`, `IgnoredCatalogEntry`) - AC-D4; 31/45 entities already final; none subclassed. **Feasibility PROVEN**: DoctrineBundle 3.x forces `enable_native_lazy_objects: true` (Configuration.php:367 - can no longer be disabled), native lazy objects support final entities, and no proxy dir exists in var/cache | Add `final` to all 12; full functional suite as safety net |
| F6 | 3 `final readonly` promotions: `Streaming/Application/TwitchStatusChecker`, `Streaming/Application/ParticipantStreamsView`, `Identity/Application/RotationResult` (every property already readonly, no mutating methods) | Promote |
| F7 | `phpstan.neon:8` stale TODO (extensions "when the first entities and services are implemented" - now 45 entities, heavy DI) | Task 6, gated on approval for new dev deps (`phpstan/extension-installer`, `phpstan-symfony`, `phpstan-doctrine`); if new-error count > ~20, revert and re-scope the TODO with the measured number |

## C. Accepted as-is (with rationale)

| # | Item | Rationale |
|---|------|-----------|
| C1 | 13 static mutable properties in the 4 `when@test` doubles (`NullMinioStorage`, `StubIgdbHttpClient`, `StubSteamWebApiClient`, `NullRunnerGateway`) | **Refactor rejected at audit time** (story fallback clause 5.3): `KernelBrowser` reboots the kernel between requests, rebuilding the container; static state is the deliberate carrier that lets request 1 (upload/configure) be observed by request 2 (download/assert) across 12 test files. Instance state would silently break every multi-request functional test; `disableReboot()` everywhere would change test semantics for zero production benefit. The doubles are registered only in `when@test`; per-process isolation holds (statics are per-process); sequential in-process isolation is handled by the existing `reset()` calls. Production code has zero static mutable state (verified). Future candidate only if functional tests move to single-request patterns. |
| C2 | `Sessions` items: `Session`/`SessionSlot` non-final, `NullRunnerGateway` statics | Sessions frozen until Epic 32 merges (TODO epic-32) - mirrors 33.5 precedent |
| C3 | Flex scaffold comments in `framework.yaml`, `cache.yaml`, `security.yaml`, `routing.yaml` | Standard Symfony Flex documentation comments; harmless; deleting is churn |
| C4 | `ValidationErrors` not readonly | Mutable accumulator by design (`add()` writes outside constructor) |
| C5 | `ArchilanEmail` abstract base + 13 `*Email` subclasses not readonly | PHP forbids readonly subclasses of a non-readonly parent; restructuring is churn without defect |
| C6 | 3 constant-bag classes (`ReportCategory`, `ReportProblem`, `Audience`) vs native enums | Working closed-set validators; native-enum conversion is a style preference with call-site churn and zero defect |
| C7 | 8 no-state final Application classes not readonly (`SlotYamlNameReader`, `PaginationHelper`, `SlotNameGenerator`, `CleanupStaleSessionsTask`, `RefreshTokenFactory`, + the 3 F2 deletions) | No instance state - readonly adds nothing |
| C8 | Transitive `symfony/polyfill-php83` on PHP 8.4 | Harmless transitive dead weight; not directly required |
| C9 | `MembershipVoter:33` `$token->getUser()` | Correct non-deprecated token form - false-positive lookalike |

## D. Out of scope - recorded, not dropped

- ~130 zero-arg `new \DateTimeImmutable()` in Application → dedicated ClockInterface migration story (33.5 worklist section D).
- 25 public setters on 12 Domain entities (AC-D5) → future story + validator-rule candidate (joins 33.5 section D list). Several already take `$now` params - halfway to business methods.
