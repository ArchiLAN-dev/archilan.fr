# Story 33.10 - Audit Worklist (layer sub-folder taxonomy migration map)

Scope of record (AC1). Every flat Application file of every in-scope context classified by
reading class declarations/constructors/method signatures (3 parallel audits, 2026-07-05,
develop = 5d5930a); ambiguous services were read in depth, 12 judgment calls resolved by the
consistency rulings below. `Sessions` (29 files + 2 Domain exceptions) DEFERRED until Epic 32;
`Legal` empty. Infrastructure/Presentation untouched by design.

## Consistency rulings (applied throughout)

R1. Mixed read/write facades stay FLAT (AdminGameLibrary, FriendshipService, NotificationService...).
R2. Non-query ports stay FLAT even when read-only (`GameUsageCounterInterface`) or abstracting a
    command (`ActivateMembershipInterface`, `ExpireMembershipInterface`, `ProcessHelloAssoMembershipPaymentInterface`).
R3. Query input filters + result records → `Query/` with their query (`ContributionQueryFilters`,
    `ReportQueryFilters`); command result records → `Command/` with their command (`RotationResult`).
R4. Read-only display services are Query even without the suffix (`MembershipCheckout`, `ShopCheckout`,
    `TwitchStatusChecker`, `CommunityDirectory`, `WeeklyEntrySessionCheck`); internal collaborators
    returning Domain objects stay FLAT (`SessionConfigResolver`, `GamePlatformResolver`, `AuthenticateUser`).
R5. Result records of FLAT helpers stay FLAT with their producer (`ApworldVersionInfo`).
R6. Media/storage facades stay FLAT (`AchievementImageService`, `CommunityAvatarService`).

## Batch 1 - pilots

### Identity (36: 16 Command, 10 Query, 10 FLAT)
- Command/: AdminChangeUserRole, AdminCreateAdminAccount, ChangeUserSlug, ConfirmEmail,
  CreatePrivacyRightsRequest, DeleteAccount, HandleDiscordAuthCallback, LinkDiscordToAccount,
  RegisterUser, RequestPasswordReset, ResendEmailConfirmation, ResetPassword, RotateRefreshToken,
  RotationResult (R3), SaveSteamAccount, SendEmailConfirmation, UnlinkDiscordFromAccount
  (17 files - RotationResult rides with RotateRefreshToken)
- Query/: AdminUserDirectory, DiscordBotStatusQueryInterface, DiscordUsersQueryInterface,
  MemberDisplayNameQueryInterface, PlayerHistoryQuery, PlayerHistoryQueryInterface,
  PlayerProfileQuery, PlayerStatsQueryInterface, UserDirectoryQueryInterface (9)
- FLAT: AuthSessionSigner, AuthenticateUser (R4), CurrentUserProvider, DiscordBotClientInterface,
  DiscordOAuthClientInterface, DiscordResyncAllUsersInterface (R2), DiscordStateToken,
  RefreshTokenFactory, SlugGenerator, ValidationErrors (10)

### GameSelection (31: 10 Command, 12 Query, 1 Exception, 8 FLAT)
- Command/: BackfillGameOptionTypes, BackfillGamePlatforms, BackfillSteamAppIds,
  ModerateGameTutorialContribution, SeedGameTutorials, SubmitGameTutorialContribution,
  UpdateArchipelagoClient, UpdateArchipelagoGuide, UploadTutorialImageCommand (9... +0; note: 9)
- Query/: AdminGameContributionsQueryInterface, AdminGameListQueryInterface, ArchipelagoClientQuery,
  ArchipelagoGuideQuery, ContributionQueryFilters (R3), GameCatalogQueryInterface,
  GameRequestListQueryInterface, MyGameTutorialContributionsQueryInterface, PublicGameCatalog,
  SteamCatalogQueryInterface, SteamLibraryCouplingQuery (11)
