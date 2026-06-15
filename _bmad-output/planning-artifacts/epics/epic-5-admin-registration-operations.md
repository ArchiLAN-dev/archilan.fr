# Epic 5: Admin Registration Operations

Admins can monitor registrations, inspect game selections, export data, handle capacity notifications, modify registrations, and contact participants.

## Story 5.1: Admin Registration Dashboard

As an admin,
I want to view registrations for an event,
So that I can monitor participant status and game selection completeness.

**Acceptance Criteria:**

**Given** an admin is authenticated and an event has registrations
**When** they open the event registration dashboard
**Then** they see each registration with participant identity, timestamp, status, access type, and selected games summary
**And** they can filter or search registrations where useful
**And** password-access registrations are visibly marked
**And** cancelled registrations are visually distinct
**And** non-admin users cannot access registration dashboard endpoints or UI

## Story 5.2: Registration Detail and Game Selection Inspection

As an admin,
I want to inspect a participant's registration details,
So that I can validate their game choices before generating the multiworld.

**Acceptance Criteria:**

**Given** a registration exists with game selections
**When** an admin opens registration detail
**Then** they see selected games, configured option values, completion status, and any validation warnings
**And** incomplete or unusual selections are clearly highlighted
**And** the view distinguishes current selection data from historical/cancelled states
**And** no sensitive auth data is exposed
**And** the data matches the latest saved participant selections

## Story 5.3: Export Participant and Game Selection Data

As an admin,
I want to export event registration and game selection data,
So that I can prepare the Archipelago multiworld outside the site.

**Acceptance Criteria:**

**Given** an event has registrations
**When** an admin requests an export
**Then** the system exports participant, registration, selected game, and option data
**And** export supports at least CSV or JSON according to implementation choice
**And** cancelled registrations are excluded or clearly marked based on selected export option
**And** exports are only available to admins
**And** the export action is logged

## Story 5.4: Admin Registration Modification and Cancellation

As an admin,
I want to modify or cancel participant registrations,
So that I can resolve participant issues without direct database access.

**Acceptance Criteria:**

**Given** an admin is viewing a registration
**When** they modify allowed registration fields or cancel the registration
**Then** the action is validated server-side
**And** destructive cancellation requires confirmation
**And** capacity is updated correctly if cancellation releases a seat
**And** the action is logged with admin identity and timestamp
**And** the participant-facing state reflects the admin change

## Story 5.5: Admin Capacity Notifications

As an admin,
I want to be notified when an event reaches capacity,
So that I can react quickly to demand and communicate next steps.

**Acceptance Criteria:**

**Given** an event has a defined capacity
**When** the final seat is claimed
**Then** an admin notification is queued or recorded
**And** duplicate capacity notifications are avoided for the same capacity-reached event
**And** notification failure is logged without affecting the participant registration
**And** the backoffice clearly shows capacity reached
**And** this works even if the final seat is claimed under concurrent load

## Story 5.6: Admin Message to Participant

As an admin,
I want to send a message to an individual registrant,
So that I can resolve issues with their registration or game selection.

**Acceptance Criteria:**

**Given** an admin is viewing a registration
**When** they send a message to the participant
**Then** the message is sent through the configured communication channel
**And** the message body is required and validated
**And** delivery failure is logged and surfaced to the admin
**And** the action is recorded in the registration history
**And** users cannot send admin messages through this endpoint

## Story 5.7: Realtime Registration Feed

As an admin,
I want the registration dashboard to update in real time,
So that I can monitor registrations without refreshing during an opening window.

**Acceptance Criteria:**

**Given** the admin registration dashboard is open
**When** a new registration arrives
**Then** a RegistrationFeedItem appears without page refresh
**And** the new item receives the documented short highlight animation
**And** SSE disconnect shows a subtle stale-state indicator and falls back to polling
**And** reconnect resumes live updates without user action
**And** realtime updates never bypass server-side authorization
