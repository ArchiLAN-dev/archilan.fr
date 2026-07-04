# Story 33.5 - Audit Worklist (DDD compliance sweep + layer-folder tidy)

This worklist is the story scope (AC1). Every item below is either implemented or explicitly
accepted with rationale. Nothing outside this list is touched. Produced from a full scan of
`api/src` at `develop` = 0c6037d; scan commands recorded in the story Dev Agent Record.

## A. New validator rules (DddArchitectureValidator)

| # | Rule | Detection (text-based, consistent with existing style) | Current violations | Allowlist |
|---|------|--------------------------------------------------------|--------------------|-----------|
| R1 | Cross-context layer restriction: no file may import another context's `Infrastructure\` or `Presentation\` (target context `Shared` exempt - shared kernel: `ApiAccessGuard`, `RequiresAuthTrait`, `MinioStorageInterface` are documented patterns) | `use App\{Other}\Infrastructure\` / `use App\{Other}\Presentation\` where `{Other}` != own context and != `Shared` | 0 (scan: all ~270 cross-context imports target Domain/Application only) | none |
| R2 | Domain upward imports, all contexts (AC-D2 completed): Domain may not import `Application\`, `Infrastructure\` or `Presentation\` of ANY context (today only same-context is checked) | `use App\{Any}\{Application\|Infrastructure\|Presentation}\` in `Domain/` files | 0 | none |
| R3 | `*RepositoryInterface.php` must live in `Domain/` (AC-A2) | filename suffix + path segment check | 0 | none |
| R4 | `*QueryInterface.php` must live in `Application/` (AC-A2) | filename suffix + path segment check | 0 | none |
| R5 | Application must not import `Infrastructure\` of any context except `Shared` (AC-I2/AC-A5) | `use App\{Any}\Infrastructure\` in `Application/` files, `{Any}` != `Shared` | 8 files - resolved by M5 (interface relocations, 6 imports) + M6 (interface extraction, 2 imports); 2 remaining allowlisted | `Sessions/Application/Handler/ArchiveRunJobHandler.php`, `Sessions/Application/Handler/FetchLogsJobHandler.php` (import concrete `RunnerCallbackClient`; Sessions frozen until Epic 32 merges - TODO epic-32) |
| R6 | No clock reads in Application (no-magic rule): `date(`, `time()`, zero-arg / `'now'` `new DateTime` / `new DateTimeImmutable` | call-pattern regexes in `Application/` files | 0 | none |
| R7 | No `new` on Infrastructure FQCNs in Application (AC-A5; complements R5 for non-imported FQCN usage) | `new App\{Any}\Infrastructure\` / `new \App\{Any}\Infrastructure\` in `Application/` files | 0 | none |
| R8 | `createNativeQuery` added to `FORBIDDEN_PRESENTATION_CALLS` (AC-P2 lists 7 calls, validator has 6) | existing `(?:->\|::)method\(` regex list | 0 | none |

Each rule ships with unit tests in `tests/Unit/DddArchitectureValidatorTest.php` (violation
detected / allowlisted file passes / clean code passes).

## B. File moves (folder tidy)

| # | Move | Blast-radius notes |
|---|------|--------------------|
| M1 | `Identity/Application/Message/{CleanupRefreshTokensHandler, CleanupEmailConfirmationTokensHandler, CleanupPasswordResetTokensHandler, SyncDiscordRoleMessageHandler}` → `Identity/Application/Handler/` | handlers only - messenger.yaml routes MESSAGES, unaffected; grep services.yaml per FQCN; mirrored unit tests move |
| M2 | `Events/Application/Message/CleanupEventPrivateAccessLogHandler` → `Handler/`; `Events/Application/EventCapacityReachedHandler` → `Handler/`; `Events/Application/EventCapacityReachedMessage` → `Message/` | `EventCapacityReachedMessage` FQCN: update messenger.yaml routing + dispatcher import (`Registrations/Application/ReserveRegistration.php`) |
| M3 | `Payments/Application/Message/CleanupHelloAssoSyncLogHandler` → `Handler/`; `Payments/Application/SyncHelloAssoFormHandler` → `Handler/`; `Payments/Application/SyncHelloAssoFormMessage` → `Message/` | `SyncHelloAssoFormMessage` FQCN: messenger.yaml routing + dispatchers (`TriggerHelloAssoSync`) |
| M4 | `Communications/Application/{EmailConfirmation, PasswordReset, RegistrationConfirmation, SessionPausedWithoutSave, SessionRestartFailed, SessionRunning}{Message,Handler}` (6 pairs) → `Communications/Application/Message/` + `Handler/` | 6 message FQCNs in messenger.yaml routing; dispatchers in `Identity/Application/{RequestPasswordReset, SendEmailConfirmation}`, `Registrations/Application/RegistrationSubmission`, `Sessions/Application/SessionLifecycleManager` (import updates only - Sessions files are edited for imports of MOVED third-party classes, which is not a Sessions relocation; AC4 respected) |
| M5 | Port interfaces out of Infrastructure (AC-I2: "interfaces defined in Application or Shared"): `GameSelection/Infrastructure/{IgdbHttpClientInterface, SteamWebApiClientInterface}` → `GameSelection/Application/`; `Identity/Infrastructure/DiscordOAuthClientInterface` → `Identity/Application/`; `Streaming/Infrastructure/TwitchApiClientInterface` → `Streaming/Application/` | services.yaml interface→impl alias FQCNs; importers in Application/Presentation/Infrastructure of same context (+ CatalogSync for Igdb); mirrored tests |
| M6 | Extract `HelloAssoClientInterface` in `Payments/Application/`, `HelloAssoHttpClient` implements it; `HandleHelloAssoWebhook` + `SyncHelloAssoFormHandler` depend on the interface (AC-I2: concrete Infrastructure class currently referenced by Application) | new interface file + services.yaml alias; behaviour-preserving (same impl injected) |

## C. Accepted as-is (with rationale)

| # | Item | Rationale |
|---|------|-----------|
| C1 | `Communications/Application/ArchilanMailer` stays in Application | Application service delegating to the Symfony `MailerInterface` port (allowed outside Domain). Moving it to Infrastructure would turn the imports in Events/Registrations/Membership handlers into cross-context Infrastructure imports - violating R1. |
| C2 | `CatalogSync/Application/GithubRateLimitException` stays | Thrown by Application code itself (`ApworldVersionChecker:342`), caught in Application - it is an application-level exception, not an infra leak. |
| C3 | `Payments/Presentation/HelloAssoWebhook{Payload,OrderData,PayerData}` stay in Presentation | Request-parsing models used only by Presentation (verified: zero `use App\Payments\Presentation\` in Application). |
| C4 | `SessionConfig/Domain/SessionConfigOverrideStore` stays in Domain | ORM entity (`#[ORM\Entity]`) - Domain is the correct layer for entities. |
| C5 | `Shared/Presentation/RequiresAuthTrait` stays | Controller helper trait - Presentation is its natural layer; imported only by controllers. |
| C6 | `Identity/Presentation/Command/ResyncDiscordRolesCommand` stays | Symfony console command; `Presentation/Command/` subfolder is a legal refinement, not a layer violation. |
| C7 | Flat sync command/query services in `Application/` stay flat | Documented convention (CQRS naming table): only async Message/Handler get sub-namespaces; `Application/*Command.php` here = CQRS command services, distinct from `Presentation/*Command.php` = console commands. |
| C8 | `Sessions` context: zero file moves | AC4 - Epic 32 (stashed, unmerged) rewrites Sessions. Rule violations there are allowlisted with TODO epic-32 (see R5). |
| C9 | Empty layer dirs (`Legal/*` all four, `Communications/{Domain,Infrastructure,Presentation}`, `Realtime/Domain`) kept | `validateContextDirectories()` requires all four layer dirs per context. |
| C10 | Cross-context Domain→Domain imports (e.g. `Registrations/Domain/RegistrationRepositoryInterface` ← `Events\Domain\Event`) allowed | AC-D2 bans upper-layer imports only; repository interface signatures legitimately reference other aggregates. Root `CLAUDE.md`'s stricter "Domain imports nothing from the project" is contradicted by established practice - residual doc nit, out of scope here. |
| C11 | Interfaces in `Shared/Infrastructure` (`MinioStorageInterface`) stay | AC-I2 letter: "interfaces defined in Application **or Shared**" - satisfied; Shared is exempted as target in R1/R5. |
| C12 | `Sessions/Presentation/ContainerController` importing `Shared\Infrastructure\DockerSocketClient` | Shared target (R1 exempt) + Sessions frozen; no Presentation→Infrastructure rule added this story (the documented `ApiAccessGuard` pattern makes it structurally legitimate). |

## D. Out of scope - candidate future validator rules (recorded, not silently dropped)

AC-D1 full `Symfony\Component\*`/`Contracts\*` coverage (only 4 namespaces checked today);
AC-D3 domain purity (clock/randomness/IO); AC-D4 `final`/`final readonly`; AC-D5 no public
setters; AC-A1 Application services `final`; AC-A3 command-returns-void/query-returns-DTO;
AC-A6 no Request/Response in Application; AC-I3 doubles only in `when@test`; AC-P3/P4/P5
controller shape rules; AC-M1 `ROLE_MEMBER` gating ban; CQRS naming conventions.

## E. Doc ride-along (doc-only, exempt)

- `api/CLAUDE.md` "Known contexts": 13 listed vs 18 in `DddArchitectureValidator::CONTEXTS`
  (missing `Community`, `Legal`, `Membership`, `SessionConfig`, `WeeklyRuns`) - align to 18.
