# Epic 3: Event Lifecycle Backoffice

Admins can create, configure, publish, protect, manage, and complete events, including game library and game-selection setup.

## Story 3.1: Admin Event List and Draft Creation

As an admin,
I want to create and view event drafts,
So that I can prepare events before publishing them publicly.

**Acceptance Criteria:**

**Given** an admin is authenticated
**When** they open the event backoffice
**Then** they can view an event list with title, type, status, dates, capacity, and visibility
**And** the empty state invites them to create the first event
**And** they can create a draft event with title, description, type, dates, venue, capacity, registration window dates, and public/private access flag
**And** required fields are validated server-side and displayed inline in the UI
**And** non-admin users cannot access event management

## Story 3.2: Edit Event Details and Registration Window

As an admin,
I want to edit event details and registration windows,
So that event information stays accurate before and during publication.

**Acceptance Criteria:**

**Given** an event draft or published event exists
**When** an admin edits event details
**Then** they can update title, description, type, dates, venue, capacity, and registration open/close dates
**And** invalid date ranges are rejected server-side
**And** capacity cannot be lowered below confirmed registration count without a clear validation error
**And** changes are persisted and reflected in public pages where applicable
**And** the edit form remains accessible and keyboard navigable

## Story 3.3: Publish, Unpublish and Lifecycle Transitions

As an admin,
I want to transition events through their lifecycle,
So that public visibility and operational status are controlled.

**Acceptance Criteria:**

**Given** an event exists
**When** an admin changes its status
**Then** supported transitions include draft, published, in-progress, and completed
**And** publishing makes the event visible on public listings
**And** unpublishing or reverting to draft hides it from public listings
**And** destructive or visibility-changing actions require confirmation
**And** invalid lifecycle transitions are rejected with a clear error

## Story 3.4: Private Event Password Configuration

As an admin,
I want to configure private event access passwords,
So that member-only or invitation-only events can be protected.

**Acceptance Criteria:**

**Given** an event exists
**When** an admin marks it private
**Then** they can set or update an event password
**And** the password is never exposed in plain text after saving
**And** public event cards show a members-only/private access state where appropriate
**And** only admins can configure private access
**And** this story only manages admin password configuration and does not implement registrant password entry

## Story 3.5: Archipelago Game Library Management

As an admin,
I want to manage the association's Archipelago game library,
So that events can offer accurate game choices and options.

**Acceptance Criteria:**

**Given** an admin is authenticated
**When** they open the game library backoffice
**Then** they can add, edit, and remove games with name, slug, description, cover image metadata, availability state, and supported event types
**And** removal is blocked or safely handled when a game is already used by existing registrations
**And** game list empty states and validation follow UX patterns
**And** non-admin users cannot manage the game library
**And** game records are available for event-specific selection configuration

## Story 3.6: Randomizer Option Definition for Games

As an admin,
I want to define configurable randomizer options per game,
So that participants can configure selected games during registration.

**Acceptance Criteria:**

**Given** a game exists in the library
**When** an admin adds configurable options
**Then** each option can define label, key, input type, plain-language description, required flag, default value, and advanced/basic visibility
**And** invalid option schemas are rejected server-side
**And** advanced options are distinguishable from base options
**And** option definitions are version-safe enough not to break existing registrations silently
**And** configured options are available to event game selection setup

## Story 3.7: Event Game Selection Intake Configuration

As an admin,
I want to configure game selection intake per event,
So that participants only see relevant games and options for that event.

**Acceptance Criteria:**

**Given** an event and game library entries exist
**When** an admin configures game selection for the event
**Then** they can enable or disable game selection intake
**And** they can choose available games for the event
**And** they can choose which game options are visible for the event
**And** the configuration is saved independently per event
**And** disabling game selection clearly affects the future registration flow

## Story 3.8: Attach Recap or VOD to Completed Event

As an admin,
I want to attach a recap article or VOD link to a completed event,
So that past events become useful public community content.

**Acceptance Criteria:**

**Given** an event is completed
**When** an admin attaches a recap article or VOD link
**Then** the completed event public page links to that recap or VOD
**And** the event listing reflects recap availability
**And** invalid VOD URLs are rejected or clearly marked
**And** only completed events can be marked with final recap content unless explicitly overridden
**And** public users can access the recap through the event page
