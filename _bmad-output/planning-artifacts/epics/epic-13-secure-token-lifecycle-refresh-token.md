# Epic 13: Secure Token Lifecycle - Refresh Token

The authentication system is upgraded from a single long-lived JWT cookie to a short-lived access token + long-lived refresh token pair, both httpOnly Secure SameSite cookies. The API handles token rotation with reuse detection; the frontend silently refreshes expired sessions without user action.

## Story 13.1: Refresh Token Domain Model and Storage

As a system,
I want to persist refresh tokens server-side with revocation support,
So that tokens can be validated, rotated, and invalidated individually.

**Acceptance Criteria:**

**Given** the database migration runs
**When** the schema is applied
**Then** a `refresh_tokens` table exists with columns: `id`, `user_id` (FK), `token_hash` (SHA-256 of raw token), `expires_at`, `revoked_at` (nullable), `created_at`, `user_agent` (nullable)
**And** an index exists on `(user_id, revoked_at)` for efficient lookups
**And** a `RefreshToken` Doctrine entity and repository are implemented in the `Identity` domain
**And** the repository exposes: `findByTokenHash(string): ?RefreshToken`, `revokeByUser(UserId): void`, `deleteExpiredBefore(DateTimeImmutable): int`
**And** the raw token value (64 random bytes, base64url-encoded) is never stored; only its SHA-256 hash persists

## Story 13.2: Dual-Cookie Token Issuance on Authentication

As a registered user,
I want login to issue both a short-lived access token and a long-lived refresh token,
So that my session stays active without re-entering credentials frequently.

**Acceptance Criteria:**

**Given** valid credentials are submitted to `POST /auth/login`
**When** the response is sent
**Then** two httpOnly Secure SameSite=Lax cookies are set: `access_token` (JWT, TTL 15 minutes) and `refresh_token` (opaque, TTL 30 days)
**And** the raw refresh token is hashed (SHA-256) before being persisted in `refresh_tokens`
**And** the `access_token` cookie has `Path=/` and the `refresh_token` cookie has `Path=/auth/refresh` to minimise exposure
**And** no token value is returned in the response body
**And** existing login behaviour for invalid credentials and CSRF is unchanged

## Story 13.3: Refresh Endpoint with Token Rotation and Reuse Detection

As an authenticated client,
I want a dedicated endpoint to exchange a valid refresh token for a new token pair,
So that my session extends transparently without storing credentials.

**Acceptance Criteria:**

**Given** a valid, non-revoked refresh token cookie is present
**When** `POST /auth/refresh` is called
**Then** the existing refresh token is revoked (sets `revoked_at`)
**And** a new access token and refresh token pair is issued in cookies (same cookie attributes as Story 13.2)
**And** the new refresh token replaces the old one in `refresh_tokens`
**And** the response body is empty with status 204

**Given** an expired or absent refresh token cookie
**When** `POST /auth/refresh` is called
**Then** the response is 401 with a generic `invalid_refresh_token` error code
**And** both cookies are cleared

**Given** a refresh token that has already been revoked (reuse attack scenario)
**When** `POST /auth/refresh` is called
**Then** all refresh tokens for the associated user are immediately revoked
**And** the response is 401 with `token_reuse_detected` error code
**And** the security event is logged with user ID and request metadata

## Story 13.4: Frontend Silent Refresh Interceptor

As a user with an expired access token,
I want the frontend to transparently refresh my session,
So that I am not interrupted mid-action by an unexpected logout.

**Acceptance Criteria:**

**Given** the fetch utility used across the frontend is centralised
**When** any API call returns 401
**Then** the interceptor calls `POST /auth/refresh` once
**And** if refresh succeeds (204), the original request is retried automatically
**And** if refresh fails (401), the authenticated client state is cleared and the user is redirected to `/connexion` with the current path as `?next=` query param
**And** requests to `/auth/refresh` itself are never retried to avoid infinite loops
**And** concurrent 401 responses during a single refresh trigger only one refresh call (queued retry pattern)

## Story 13.5: Logout with Server-Side Token Revocation

As an authenticated user,
I want logout to invalidate my refresh token server-side,
So that stolen cookies cannot be used to obtain new tokens after I sign out.

**Acceptance Criteria:**

**Given** a user is authenticated with a valid refresh token cookie
**When** `POST /auth/logout` is called
**Then** the refresh token identified by the cookie is revoked in the database
**And** both `access_token` and `refresh_token` cookies are cleared (Set-Cookie with `Max-Age=0`)
**And** the response is 204 regardless of whether the token was found (idempotent)
**And** subsequent calls to `/auth/refresh` with the old cookie return 401

## Story 13.6: Expired Token Cleanup Command

As an operator,
I want a Symfony console command to prune stale refresh token records,
So that the `refresh_tokens` table does not grow indefinitely.

**Acceptance Criteria:**

**Given** the command `app:auth:cleanup-refresh-tokens` exists
**When** it is executed
**Then** all rows where `expires_at < now()` OR (`revoked_at IS NOT NULL` AND `revoked_at < now() - 7 days`) are deleted
**And** the number of deleted rows is logged at `info` level via `LoggerInterface`
**And** the command is safe to run in production under concurrent load (DELETE with WHERE, no full-table lock)
**And** a cron entry is documented in `docker-compose.yml` or a Symfony Scheduler message to run daily

---
