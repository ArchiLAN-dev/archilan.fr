# Epic 22: Membership Management - Adhésions & Sync Dolibarr

Users can subscribe and renew their ArchiLAN membership autonomously via HelloAsso. The system handles role transitions, expiry reminders, Discord sync, and Dolibarr push automatically. Admins retain a full dashboard and manual override.

## Story 22.1: Membership Domain Model & Core Application Services

As a developer,
I want a `Membership` bounded context with an entity, two command services (`ActivateMembership`, `ExpireMembership`), and Discord sync dispatch,
So that membership lifecycle transitions are encapsulated in a single place and can be triggered from multiple entry points (HelloAsso webhook, scheduler, admin action).

**Context:**
A new `Membership` bounded context is created alongside `Identity`, `Events`, etc. `Membership` owns everything related to the cotisation lifecycle. The `Membership` entity is the only aggregate; it holds no Symfony dependencies. `ActivateMembership` and `ExpireMembership` are the two write services.

To avoid a direct `Membership/Application` → `Identity/Domain/User` cross-context import, a `UserRoleGatewayInterface` is defined in `Membership/Application/` with two methods (`promoteToMember(string $userId): void`, `demoteToUser(string $userId): void`). Its concrete implementation `UserRoleGateway` lives in `Membership/Infrastructure/` and is the only class allowed to import `Identity\Domain\User`. The services update the `Membership` entity and call the gateway, flush both in one unit of work (NFR-ME4), then dispatch `SyncDiscordRoleMessage` (from Epic 21) after the successful flush. Later stories (22.4, 22.6) add further dispatches additively. `DddArchitectureValidator::CONTEXTS` and `services.yaml` Domain exclusions must be updated for the new context.

**Acceptance Criteria:**