- Exception/: SteamApiException (Application)
- FLAT: AdminGameLibrary (R1), GameCatalogLinksProviderInterface, GamePlatformResolver (R4),
  GameRequests (R1), GameTutorialSeeder, GameUsageCounterInterface (R2), IgdbHttpClientInterface,
  InstallStepsNormalizer, InstallStepsReader, SteamWebApiClientInterface (10)

## Batch 2

### Community (45: 8 Command, 18 Query, 1 Exception, 18 FLAT)
- Command/: AdminAchievementGrantService, BackfillActivity, EvaluateAccountEscalation,
  RecomputeAchievements, RecordActivity, RefreshCommunityAvatars, ReportProfileService,
  UpdateCommunityProfile
- Query/: AccountReportScoreQueryInterface, AchievementRarityQueryInterface, AdminReportsQueryInterface,
  CommunityAdminIdsQueryInterface, CommunityDirectory (R4), CommunityDirectoryQueryInterface,
  CommunityFeedQuery, CommunityLevelQuery, CommunityPresenceQueryInterface,
  CommunityProfileQueryInterface, CommunityProfileView, CommunityUserContactsQueryInterface,
  CommunityUserDirectoryQueryInterface, CommunityUserIdsQueryInterface, EventCatalogueQueryInterface,
  EventParticipationQueryInterface, ReportQueryFilters (R3), + (17... recount at execution)
- Exception/: CannotKudosOwnContentException
- FLAT: AccountModerationService (R1), AchievementImageService (R6), AchievementImageUrlResolver,
  AchievementMetricProviderInterface, AdminAchievementService (R1), AvatarResolverInterface,
  AvatarUrlResolver, CommunityAvatarService (R6), EventParticipationMetricProvider, FriendshipService (R1),
  KudosService (R1), MemberModerationGatewayInterface, MetricBagBuilder, ModerationService (R1),
  NotificationService (R1), Notifier, ProfileCommentService (R1), ProfileVisibility (R4),
  StatsMetricProvider (19)
- Domain/Exception/: InvalidAchievementRuleException (from Community/Domain)

### WeeklyRuns (30: 9 Command, 19 Query, 2 FLAT)
- Command/: AdminCreateWeeklyTemplate, AdminDeactivateWeeklyTemplate, AdminUpdateWeeklyTemplate,
  GenerateWeeklyRunForTemplate, LaunchWeeklyEntry, MarkWeeklyRunGenerated, OptInToWeeklyRun,
  RecordWeeklyGoal, WithdrawFromWeeklyRun
- Query/: the 12 Admin*/Current*/WeeklyRun* query+interface pairs, WeeklyEntryPatchQuery,
  WeeklyRunEntriesQueryInterface, WeeklyRunSlotQuery, WeeklyEntrySessionCheck (R4) (19)
- FLAT: WeeklyRunGeneratorInterface, WeeklyRunnerGatewayInterface

### Membership (23: 8 Command, 10 Query, 5 FLAT)
- Command/: ActivateMembership, AdminCreateMembership, AdminDeleteMembership, AdminDolibarrResyncService,
  AdminEditMembership, AdminReconcileHelloAssoOrder, ExpireMembership, ProcessHelloAssoMembershipPayment
- Query/: AccountMembershipQuery(+Interface), ActiveMembershipQuery(+Interface),
  AdminMembershipListQuery(+Interface), AdminUnmatchedHelloAssoOrdersQuery(+Interface),
  MembershipAllIdsQueryInterface, MembershipExpiryCheckQueryInterface
- FLAT: DolibarrClientInterface, UserRoleGatewayInterface, ActivateMembershipInterface (R2),
  ExpireMembershipInterface (R2), ProcessHelloAssoMembershipPaymentInterface (R2)

### Registrations (16: 6 Command, 9 Query, 1 FLAT)
- Command/: AdminRegistrationCancellation, AdminRegistrationModification, RegistrationCancellation,
  RegistrationSubmission, ReserveRegistration, SendMessageToRegistrant
