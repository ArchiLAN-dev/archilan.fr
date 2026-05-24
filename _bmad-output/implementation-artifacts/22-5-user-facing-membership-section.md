# Story 22.5: User-Facing Membership Section

## Story

**As an** authenticated user,
**I want** to see my current membership status in my account profile and access the HelloAsso checkout to subscribe or renew,
**So that** I can manage my membership entirely from the ArchiLAN site.

## Status

done

## Acceptance Criteria

**AC1:** `GET /api/v1/account/membership` returns `200 { data: { status: 'active'|'expired'|'none', expiresAt: string|null, startedAt: string|null } }` for authenticated users. Unauthenticated requests receive `401`.

**AC2:** Active membership: "Adhésion active" badge + expiry date + "Renouveler" link to HelloAsso form.

**AC3:** No or expired membership: "Aucune adhésion active" + HelloAsso checkout link. If previously expired, past expiry date shown.

**AC4:** The checkout URL comes from `getMembershipCheckoutUrl()` - no new env variable, no direct `process.env`.

**AC5:** `pnpm typecheck`, `pnpm lint`, `pnpm build` clean. All four API quality gates pass.

## Tasks / Subtasks

- [x] Task 1: Create `AccountMembershipQuery` application service (DBAL)
- [x] Task 2: Create `AccountMembershipController` (`GET /api/v1/account/membership`)
- [x] Task 3: Write API functional test and run all 4 quality gates
- [x] Task 4: Add `getAccountMembership()` to `membership-api.ts`
- [x] Task 5: Create `MembershipSection` client component using TanStack Query
- [x] Task 6: Add "Adhésion" tab to `account-tabs.tsx` and run frontend quality gates

## Dev Notes

### API - AccountMembershipQuery

Two DBAL queries (no raw SQL, no DQL):
1. Find active membership for userId → return `status: 'active'`
2. If not found, find most recent membership for userId → return `status: 'expired'` or `status: 'none'`

Dates returned as ATOM format (parse raw DBAL string with `new \DateTimeImmutable()`, format with `\DateTimeInterface::ATOM`).

### API - Controller

- Namespace: `App\Membership\Presentation`
- Uses `RequiresAuthTrait` (inject `ApiAccessGuard`)
- Route: `#[Route('/api/v1/account/membership', name: 'api_membership_account_status', methods: ['GET'])]`
- Returns `{ data: {...}, meta: [] }`

### Frontend - MembershipSection

- `"use client"` component in `features/auth/membership-section.tsx`
- Uses `useQuery` for membership data (queryKey: `['account-membership']`)
- Uses separate `useQuery` for checkout URL (queryKey: `['membership-checkout-url']`)
- `staleTime: DEFAULT_STALE_TIME` on both queries
- No `useEffect`, no `process.env`

### Frontend - Tab placement

New tab "Adhésion" inserted between "Mes parties" and "Confidentialité".

## File List

### API
- `api/src/Membership/Application/AccountMembershipQuery.php` - DBAL read query (active → expired → none)
- `api/src/Membership/Presentation/AccountMembershipController.php` - `GET /api/v1/account/membership`
- `api/tests/Functional/AccountMembershipTest.php` - 4 functional tests

### Frontend
- `frontend/src/features/payments/membership-api.ts` - added `MembershipStatus` type, `getAccountMembership()`, `isMembershipStatusPayload()` guard
- `frontend/src/features/auth/membership-section.tsx` - `"use client"` component with two `useQuery` calls
- `frontend/src/features/auth/account-tabs.tsx` - added `"adhesion"` tab between "Mes parties" and "Confidentialité"

## Change Log

| Date       | Change        |
|------------|---------------|
| 2026-05-16 | Story created |
| 2026-05-16 | All tasks complete - API + frontend, all quality gates green |
