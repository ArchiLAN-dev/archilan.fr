# Epic 10: Identité visuelle & médias - pages publiques

Transformer les pages publiques d'un site fonctionnel en une vitrine professionnelle qui reflète l'identité d'ArchiLAN - logo officiel, atmosphère gaming, photos d'événements réels.

**FRs covered:** New scope - visual quality and brand identity enhancement not in original PRD.

## Story 10.1: Logo officiel dans la navigation et le footer

As a visitor,
I want to see the real ArchiLAN logo throughout the site,
So that I immediately recognise the association's visual identity.

**Acceptance Criteria:**

**Given** the public shell exists
**When** a visitor views any public page
**Then** the ArchiLAN illustrated logo (six circular game worlds) appears in the sticky navigation bar
**And** the same logo appears in the footer alongside the copyright text
**And** the old placeholder badge ("A") is fully removed from all public-facing surfaces
**And** the logo is stored in `public/images/logo.webp` and served locally - no external CDN dependency
**And** the logo renders at 36px in the navbar and 24px in the footer with correct aspect ratio

## Story 10.2: Hero immersif avec photo d'événement

As a first-time visitor,
I want a visually impactful homepage hero that shows real event atmosphere,
So that I immediately understand the LAN gaming culture of ArchiLAN.

**Acceptance Criteria:**

**Given** the homepage exists
**When** a visitor opens the landing page
**Then** the hero section spans the full container width with the event photo as background
**And** a dark gradient overlay (left → right and top → bottom) ensures headline text is readable
**And** the ArchiLAN logo is displayed within the hero above the headline
**And** the headline "Un item de ton jeu. Le monde entier." remains the primary message
**And** the two-column fake Archipelago example card is removed
**And** the "C'est quoi Archipelago ?" explainer below is restructured into three clean feature cards
**And** the layout is responsive and readable at 375px

## Story 10.3: Favicon et Open Graph

As a visitor sharing or bookmarking the site,
I want proper visual previews when sharing links and a recognisable favicon,
So that ArchiLAN looks professional when shared on Discord, Twitter, or in browser tabs.

**Acceptance Criteria:**

**Given** any public page is opened or shared
**When** the page metadata is read by a browser or social platform
**Then** the browser tab displays the ArchiLAN logo as favicon
**And** sharing the homepage URL shows the event photo as og:image with correct title and description
**And** `og:locale` is set to `fr_FR` and `og:site_name` to `ArchiLAN`
**And** og:image dimensions are declared (6000×4000)
**And** the description is updated to reflect the ArchiLAN mission accurately

## Story 10.4: Section galerie événements sur la homepage

As a visitor,
I want to see photos from past events on the homepage,
So that I can visualise the atmosphere before deciding to register.

**Acceptance Criteria:**

**Given** the homepage exists
**When** a visitor scrolls past the Archipelago explainer section
**Then** they see a "Nos événements" gallery section with a masonry-style grid
**And** available photos display an event label (e.g. "ARCHILAN 3") and a gradient overlay
**And** unavailable slots show styled placeholder cards with an image icon and "Photos à venir"
**And** a "Voir tous les événements →" link is visible at the top right of the section
**And** the grid is responsive: 1 column mobile, 2 columns tablet, 3 columns desktop with row-spanning large card

## Story 10.5: Cover image par événement

As a visitor,
I want each event to have a representative cover photo,
So that the event listing and detail pages feel visually rich.

**Acceptance Criteria:**

**Given** an admin is editing an event
**When** they set a cover image URL
**Then** the event detail page displays the cover as a hero image
**And** the event listing card displays a cropped version of the cover
**And** events without a cover image display a neutral placeholder
**And** `cover_image_url` (snake_case) is stored in the `events` table via a Doctrine migration
**And** the API serialises the field as `coverImageUrl` (camelCase) in the event payload
**And** the admin event edit form includes a cover image URL field

## Story 10.6: Galerie photos par événement

As a visitor,
I want to see a photo gallery on past event pages,
So that I can relive or discover the atmosphere of a specific event.

**Acceptance Criteria:**

**Given** a completed event with photos configured exists
**When** a visitor opens the event detail page
**Then** they see a responsive photo gallery grid below the main event content
**And** photos are stored as a JSON array of URLs in a `photo_gallery` column on the `events` table
**And** the gallery displays between 2 and 12 photos
**And** events with no photos configured do not show the gallery section
**And** an admin can set and update photo URLs from the event edit form in the backoffice

## Story 10.7: Cover image par article

As a visitor,
I want news and recap articles to have cover images,
So that the content section feels as polished as the events section.

**Acceptance Criteria:**

**Given** a published news post or recap exists
**When** a visitor opens the news listing or article detail page
**Then** articles with a cover image show it in the listing card and as a header on the detail page
**And** articles without a cover image display a neutral placeholder
**And** `cover_image_url` is added to the `posts` table via a Doctrine migration
**And** the API serialises `coverImageUrl` in the post payload
**And** the admin content editor includes a cover image URL field

## Story 10.8: Gaming atmosphere design refresh

As a visitor,
I want the public site to feel visually immersive and gaming-adjacent,
So that the atmosphere matches the cooperative LAN gaming culture of ArchiLAN.

**Acceptance Criteria:**

**Given** any public page is open
**When** a visitor views the page
**Then** a subtle repeating grid pattern is visible on the background, adding depth without cluttering
**And** the navigation bar has a faint teal glow line below it
**And** all interactive cards (feature cards, event cards, community links) emit a soft teal glow on hover
**And** the active navigation link's bottom border has a teal glow
**And** the primary CTA button ("Voir les événements") has a resting teal glow that intensifies on hover

**Given** the homepage hero is displayed
**When** a visitor views the headline
**Then** the h1 text renders as a gradient from white to teal
**And** the overline "Association Archipelago en France" uses the magenta brand colour (`--color-special`) instead of warm orange

**Given** the design tokens exist in globals.css
**When** the gaming design is applied
**Then** no new colours are introduced - only existing tokens (`--color-accent`, `--color-special`) are used
**And** the changes are CSS/Tailwind only with no backend impact