- Query/: AccountRegistrationsQuery(+Interface), AdminRegistrationDashboard, AdminRegistrationExporter,
  AdminRegistrationInspector, MyRegistrationQuery, PrivateAccessGrantedQueryInterface,
  RegistrationCounter, RegistrationCounterQueryInterface
- FLAT: RegistrationGameSelection (R1)

### CatalogSync (11: 3 Command, 4 Query, 1 Exception, 3 FLAT)
- Command/: CheckApworldUpdatesService (flushes - verified), IgnoreCatalogEntryCommand, UnignoreCatalogEntryCommand
- Query/: CatalogSyncStatusQuery, ImportedCatalogNamesQueryInterface, PublicCatalogGamesQuery, PublicGameDetailQuery
- Exception/: GithubRateLimitException
- FLAT: ApworldVersionChecker, ApworldVersionInfo (R5), CatalogSyncService (R1)

### Events (10: 4 Command, 4 Query, 2 FLAT)
- Command/: AdminEventRecap (saves - verified), ManageEventGalleryCommand, UploadEventCoverImageCommand,
  VerifyPrivateEventAccess (persists access log - verified)
- Query/: AdminDashboardStats, DashboardStatsQueryInterface, PublicEventCatalog, RegistrationEligibility
- FLAT: AdminEventDrafts (R1), AdminEventGameSelection (R1)

## Batch 3

### PersonalRuns (9): Command/: PersonalRunGameConfig, PersonalRunLifecycle. Query/: PersonalRunPatchQuery,
PersonalRunSpoilerDownload, RecentlyPlayedGamesQueryInterface. FLAT: PersonalRunConfigOverride (R1),
PersonalRunDrafts (R1), PersonalRunGameSelection (R1), PersonalRunYamlTemplates (R1).
### Payments (8): Command/: HandleHelloAssoWebhook, TriggerHelloAssoSync. Query/: AdminHelloAssoSyncStatus,
HelloAssoPaymentLookup, MembershipCheckout (R4), ShopCheckout (R4). FLAT: HelloAssoClientInterface, HelloAssoConfig.
### SessionConfig (6): Command/: AdminUpdateSessionConfig, ClearSessionConfigOverride, SetSessionConfigOverride.
Query/: AdminSessionConfigQuery, SessionConfigOverrideQuery. FLAT: SessionConfigResolver (R4).
### Streaming (5): Query/: OverlaySubscribeQuery, ParticipantStreamsView, ParticipantTwitchLinksQueryInterface,
TwitchStatusChecker (R4). FLAT: TwitchApiClientInterface.
### Shared (4): FLAT all four (DddArchitectureValidator + DddArchitectureReport = the gate tooling itself,
PaginationHelper, SlotYamlNameReader). Zero moves - allowlist removal only.
### Content (3): Command/: UploadPostCoverImageCommand. Query/: PublicPostCatalog. FLAT: AdminPostCatalog (R1).
### Communications (2): FLAT both (ArchilanMailer - 33.5 precedent; ArchilanEmail abstract base). Zero moves.
### Realtime (1): FLAT (RealtimePublisher - publishing facade). Zero moves.

## Exceptions inventory (8)
- Move now: GameSelection/Application/SteamApiException, CatalogSync/Application/GithubRateLimitException,
  Community/Application/CannotKudosOwnContentException → Application/Exception/;
  Community/Domain/InvalidAchievementRuleException → Domain/Exception/.
- STAY: GameSelection/Infrastructure/{IgdbAuthException, IgdbSearchException} (Infrastructure untouched).
- DEFERRED (Sessions, TODO epic-32): Sessions/Domain/{SessionNotFoundException, SessionNotRunningException}.

## Totals
~125 file moves (Command ~55, Query ~65 incl. interfaces/DTOs, Exceptions 4), ~65 deliberate FLATs,
3 zero-move contexts (allowlist removal only), Sessions deferred.
