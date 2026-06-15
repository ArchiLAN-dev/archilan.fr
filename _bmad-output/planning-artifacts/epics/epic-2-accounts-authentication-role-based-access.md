# Epic 2: Accounts, Authentication & Role-Based Access

Visitors can create accounts, authenticated users can manage their profiles and data rights, and admins can manage users and roles.

## Story 2.1: Lambda Account Registration

As a visitor,
I want to create a lambda account with email and password,
So that I can register for public events.

**Acceptance Criteria:**

**Given** the public signup page is available
**When** a visitor submits a valid email and password
**Then** a lambda user account is created with no member/admin privileges
**And** the password is hashed with the configured secure hasher
**And** duplicate email registration is rejected with a field-level error
**And** CGU acceptance is required during account creation
**And** the signup form has labels, linked validation errors, and keyboard support

## Story 2.2: Login, Logout and Authenticated Session

As a registered user,
I want to log in and out securely,
So that I can access authenticated features.

**Acceptance Criteria:**

**Given** a lambda account exists
**When** the user logs in with valid credentials
**Then** the API issues authentication using httpOnly Secure SameSite cookies
**And** no token is stored in localStorage or JS-accessible storage
**And** invalid credentials return a generic authentication error
**And** logout clears the authenticated session cookie
**And** authenticated frontend state updates without exposing token contents

## Story 2.3: Profile View and Edit

As an authenticated user,
I want to view and update my profile information,
So that my account details stay accurate.

**Acceptance Criteria:**

**Given** a user is authenticated
**When** they open their account page
**Then** they can view their email, display name, role, and relevant account metadata
**And** they can update editable profile fields
**And** email uniqueness and field validation are enforced server-side
**And** form errors are shown inline and specifically
**And** role fields cannot be changed by the user

## Story 2.4: Account Deletion and Personal Data Erasure

As an authenticated user,
I want to delete my account and associated personal data,
So that I can exercise my RGPD erasure rights.

**Acceptance Criteria:**

**Given** a user is authenticated
**When** they request account deletion
**Then** they must confirm the destructive action through AlertDialog
**And** personal data associated with the account is removed or anonymized according to legal retention rules
**And** the user is logged out after deletion
**And** the system preserves non-personal aggregate event data where legally allowed
**And** the deletion action is auditable without retaining unnecessary personal data

## Story 2.5: Admin User Directory

As an admin,
I want to search and filter user accounts,
So that I can manage community access efficiently.

**Acceptance Criteria:**

**Given** an admin is authenticated
**When** they open the user backoffice
**Then** they can view users with email, display name, role, and account status
**And** they can search and filter users by role and text query
**And** no non-admin can access the user directory UI or API
**And** empty and no-result states follow the UX spec
**And** API responses do not expose password hashes or sensitive auth internals

## Story 2.6: Admin Role Promotion and Demotion

As an admin,
I want to promote and demote users between lambda and membre,
So that member-only access is controlled by the association.

**Acceptance Criteria:**

**Given** an admin is viewing a user profile
**When** they promote a lambda user to membre
**Then** the user's role changes to membre after explicit confirmation
**And** the action is logged for auditability
**And** demoting a membre to lambda also requires explicit confirmation
**And** admins cannot accidentally remove their own last admin capability
**And** role changes are reflected in the UI with optimistic update and rollback on API failure

## Story 2.7: Admin Account Creation

As an admin,
I want to create other admin accounts,
So that the association board can share backoffice responsibility.

**Acceptance Criteria:**

**Given** an admin is authenticated
**When** they create a new admin account
**Then** the account receives admin role only through an admin-only API action
**And** required account fields are validated server-side
**And** the action is logged
**And** non-admin users cannot create admins
**And** the system prevents privilege escalation through client-side payload manipulation

## Story 2.8: API RBAC Enforcement

As the system,
I want every protected API endpoint to enforce roles server-side,
So that frontend route guards are never the security boundary.

**Acceptance Criteria:**

**Given** protected endpoints exist for account, admin users, content, events, and role changes
**When** unauthenticated or under-privileged users call them
**Then** the API returns the correct unauthorized/forbidden response
**And** RBAC is enforced in Symfony, not only in Next.js
**And** frontend redirects are treated as UX only
**And** functional tests cover at least lambda, membre, admin, and anonymous access paths
**And** error responses follow the documented API error format

## Story 2.9: RGPD Rights Request Support

As an authenticated user,
I want a clear way to exercise RGPD rights,
So that I can request access, rectification, erasure, portability, or opposition.

**Acceptance Criteria:**

**Given** a user is authenticated
**When** they open account privacy settings
**Then** they see the available RGPD rights and how to exercise them
**And** they can initiate or access the documented process for each right
**And** privacy policy links are available from the flow
**And** admin/contact handling requirements are recorded for follow-up
**And** the implementation does not promise automated portability unless that capability exists
