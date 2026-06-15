# Epic 4: Event Registration & Archipelago Game Selection

Authenticated users can register for events, access private events, select and configure Archipelago games, update/cancel registrations, and receive confirmation.

## Story 4.1: Registration Eligibility and Start Flow

As an authenticated user,
I want to start registration only when an event is available,
So that I understand whether I can register before entering details.

**Acceptance Criteria:**

**Given** a published event exists
**When** an authenticated user opens the registration page
**Then** the system checks publication state, registration window, capacity, and access type
**And** open public events allow the registration flow to start
**And** closed, unpublished, completed, or unavailable events show a clear terminal or informational state
**And** anonymous users are redirected to login/signup with return path preserved
**And** all eligibility decisions are enforced server-side

## Story 4.2: Private Event Password Access

As an authenticated user,
I want to enter a private event password,
So that I can register for an invitation-only event when I have access.

**Acceptance Criteria:**

**Given** an event is private and registration is open
**When** a user opens the registration page
**Then** the PasswordAccessGate is available behind a "J'ai un code d'accès" disclosure
**And** valid passwords unlock the registration flow
**And** invalid passwords show an inline field error without revealing whether the event password format is correct
**And** successful password access is recorded for admin visibility
**And** password access does not promote the user to membre

## Story 4.3: Atomic Event Registration Reservation

As an authenticated user,
I want my registration to reserve a seat reliably,
So that I do not lose my place because of concurrent submissions.

**Acceptance Criteria:**

**Given** an event has remaining capacity and registration is open
**When** a user submits the initial registration step
**Then** the backend creates or confirms the registration transactionally
**And** capacity is checked atomically server-side
**And** two concurrent requests cannot both claim the final seat
**And** full events return a graceful capacity-full response
**And** registration creation either completes fully or rolls back without partial state

## Story 4.4: Game Selection Grid

As a registrant,
I want to choose one or more games from the event library,
So that my Archipelago world can be prepared.

**Acceptance Criteria:**

**Given** an event has game selection enabled and available games configured
**When** the registrant reaches the game selection step
**Then** they see a responsive GameCard grid with game title, cover art, and selection state
**And** selected, disabled, and limit-reached states are represented visually and accessibly
**And** keyboard users can select/deselect games with focus, Enter, and Space
**And** selection limits are enforced both in UI and backend
**And** selected games are persisted to the registration draft or registration record

## Story 4.5: Per-Game Randomizer Option Configuration

As a registrant,
I want to configure key options for each selected game,
So that admins receive usable setup data for the multiworld.

**Acceptance Criteria:**

**Given** a registrant has selected a game with configured options
**When** they open that game's option panel
**Then** they can configure required base options with plain-language descriptions
**And** advanced options are collapsed by default and available through an accessible accordion
**And** incomplete required options are clearly marked
**And** option values are validated server-side against the event/game option schema
**And** invalid or stale options return field-addressable errors

## Story 4.6: World Summary and Registration Progress

As a registrant,
I want to see my selected games and completion progress,
So that I know what remains before submitting.

**Acceptance Criteria:**

**Given** the registration flow is in progress
**When** the user selects or configures games
**Then** the WorldSummaryPanel updates with selected games, key options, and completion state
**And** the RegistrationProgressIndicator shows the current step, completed steps, and errors
**And** the continue/submit CTA remains disabled until required information is valid
**And** mobile users see the summary as a sticky bottom bar with expandable details
**And** screen readers receive polite updates when games are added or removed

## Story 4.7: Review and Submit Registration

As a registrant,
I want to review my event and game choices before final submission,
So that I can confirm the registration data is correct.

**Acceptance Criteria:**

**Given** the registrant completed required event and game selection steps
**When** they reach the review step
**Then** they see event details, selected games, and configured options
**And** they can return to earlier steps without losing entered data
**And** submitting validates the full registration server-side
**And** success shows a personalized confirmation screen with next steps
**And** submission returns confirmation or actionable error within the NFR target

## Story 4.8: Update Game Selections Before Registration Closes

As a registrant,
I want to update my game selections before registration closes,
So that I can correct or refine my setup.

**Acceptance Criteria:**

**Given** a user has an existing registration and the registration window is still open
**When** they reopen their registration
**Then** their previous selections and option values are pre-filled
**And** they can update games and options subject to current event configuration
**And** updates are validated and persisted server-side
**And** the system prevents updates after registration closes
**And** admins can later see the latest saved selections

## Story 4.9: User Registration Cancellation

As a registrant,
I want to cancel my own registration,
So that I can release my place if I cannot attend.

**Acceptance Criteria:**

**Given** a user has an active registration
**When** they request cancellation
**Then** they must confirm through a destructive confirmation dialog
**And** the registration is marked cancelled or removed according to backend rules
**And** event capacity is made available again where applicable
**And** cancellation is blocked or clearly handled after configured cutoff rules
**And** the user receives clear confirmation of cancellation

## Story 4.10: Registration Confirmation Email

As a registrant,
I want to receive a confirmation email after registration,
So that I have event details and next steps outside the site.

**Acceptance Criteria:**

**Given** a registration is successfully submitted
**When** the backend completes the registration transaction
**Then** a confirmation email job is queued with event details, selected games, and next steps
**And** email delivery failure is logged and flagged without rolling back the registration
**And** the email content does not include sensitive auth tokens or private passwords
**And** duplicate submissions do not send duplicate confirmation emails unless intentionally retriggered
**And** the confirmation screen does not depend on email delivery success

## Story 4.11: Seat Counter and Capacity-Full Registration UX

As a registrant,
I want to see remaining seat availability during registration,
So that I understand urgency and capacity changes.

**Acceptance Criteria:**

**Given** an event has capacity configured
**When** a user views the event or registration page
**Then** they see the remaining seat count and capacity state
**And** available, low, full, and disconnected states follow the SeatCounter UX contract
**And** if the event becomes full during registration, the user sees a graceful CapacityFullScreen or equivalent terminal state
**And** frontend seat count is never trusted for final registration authority
**And** realtime behavior is integrated later with Epic 7, with polling or static fallback acceptable in this story
