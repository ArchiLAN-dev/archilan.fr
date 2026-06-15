# Epic 6: Payments, Ticketing & HelloAsso Sync

Visitors can use embedded HelloAsso checkout for tickets, memberships, and merchandise while admins see synchronized payment/order status in the ERP.

## Story 6.1: HelloAsso Integration Configuration

As an admin/developer,
I want HelloAsso credentials and integration settings configured safely,
So that payments can be embedded and synchronized without exposing secrets.

**Acceptance Criteria:**

**Given** the API and frontend environment examples exist
**When** HelloAsso configuration is added
**Then** required client IDs, secrets, organization slugs, and environment modes are documented in `.env.example` without real secrets
**And** secrets are read only server-side
**And** frontend code never exposes private HelloAsso API credentials
**And** sandbox and production modes can be distinguished
**And** missing configuration fails with a clear operational error

## Story 6.2: Embedded Event Ticket Checkout

As a visitor,
I want to purchase event tickets through embedded HelloAsso checkout,
So that I can complete payment without leaving archilan.fr.

**Acceptance Criteria:**

**Given** a public event has ticketing enabled
**When** a visitor opens the ticketing action
**Then** the HelloAsso checkout is embedded in the site where supported
**And** the user is not forced through an external redirect for the primary flow
**And** checkout loading and unavailable states follow UX feedback patterns
**And** CGV acceptance is presented before the transactional action
**And** no payment is treated as confirmed until HelloAsso confirmation/sync validates it

## Story 6.3: Embedded Membership Fee Checkout

As a visitor,
I want to pay association membership fees through HelloAsso,
So that public membership payments can be supported when enabled.

**Acceptance Criteria:**

**Given** membership payment is enabled in configuration
**When** a visitor opens the membership checkout
**Then** the HelloAsso membership form is embedded or surfaced through the approved embedded flow
**And** CGV/CGU/legal context is available before payment
**And** payment does not automatically promote the user to membre in v1 unless an admin-controlled process explicitly does so
**And** unavailable HelloAsso state degrades gracefully
**And** the transaction can later be synchronized into the ERP

## Story 6.4: Embedded Merchandise Boutique Checkout

As a visitor,
I want to browse or access merchandise checkout through HelloAsso,
So that I can buy ArchiLAN merchandise from the site.

**Acceptance Criteria:**

**Given** HelloAsso boutique configuration exists
**When** a visitor opens the boutique section
**Then** the HelloAsso boutique checkout is embedded or linked according to supported embedded capabilities
**And** the page clearly distinguishes merchandise checkout from event registration
**And** unavailable HelloAsso state shows a retryable message
**And** CGV are accessible before purchase
**And** no local inventory management is introduced in v1

## Story 6.5: HelloAsso OAuth/API Sync Adapter

As the system,
I want to synchronize HelloAsso orders and member/payment data,
So that the internal ERP reflects payment status without manual work.

**Acceptance Criteria:**

**Given** HelloAsso API credentials are configured
**When** the sync job runs or a payment update is requested
**Then** the backend retrieves relevant order/payment/member data through a server-side adapter
**And** data is mapped into internal payment records without exposing raw API secrets
**And** transient failures are retried through Messenger
**And** persistent failures are logged and surfaced to admins
**And** sync delay does not block a registration record from existing

## Story 6.6: Payment Status Visibility in Admin Registration View

As an admin,
I want to see HelloAsso payment status for registrations,
So that I can verify whether participant payments are complete.

**Acceptance Criteria:**

**Given** a registration is associated with a HelloAsso payment/order
**When** an admin views registration details or the registration dashboard
**Then** payment status is displayed with clear labels such as pending, confirmed, failed, refunded, or unknown
**And** stale or unsynced payment data is clearly marked
**And** admins can trigger or request a sync retry where appropriate
**And** payment status is not editable manually unless a specific audited override exists
**And** payment data access is restricted to admins

## Story 6.7: HelloAsso Graceful Degradation

As a visitor or admin,
I want clear feedback when HelloAsso is unavailable,
So that payment issues do not look like broken site behavior.

**Acceptance Criteria:**

**Given** HelloAsso checkout or API is unavailable
**When** a visitor opens a payment surface
**Then** the UI shows a specific, retryable degradation message
**And** no silent failure or blank embed is shown
**And** admins can see persistent sync failures in the backoffice
**And** errors follow the documented API error format
**And** payment outages do not corrupt registrations or local records
