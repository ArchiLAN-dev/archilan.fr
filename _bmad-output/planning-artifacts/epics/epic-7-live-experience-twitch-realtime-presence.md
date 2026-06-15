# Epic 7: Live Experience, Twitch & Realtime Presence

The site displays Twitch live/offline state and realtime activity signals, including live seat counters and resilient fallback behavior.

## Story 7.1: Realtime Infrastructure and Topic Authorization

As the system,
I want a realtime delivery layer for public and admin updates,
So that live site signals can update without full page reload.

**Acceptance Criteria:**

**Given** the backend and frontend are running
**When** realtime infrastructure is configured
**Then** the backend can publish public seat-counter events
**And** the backend can publish admin-only registration feed events
**And** frontend clients can subscribe to permitted topics
**And** admin topics require authenticated authorization
**And** SSE falls back to polling when the connection is unavailable

## Story 7.2: Public Realtime Seat Counter

As a visitor,
I want event seat availability to update live,
So that I can see registration momentum without refreshing.

**Acceptance Criteria:**

**Given** a published event has capacity
**When** registrations are created or cancelled
**Then** public event pages receive updated remaining seat counts
**And** updates are reflected within the target propagation window where SSE is available
**And** the SeatCounter animates count changes without disrupting accessibility
**And** disconnected state shows subtle fallback messaging
**And** final registration authority remains server-side

## Story 7.3: Twitch Live Status Detection

As a visitor,
I want the site to know whether ArchiLAN is live on Twitch,
So that I can open the stream when it is active.

**Acceptance Criteria:**

**Given** Twitch integration is configured
**When** the site checks channel status
**Then** the backend or server-side integration determines live/offline state
**And** Twitch API failure falls back to a static channel link
**And** no Twitch API secrets are exposed to the browser
**And** live/offline state can be consumed by the frontend navigation and landing page
**And** status refresh does not overload Twitch API limits

## Story 7.4: LiveTwitchBadge in Public Navigation

As a visitor,
I want a visible Twitch live/offline badge in navigation,
So that I can quickly join the stream when ArchiLAN is live.

**Acceptance Criteria:**

**Given** Twitch live status is available
**When** a visitor views public navigation
**Then** the LiveTwitchBadge shows live, offline, loading, or error state
**And** live state includes a visible live indicator and accessible label
**And** offline/error state links to the Twitch channel
**And** state transitions are visually smooth and not jarring
**And** screen readers receive appropriate live/offline state labels

## Story 7.5: Consent-Gated Twitch Embed

As a visitor,
I want to watch the Twitch stream on the site when I consent,
So that I can follow ArchiLAN events without an unexpected tracker load.

**Acceptance Criteria:**

**Given** Twitch is live and the visitor has not consented to non-functional embeds
**When** the landing page or stream section renders
**Then** the Twitch iframe is not loaded before consent
**And** the visitor sees a clear consent-aware placeholder with channel link fallback
**And** after consent, the Twitch embed lazy-loads responsively
**And** very small viewports degrade to a Twitch channel link if the embed is unusable
**And** the iframe has accessible title/fallback text

## Story 7.6: Realtime Resilience and Stale Data UX

As a visitor or admin,
I want realtime failures to degrade clearly,
So that I can trust whether displayed data is current.

**Acceptance Criteria:**

**Given** an SSE connection is active
**When** the connection drops
**Then** the UI waits briefly before showing a subtle disconnected indicator
**And** polling starts automatically on the documented interval
**And** stale data older than the threshold shows an action to refresh
**And** reconnect removes the stale indicator without user action
**And** no disruptive modal is shown for realtime interruptions
