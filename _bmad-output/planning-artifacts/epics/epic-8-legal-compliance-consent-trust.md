# Epic 8: Legal Compliance, Consent & Trust

The site satisfies required French legal/RGPD/CNIL surfaces, displays legal documents in the right flows, and manages cookie consent lifecycle.

## Story 8.1: Legal Footer and Static Legal Page Shell

As a visitor,
I want legal pages linked from every public page,
So that I can access required association and policy information.

**Acceptance Criteria:**

**Given** the public shell exists
**When** a visitor views any public page
**Then** footer links to Mentions Legales, Politique de Confidentialite, CGV, and CGU are visible
**And** each legal route renders a readable static page shell
**And** pages use semantic headings and accessible document structure
**And** legal routes are public and crawlable
**And** missing legal content is clearly marked as content-required rather than silently empty

## Story 8.2: Mentions Legales Page

As a visitor,
I want to read ArchiLAN's legal notice,
So that I know who publishes and hosts the site.

**Acceptance Criteria:**

**Given** legal content is configured
**When** a visitor opens Mentions Legales
**Then** the page includes association name, address, phone/email contact, directeur de publication, and hosting provider identity fields
**And** missing required fields are visible to admins or fail validation before publication if managed dynamically
**And** the page is linked from the footer on every page
**And** content does not misrepresent ArchiLAN's loi 1901 nonprofit status
**And** the page is accessible without authentication

## Story 8.3: Privacy Policy and RGPD Information

As a visitor or user,
I want to read the privacy policy,
So that I understand how my personal data is processed and what rights I have.

**Acceptance Criteria:**

**Given** privacy policy content is configured
**When** a visitor opens the privacy page
**Then** it describes data controller identity, processing purposes, legal bases, retention periods, user rights, and CNIL complaint right
**And** it references account deletion and RGPD rights request paths where applicable
**And** the page is linked from the footer and relevant account flows
**And** content is readable on mobile and desktop
**And** the site does not collect non-functional tracking consent before showing this page

## Story 8.4: CGU Presentation During Account Creation

As a visitor creating an account,
I want to see and accept the CGU,
So that account creation is governed by clear usage terms.

**Acceptance Criteria:**

**Given** the signup flow exists
**When** a visitor creates an account
**Then** CGU acceptance is required before account creation succeeds
**And** the CGU are linked from the signup form
**And** the acceptance timestamp/version is stored where required
**And** the checkbox is not pre-checked
**And** the user receives a field-level validation error if they submit without acceptance

## Story 8.5: CGV Presentation Before Transactional Actions

As a visitor making a purchase,
I want to access and accept CGV before payment,
So that ticketing, membership, or merchandise purchases are legally framed.

**Acceptance Criteria:**

**Given** a transactional HelloAsso action is available
**When** a visitor starts ticket, membership, or merchandise checkout
**Then** CGV are linked and presented before the transactional action
**And** acceptance is required where the flow requires it
**And** acceptance is not pre-checked
**And** the CGV page is available from the footer
**And** checkout cannot silently bypass CGV presentation

## Story 8.6: Cookie Consent Banner

As a visitor,
I want to choose whether to allow non-functional cookies and embeds,
So that Twitch and analytics are not loaded without consent.

**Acceptance Criteria:**

**Given** a visitor has no stored consent choice
**When** they first visit the site
**Then** a cookie consent banner appears before non-functional trackers or embeds load
**And** the visitor can accept, reject, or configure consent
**And** rejection is as easy as acceptance
**And** the consent choice is stored and respected on future visits
**And** session/functional cookies are handled separately from non-functional consent

## Story 8.7: Persistent Consent Management

As a visitor,
I want to update or withdraw cookie consent later,
So that my choices remain under my control.

**Acceptance Criteria:**

**Given** a visitor has previously made a consent choice
**When** they use the persistent footer consent control
**Then** they can view and update current consent settings
**And** withdrawing consent prevents future non-functional embed/tracker loading
**And** the UI confirms the updated choice
**And** consent state changes are reflected without requiring account login
**And** Twitch embed components react correctly to withdrawn consent

## Story 8.8: Legal Compliance Review Checklist

As an admin,
I want a launch checklist for legal/compliance content,
So that missing required legal data is caught before launch.

**Acceptance Criteria:**

**Given** legal pages and consent flows exist
**When** admins prepare for launch
**Then** there is a documented checklist covering Mentions Legales, privacy policy, CGV, CGU, cookie consent, footer links, and transaction/account insertion points
**And** checklist items reference where each item is implemented
**And** missing association-specific legal content is explicitly marked as requiring human/legal review
**And** the checklist is stored in project docs or implementation artifacts
**And** this story does not claim to provide formal legal advice