**Given** the monorepo has existing bounded contexts
**When** story 22.1 begins
**Then** the directories `src/Membership/{Domain,Application,Infrastructure,Presentation}/` are created
**And** `DddArchitectureValidator::CONTEXTS` includes `'Membership'`
**And** `services.yaml` excludes `App\Membership\Domain\` from autowiring (consistent with other Domain exclusions)
**And** Doctrine mapping is configured for `src/Membership/Domain/`

**Given** the bounded context is set up
**When** the entity and migration are created
**Then** `src/Membership/Domain/Membership.php` is a `final` entity with ORM annotations and fields: `id` (UUID string), `userId` (string, NOT NULL), `helloassoOrderId` (varchar 100, nullable, unique), `startedAt` (datetime_immutable), `expiresAt` (datetime_immutable), `status` (`varchar 10`, `'active'|'expired'`), `source` (`varchar 20`, `'helloasso'|'admin'`), `adminNote` (text nullable), `reminder30SentAt` (datetime_immutable nullable), `reminder7SentAt` (datetime_immutable nullable), `createdAt`, `updatedAt`
**And** a Doctrine migration adds the `memberships` table with indexes on `(user_id)`, `(expires_at, status)`, `(status, user_id)`, and a unique constraint on `helloasso_order_id`
**And** `ActivateMembership` enforces one active membership per `userId` by updating the existing active row when present instead of creating a second active membership

**Given** `src/Membership/Application/UserRoleGatewayInterface.php` exists
**When** story 22.1 begins
**Then** the interface declares `promoteToMember(string $userId): void` and `demoteToUser(string $userId): void`
**And** `src/Membership/Infrastructure/UserRoleGateway.php` implements it by loading `Identity\Domain\User` via `EntityManagerInterface` and calling the domain method
**And** `UserRoleGatewayInterface` is bound to `UserRoleGateway` in `services.yaml`

**Given** the entity exists
**When** `ActivateMembership::activate(string $userId, \DateTimeImmutable $startedAt, string $source, ?string $helloassoOrderId = null, ?string $adminNote = null): void` is called
**Then** it finds an existing active membership for the user and renews it to `max(existing expiresAt, $startedAt) + 12 months`, or creates a new `Membership` with `$startedAt + 12 months` if none exists
**And** calls `$this->userRoleGateway->promoteToMember($userId)` (the gateway handles the `ROLE_ADMIN` guard internally)
**And** flushes `Membership` entity in one `EntityManagerInterface::flush()` call (the gateway flushes `User` inside its own call, sharing the same `EntityManager` instance - same unit of work)
**And** dispatches `SyncDiscordRoleMessage` after flush with the user's updated roles

**Given** the entity exists
**When** `ExpireMembership::expire(string $membershipId): void` is called
**Then** it no-ops if the membership is already expired
**And** otherwise sets `$membership->status = 'expired'` and calls `$this->userRoleGateway->demoteToUser($userId)` (the gateway guards `ROLE_MEMBER` internally)
**And** flushes both in the same unit of work
**And** dispatches `SyncDiscordRoleMessage` after flush

**And** all four quality gates pass

---

## Story 22.2: HelloAsso Payment to Membership Activation

As a user who pays their cotisation via HelloAsso,
I want my membership to be created or renewed automatically without any manual step,
So that I can access member-only features immediately after payment.

**Context:**
The HelloAsso sync adapter from Epic 6 (Story 6.5) already pulls order data into `Payments\Domain\HelloAssoOrder`. To avoid a `Payments → Membership` cross-context import, the `Payments` context dispatches a generic `HelloAssoOrderPaidMessage(helloassoOrderId, formType, payerEmail, paidAt)` when a membership-form order transitions into a paid state. `HelloAssoOrderPaidMessage` lives in `Payments/Application/Message/` - `Payments` imports nothing from `Membership`. The `Membership` context subscribes to this message via `HelloAssoOrderPaidMessageHandler` (in `Membership/Application/Handler/`), which filters for the configured membership form type and calls `ProcessHelloAssoMembershipPayment`. This decouples the two contexts through an event.

**Acceptance Criteria:**

**Given** a `HelloAssoOrder` for the membership form is newly inserted or transitions into a paid state
**When** the HelloAsso sync pipeline flushes successfully
**Then** `HelloAssoOrderPaidMessage(helloassoOrderId, formType: 'membership', payerEmail, paidAt)` is dispatched to the async bus exactly once for that paid transition
**And** `Payments/` imports nothing from `Membership/` - the message type is defined in `Payments/Application/Message/`

**Given** `HelloAssoOrderPaidMessageHandler` (in `Membership/`) processes the message
**When** `formType` matches `HELLOASSO_MEMBERSHIP_FORM_SLUG`
**Then** `ProcessHelloAssoMembershipPayment::process(helloassoOrderId, payerEmail, paidAt)` is called

**Given** `ProcessHelloAssoMembershipPayment::process(string $helloassoOrderId): void` is called
**When** the order exists, belongs to the membership form, has a paid status, and has a non-null payer email and paid date
**Then** it looks up the ArchiLAN user by email; if no matching user is found, it logs a warning at `warning` level with the email and order ID, then returns without error
**And** it calls `ActivateMembership::activate($userId, $paidAt, 'helloasso', $helloassoOrderId)`

**Given** the order does not exist, is not paid, is not for the membership form, has no payer email, or has no paid date
**When** `process()` runs
**Then** it logs at `info` or `warning` level as appropriate and returns without changing membership state

**Given** the same `helloassoOrderId` is processed twice
**When** `activate()` triggers a `UniqueConstraintViolationException`
**Then** the exception is caught, a log entry is written at `info` level (`membership.already_processed`), and the method returns - no duplicate membership, no role change, no error response

**Given** `api/.env`
**When** story 22.2 is complete
**Then** `HELLOASSO_MEMBERSHIP_FORM_SLUG=` is added and documented as the HelloAsso form slug for membership payments

**And** all four quality gates pass

---

## Story 22.3: Symfony Scheduler - Daily Expiry & Reminder Dispatch

As the system,
I want a daily scheduled task that detects expired memberships and dispatches expiry and reminder messages for each,
So that role demotion and email notifications happen automatically without any manual intervention.

**Context:**
The existing central `src/Schedule.php` provider dispatches `CheckMembershipExpiryMessage` daily at 00:05 UTC. The handler uses a DBAL query (not EntityManager) to find: (1) active memberships where `expires_at <= NOW()` - dispatches `ExpireMembershipMessage(membershipId)` for each; (2) active memberships expiring within a 30-day window (between `NOW() + 29 days 23h` and `NOW() + 30 days 1h`) and `reminder_30_sent_at IS NULL` - dispatches `MembershipReminderMessage(membershipId, 30)` and marks `reminder_30_sent_at`; (3) same for the 7-day window using `reminder_7_sent_at`. `ExpireMembershipMessageHandler` calls `ExpireMembership::expire()`. `MembershipReminderMessageHandler` is introduced in this story as a no-op handler that logs and returns; Story 22.4 replaces it with the real email sender so messages are always handleable.

**Acceptance Criteria:**

**Given** Symfony Scheduler is configured
**When** story 22.3 is complete
**Then** `src/Schedule.php` includes a `RecurringMessage::cron('5 0 * * *', new CheckMembershipExpiryMessage())` entry on the default schedule

**Given** `CheckMembershipExpiryMessageHandler` runs
**When** active memberships exist with `expires_at <= NOW()`
**Then** one `ExpireMembershipMessage(membershipId)` is dispatched per expired membership to the async bus

**Given** `ExpireMembershipMessageHandler` processes a message
**When** the membership is found and `status = 'active'`
**Then** `ExpireMembership::expire(membershipId)` is called successfully

**Given** active memberships expire in 30 days (within the window)
**When** `CheckMembershipExpiryMessageHandler` runs
**Then** `reminder_30_sent_at` is written and flushed **before** dispatching `MembershipReminderMessage(membershipId, daysLeft: 30)`
**And** the dispatch happens only after the flush succeeds
**And** this order guarantees that at worst a reminder is skipped (if the dispatch crashes), never sent twice

**Given** active memberships expire in 7 days (within the window)
**When** the handler runs
**Then** `reminder_7_sent_at` is written and flushed **before** dispatching `MembershipReminderMessage(membershipId, daysLeft: 7)`
**And** same ordering guarantee applies

**Given** no memberships match any condition
**When** the handler runs
**Then** no messages are dispatched and the handler exits with no errors

**And** all four quality gates pass

---

## Story 22.4: Email Notification Pipeline

As a member,
I want to receive emails on membership activation, 30 days before expiry, 7 days before expiry, and on the day of expiry,
So that I am never surprised by a loss of member access.

**Context:**
`ActivateMembership` (22.1) is updated to dispatch `MembershipActivatedNotificationMessage(userId, expiresAt)` after successful flush. `ExpireMembership` (22.1) is updated to dispatch `MembershipExpiredNotificationMessage(userId)` after successful flush. The no-op `MembershipReminderMessageHandler` from 22.3 is replaced by the real email sender here. All handlers look up the user email via DBAL, build the email with `MailerInterface`, and send. Templates use Symfony Mailer with Twig; styling follows the existing email design. On send failure, handlers log at `error` level with message context and rethrow so Messenger retries according to the configured transport policy; the already-flushed role change is not rolled back.

**Acceptance Criteria:**

**Given** `ActivateMembership::activate()` flushes successfully
**When** `MembershipActivatedNotificationMessage` is processed
**Then** an email is sent to the user with subject "Bienvenue chez ArchiLAN - Adhésion activée", expiry date formatted as `DD/MM/YYYY`, and a link to their account profile

**Given** `MembershipReminderMessage(membershipId, daysLeft: 30)` is processed
**When** the handler runs
**Then** an email is sent with subject "Votre adhésion ArchiLAN expire dans 30 jours" and a direct link to the HelloAsso membership form

**Given** `MembershipReminderMessage(membershipId, daysLeft: 7)` is processed
**When** the handler runs
**Then** an email is sent with subject "Plus que 7 jours - renouvelez votre adhésion ArchiLAN" and the renewal link

**Given** `ExpireMembership::expire()` flushes successfully
**When** `MembershipExpiredNotificationMessage` is processed
**Then** an email is sent with subject "Votre adhésion ArchiLAN a expiré" informing the user their member access has been removed and providing the renewal link

**Given** any handler catches an SMTP error or cannot find the target user in DBAL
**When** the email cannot be sent
**Then** the failure is logged at `error` level with `membershipId` or `userId` context before the exception is rethrown for Messenger retry handling
**And** the role transition already committed is not rolled back (email failure is non-critical)

**And** all four quality gates pass

---

## Story 22.5: User-Facing Membership Section

As an authenticated user,
I want to see my current membership status in my account profile and access the HelloAsso checkout to subscribe or renew,
So that I can manage my membership entirely from the ArchiLAN site.

**Context:**
A new "Adhésion" section is added to the existing account tabs (`account-tabs.tsx`). The API exposes `GET /api/v1/account/membership`. The frontend follows AGENTS.md: a server component or TanStack Query loads the data; no `useEffect` for data fetching. The HelloAsso checkout URL is fetched through the existing `GET /api/v1/payments/membership/checkout` API and `features/payments/membership-api.ts`, avoiding a second frontend env source for the same form URL.

**Acceptance Criteria:**

**Given** an authenticated user
**When** `GET /api/v1/account/membership` is called
**Then** the response is `200 { data: { status: 'active'|'expired'|'none', expiresAt: string|null, startedAt: string|null } }`
**And** unauthenticated requests receive `401`

**Given** the user has an active membership
**When** they visit the "Adhésion" tab in their account profile
**Then** they see a status badge "Adhésion active", the expiry date, and a "Renouveler" link to the HelloAsso form

**Given** the user has no membership or an expired one
**When** they visit the section
**Then** they see "Aucune adhésion active" and a prominent HelloAsso checkout link to subscribe
**And** if previously expired, the past expiry date is shown for context

**Given** the membership checkout URL is rendered
**When** the account section needs the subscribe or renew link
**Then** it reuses `getMembershipCheckoutUrl()` from `features/payments/membership-api.ts`
**And** no new `NEXT_PUBLIC_HELLOASSO_MEMBERSHIP_FORM_URL` variable is introduced
**And** `process.env` is not accessed directly anywhere in the component tree

**And** `pnpm typecheck`, `pnpm lint`, and `pnpm build` are clean
**And** all four API quality gates pass

---

## Story 22.6: Admin Membership Dashboard & Dolibarr Sync

As an admin,
I want a membership management dashboard with manual create and automatic Dolibarr synchronisation,
So that I can monitor membership state, correct edge cases, and keep the association ERP up to date.

**Context:**
Three endpoints are added under `Membership/Presentation/Admin/`. Controllers delegate to Application services only. `DolibarrClientInterface` lives in `Membership/Infrastructure/` - consistent with `DiscordBotClientInterface` and `DiscordOAuthClientInterface` (existing Infrastructure convention). It exposes `upsertMember(string $email, string $displayName, string $status, ?\DateTimeImmutable $expiresAt): void`. `DolibarrClient` (real HTTP impl) and `NullDolibarrClient` (no-op stub) also live in `Membership/Infrastructure/`, with the null implementation registered under `when@test:`. `ActivateMembership` and `ExpireMembership` (22.1) are each updated to dispatch `SyncMemberToDolibarrMessage` after successful flush. The handler calls `DolibarrClientInterface::upsertMember()`; failures are caught, logged, and rethrown for Messenger retry without affecting the ArchiLAN membership record (NFR-ME2). This story also adds a Dolibarr bulk resync endpoint/service so memberships created before Dolibarr configuration can be pushed later.

**Acceptance Criteria:**

**Given** an admin is authenticated
**When** `GET /api/v1/admin/memberships?page=1&limit=50&status=active&search=jean` is called
**Then** the response is `200` with `{ data: [...], meta: { page: 1, limit: 50, total: int } }`
**And** memberships are filtered by `status` and searched by `email` or `displayName`
**And** each entry contains `id`, `userId`, `email`, `displayName`, `status`, `startedAt`, `expiresAt`, `source`, `helloassoOrderId`, `adminNote`

**Given** an admin is authenticated
**When** `POST /api/v1/admin/memberships` is called with `{ userId: string, startedAt?: string, adminNote?: string }`
**Then** `ActivateMembership::activate($userId, startedAt ?? now(), 'admin', null, $adminNote)` is called
**And** the response is `201` with the created membership payload

**Given** an admin is authenticated
**When** `POST /api/v1/admin/memberships/dolibarr/resync` is called
**Then** `SyncMemberToDolibarrMessage` is dispatched for every membership row
**And** the response is `202` with `{ data: { queued: N } }`

**Given** a non-admin user
**When** any admin endpoint is called
**Then** the response is `403 Forbidden`

**Given** `ActivateMembership` or `ExpireMembership` flushes successfully
**When** `SyncMemberToDolibarrMessage` is processed
**Then** `DolibarrClientInterface::upsertMember()` is called with the user's email, displayName, status, and expiresAt
**And** success is logged at `info` level
**And** on failure the exception is caught, logged at `error` level, and rethrown for Messenger retry - the ArchiLAN membership is unaffected

**Given** the admin visits `/admin/memberships`
**When** the page loads
**Then** a searchable, filterable table shows all memberships with status badges (active in green, expired in muted)
**And** a "Créer une adhésion" button opens a dialog with a user email search field and optional note field, submits to `POST /api/v1/admin/memberships`, and refreshes the table on success
**And** the admin sidebar navigation includes an "Adhésions" entry pointing to `/admin/memberships`

**Given** `api/.env`
**When** story 22.6 is complete
**Then** `DOLIBARR_API_URL=` and `DOLIBARR_API_KEY=` are added and documented

**And** `pnpm typecheck`, `pnpm lint`, and `pnpm build` are clean
**And** all four API quality gates pass

---
