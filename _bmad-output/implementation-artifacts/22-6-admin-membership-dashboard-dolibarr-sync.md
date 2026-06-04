# Story 22.6: Admin Membership Dashboard & Dolibarr Sync

## Story

**As an** admin,
**I want** a membership management dashboard with manual create and automatic Dolibarr synchronisation,
**So that** I can monitor membership state, correct edge cases, and keep the association ERP up to date.

## Status

done

## Acceptance Criteria

**AC1:** `GET /api/v1/admin/memberships?page=1&limit=50&status=active&search=jean` returns `200 { data: [...], meta: { page, limit, total } }`. Each entry contains `id`, `userId`, `email`, `displayName`, `status`, `startedAt`, `expiresAt`, `source`, `helloassoOrderId`, `adminNote`. Non-admin returns `403`.

**AC2:** `POST /api/v1/admin/memberships` with `{ userId, startedAt?, adminNote? }` calls `ActivateMembership::activate()`, returns `201` with the created membership payload. Non-admin returns `403`.

**AC3:** `POST /api/v1/admin/memberships/dolibarr/resync` dispatches `SyncMemberToDolibarrMessage` for every membership row, returns `202 { data: { queued: N } }`. Non-admin returns `403`.

**AC4:** `ActivateMembership` and `ExpireMembership` dispatch `SyncMemberToDolibarrMessage` after successful flush.

**AC5:** `SyncMemberToDolibarrMessageHandler` calls `DolibarrClientInterface::upsertMember(email, displayName, status, expiresAt)`. On failure: catch, log error, rethrow for Messenger retry. Success: log info.

**AC6:** `DOLIBARR_API_URL=` and `DOLIBARR_API_KEY=` added to `api/.env`.

**AC7:** Admin dashboard includes "Adhésions" tile. Frontend membership list page at `/admin/adhesions` shows searchable/filterable table with status badges and a "Créer" dialog.

**AC8:** `pnpm typecheck`, `pnpm lint`, `pnpm build` clean. All four API quality gates pass.

## Tasks / Subtasks

- [x] Task 1: Create `DolibarrClientInterface` (Application), `DolibarrClient` (Infrastructure), `NullDolibarrClient` (Infrastructure)
- [x] Task 2: Create `SyncMemberToDolibarrMessage` and `SyncMemberToDolibarrMessageHandler`
- [x] Task 3: Update `ActivateMembership` and `ExpireMembership` to dispatch `SyncMemberToDolibarrMessage`
- [x] Task 4: Create `AdminMembershipListQuery`, `AdminCreateMembership` facade, `AdminDolibarrResyncService`
- [x] Task 5: Create admin controllers (`AdminMembershipListController`, `AdminCreateMembershipController`, `AdminDolibarrResyncController`)
- [x] Task 6: Wire `services.yaml`, `messenger.yaml`, `.env`; write unit + functional tests; run all 4 API quality gates
- [x] Task 7: Create frontend admin membership page and update admin dashboard

## Dev Notes

### API - DolibarrClientInterface

Lives in `Membership/Application/` (following `DiscordBotClientInterface` in `Identity/Application/`).

```php
interface DolibarrClientInterface {
    public function upsertMember(string $email, string $displayName, string $status, ?\DateTimeImmutable $expiresAt): void;
}
```

`DolibarrClient` uses Symfony `HttpClientInterface`. Searches member by email first, then creates or updates.
`NullDolibarrClient` is a no-op stub registered under `when@test:`.

### API - SyncMemberToDolibarrMessage

Carries `membershipId: string`. Handler does a DBAL JOIN to fetch email, displayName, status, expiresAt.

### API - AdminMembershipListQuery

DBAL query with JOINs on `memberships` + `user` table. Supports `page`, `limit`, `status` filter, `search` (email/displayName).

### API - AdminCreateMembership (facade)

Injects `ActivateMembershipInterface` + `Connection`. Calls `activate()` then queries the resulting row.

### API - Admin Controllers

Namespace: `App\Membership\Presentation\Admin\`. All use `requireAuthenticatedAdmin()`.

### Frontend - Admin page

New route at `frontend/src/app/(admin)/admin/adhesions/page.tsx`. Component in `frontend/src/features/admin/admin-membership-dashboard.tsx`.

## File List

### API - New files
- `api/src/Membership/Application/DolibarrClientInterface.php`
- `api/src/Membership/Infrastructure/DolibarrClient.php`
- `api/src/Membership/Infrastructure/NullDolibarrClient.php`
- `api/src/Membership/Application/Message/SyncMemberToDolibarrMessage.php`
- `api/src/Membership/Application/Handler/SyncMemberToDolibarrMessageHandler.php`
- `api/src/Membership/Application/AdminMembershipListQuery.php`
- `api/src/Membership/Application/AdminCreateMembership.php`
- `api/src/Membership/Application/AdminDolibarrResyncService.php`
- `api/src/Membership/Presentation/Admin/AdminMembershipListController.php`
- `api/src/Membership/Presentation/Admin/AdminCreateMembershipController.php`
- `api/src/Membership/Presentation/Admin/AdminDolibarrResyncController.php`
- `api/tests/Unit/Membership/SyncMemberToDolibarrMessageHandlerTest.php`
- `api/tests/Functional/AdminMembershipListTest.php`
- `api/tests/Functional/AdminCreateMembershipTest.php`
- `api/tests/Functional/AdminDolibarrResyncTest.php`

### API - Modified files
- `api/src/Membership/Application/ActivateMembership.php` - dispatch `SyncMemberToDolibarrMessage`
- `api/src/Membership/Application/ExpireMembership.php` - dispatch `SyncMemberToDolibarrMessage`
- `api/config/services.yaml` - bind DolibarrClientInterface, NullDolibarrClient for tests
- `api/config/packages/messenger.yaml` - add SyncMemberToDolibarrMessage routing
- `api/.env` - add DOLIBARR_API_URL and DOLIBARR_API_KEY
- `api/tests/Unit/Membership/ActivateMembershipTest.php` - updated dispatch counts
- `api/tests/Unit/Membership/ExpireMembershipTest.php` - updated dispatch counts

### Frontend - New files
- `frontend/src/features/admin/admin-membership-api.ts`
- `frontend/src/features/admin/admin-membership-dashboard.tsx`
- `frontend/src/app/(admin)/admin/adhesions/page.tsx`

### Frontend - Modified files
- `frontend/src/app/(admin)/admin/page.tsx` - added "Adhésions" section tile

## Change Log

| Date       | Change        |
|------------|---------------|
| 2026-05-16 | Story created |
| 2026-05-16 | All tasks complete - API + frontend, all quality gates green (844 tests) |
