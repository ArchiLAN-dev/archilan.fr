# Epic 30 - Community: Steam-style Enriched Player Profiles

Status: planned (not started)
Date: 2026-06-17 (rev 0.4 - second review pass)

## Goal

Turn the read-only player profile (Epic 18) into a **rich, Steam-like community hub** so ArchiLAN
feels like a living community, not just an event tool. A profile becomes a place players **customize,
show off, and visit each other on**: avatar + banner, bio, favorite games, showcases, **achievements
& a level**, a **friends graph**, an **activity feed**, and **interactions** (comments, kudos).

North star: a member is **proud of their profile** and wants to **see what their friends are doing**
between LANs.

## Decisions (locked)

- **Builds on Epic 18, does not replace it.** `PlayerProfileQuery` / `PlayerStatsQueryInterface` /
  `PlayerHistoryQuery` (Identity) and `DbalCommunityStatsQuery` (Sessions) remain the **stats &
  leaderboard foundation**; Epic 30 wraps and enriches them.
- **Four pillars**: (1) personalization & showcases, (2) gamification (achievements + level/XP),
  (3) social graph + activity feed, (4) interactions (comments + kudos).
- **Mutual friendships** (request → accept/decline → friends) + **block**. `friends` is a first-class
  privacy audience. (Chosen over one-way follow.)
- **Avatar/banner = reuse Discord & Steam, no upload** this epic. Banner = curated presets. Upload +
  image moderation deferred. (See *Avatar resolution* below - this is a real integration, not a field.)
- **One new bounded context `Community`** owns the social graph, profile customization, interactions,
  activity feed, achievements/level and in-app notifications. (See *Bounded context* for why one and
  not two.)
- **Default audience = members-only** for the social/interaction surface; the core profile (identity +
  aggregate stats) stays public (Epic 18 parity). Audience: `public | members | friends`, enforced
  **server-side on every read**. **`members` means a *live* membership** (`IS_MEMBER` /
  `ApiAccessGuard`), **never the stale `ROLE_MEMBER`** (CLAUDE.md AC-M1/M2). Full viewer ladder
  (review #10): `anonymous` → `authenticated non-member` → `member` → `friend` → `self`.
- **Achievements are derived, deterministic, recomputable** from existing data; no manual grants in the
  MVP.

---

## Architecture analysis

### A. Codebase reality this epic must live with (verified)

- **No domain-event bus.** Cross-aggregate signals today are *data mutations* (e.g. a slot's
  `goal_reached_at` is set inside `SessionLifecycleManager`, from a status webhook), not broadcast
  events. Asynchronous work uses **Symfony Messenger** messages dispatched at specific write sites
  (e.g. `MembershipActivatedNotificationMessage`, `ReconcileStuckRunsMessage`).
- **No in-app notification store.** Existing "notifications" are Messenger → email/Discord via
  `Communications`. An in-app notification center is **net new** (owned by `Community`).
- **Stats/history are DBAL read models** over the `session` / `session_slot` tables
  (`DbalPlayerStatsQuery`, `DbalPlayerHistoryQuery`, `DbalCommunityStatsQuery`). The slot row already
  carries `checks_done`, `items_received`, `goal_reached_at`, `was_released` - **everything the
  achievement engine needs is already persisted.**
- **Identity.User** has `slug`, `displayName`, `discordId`, `createdAt`. **No avatar URL, no steamId.**
  The **SteamID lives in `GameSelection`** (`SteamProfileReference`, Epic 28), and Discord avatars are
  not stored (only `discordId`).
- **Realtime** is a dedicated context (`RealtimePublisher` over Mercure) - reuse it for feed /
  notification push; don't talk to Mercure directly from `Community`.
- **DDD is enforced**: a new context must be added to `DddArchitectureValidator::CONTEXTS`, get its four
  layer dirs, a `services.yaml` Domain exclusion, and Doctrine mapping. Application must not inject
  `EntityManager`/`Connection` (repository interfaces in Domain, query interfaces in Application, DBAL
  in Infrastructure).

### B. Bounded context: one `Community`, not two

A single `Community` context owns: profile customization, friendships, interactions, activity feed,
achievements/level, in-app notifications. Rationale: these share the same aggregates' lifecycles
(a friendship gates feed/interaction visibility; an achievement unlock produces a feed entry **and** a
notification), so splitting "Gamification" out would create a chatty intra-team dependency for no
isolation benefit. We keep **internal modules** (`Profile`, `Social`, `Feed`, `Achievement`,
`Notification`) as namespaced sub-folders under each layer, so a later extraction stays cheap.

**Dependency direction (one-way):** `Community` depends only on the **read interfaces** of `Identity`,
`Sessions`, `Streaming`, `GameSelection`. **Nothing depends on `Community`** - it is a leaf consumer +
its own presentation. Other contexts never import Community.

> **Decision (review #1) - message ownership.** To preserve that one-way direction with the write-site
> signals of section E.2, the **fact message is owned by the producing context** (e.g.
> `Sessions\Application\Message\RunOutcomeRecorded`) and `Community` *subscribes* to it (handler in
> `Community`). This is a **deliberate inversion of the existing precedent** where a producer imports
> the consumer's message (`Sessions` dispatches `Communications\…\SessionRunningMessage`): here we keep
> `Community` a pure leaf rather than let `Sessions`/`Registrations` import a `Community` message. If a
> reviewer prefers strict consistency with the precedent instead, the alternative is acceptable but
> then "nothing depends on Community" must be relaxed.

### C. Domain model

Aggregates / entities owned by `Community` (write side):

- **`CommunityProfile`** (aggregate, 1-1 with a `User` by userId) - customization: `bio`, `tagline`,
  `pronouns`, `bannerPreset`, `socialLinks`, `favoriteGameIds[]`, `showcaseLayout`, `audience`
  (`public|members|friends`), `avatarSource` (`discord|steam|default`). The profile **owns
  presentation prefs only**; identity (name/slug) stays in `Identity.User`.
- **`Friendship`** (aggregate) - `(requesterId, addresseeId, status: pending|accepted|declined,
  createdAt, respondedAt)`. Canonical unordered pair to enforce uniqueness; `accepted` = mutual.
- **`Block`** (aggregate or value on profile) - `(blockerId, blockedId)`. Blocking is the strongest
  user action: it **retracts any existing/pending friendship**, **hides feed + comments both ways**,
  and **prevents any re-interaction** (new request/comment/kudos). It overrides every audience.
- **`ProfileComment`** (aggregate) - guestbook entry on a profile `(profileUserId, authorId, body,
  createdAt, hiddenAt?)`. **Two distinct audiences**: who may *view* comments = the profile's section
  audience; who may *write* = `members` by default (and never a blocked user), tightenable to `friends`
  by the owner.
- **`Kudos`** (aggregate) - reaction `(actorId, targetType: run|achievement, targetId, createdAt)`,
  unique per (actor, target). For `achievement`, `targetId` is a specific user's **`AchievementGrant`**
  (you congratulate *their* unlock), not the catalog definition.
- **`ActivityEntry`** (append-only record) - materialized feed row `(actorId, type, subjectRef,
  occurredAt)`; written at signal time (see E). **No audience column**: visibility is resolved at read
  from the actor's *current* profile (review #2), never snapshotted.
- **`AchievementGrant`** (record) - `(userId, achievementKey, unlockedAt, progressSnapshot)`; the
  catalog itself is **code-defined** (a `AchievementDefinition` registry), grants are persisted.
- **`Notification`** (aggregate) - `(recipientId, type, payload, readAt?, createdAt)`; the in-app
  center.
- **`ContentReport`** (aggregate, review #11) - `(reporterId, targetType: comment|profile, targetId,
  reason, createdAt, resolvedAt?, resolvedBy?)`; feeds the admin moderation queue (30.13). *Was
  referenced in §I/30.13 but missing from the model - added here.*

Value objects: `Audience`, `SocialLink`, `AchievementKey`, `Level` (derived), `ShowcaseSlot`.

### D. Read models & cross-context contracts

The **enriched profile is a read model**, not an aggregate read. `CommunityProfileQuery` composes:

- identity + joinedAt → `Identity` (existing `User` read / a small `UserIdentityQuery`),
- aggregate stats → **reuse** `PlayerStatsQueryInterface` (Epic 18),
- run history / best runs → **reuse** `PlayerHistoryQuery` (Epic 18),
- favorite games (covers/slugs) → `GameSelection` catalog read,
- live presence → `Streaming` Twitch status (Epic 7),
- customization + achievements + friends + interactions → `Community`'s own repositories.

New **Query interfaces** are defined in `Community/Application` and implemented with DBAL in
`Community/Infrastructure`. Where a read crosses into another context's tables, prefer **calling that
context's existing Query service**; only DBAL-read foreign tables when no query exists (consistent with
how `DbalPlayerHistoryQuery` already reads `session`/`session_slot`).

### E. Signals & eventing strategy (the crux)

Because there is **no event bus**, Epic 30 uses a **two-pronged, backfill-first** approach:

1. **Deterministic recompute (source of truth).** Achievements and aggregate-derived state are computed
   from the existing read models (slots with `goal_reached_at`, counts, distinct games, attended
   events). A `community:achievements:recompute` command (and a light scheduled pass) (re)evaluates the
   catalog for a user. → adding a new achievement later **retroactively grants** it with no migration.
   **Grants are monotonic (review #12):** the recompute only **adds** grants, **never revokes** one - an
   achievement earned at the time stays earned even if a later Epic-18 stat invalidation (forfeit /
   slot release, `was_released`) would lower the underlying count. (Steam-like; flip to "revocable"
   only if Jean wants strict integrity over UX.)
2. **Thin write-site dispatch (for freshness/realtime).** At the few existing write sites that already
   mutate the relevant data, dispatch a small Messenger message consumed by `Community` handlers that
   (a) write an `ActivityEntry`, (b) evaluate just-affected achievements, (c) push realtime via
   `RealtimePublisher`, (d) create `Notification`s. Candidate sites (add a one-line dispatch each):
   - goal recorded / run finished (`Sessions` lifecycle) → `RunOutcomeRecorded`,
   - registration confirmed (`Registrations`) → `EventAttendanceRecorded`,
   - friendship accepted, comment posted, kudos given (`Community` internal).

   The fact message is **owned by the producing context** (review #1); the handler lives in `Community`
   and is **idempotent**. These messages are **facts already persisted**; the recompute in (1) is the
   safety net if a dispatch is ever lost. A producer emits a fact and **does not know about Community**.

### F. Avatar resolution (explicit, because it's not a field)

- `avatarSource` on the profile picks Discord, Steam, or a generated default.
- **Discord**: only `discordId` is stored; the avatar URL must be fetched (Discord CDN via the bot /
  REST). **Constraint (review #14):** the bot can only read a user it **shares a guild with** - a
  member who left the ArchiLAN Discord is unresolvable → fallback. Cheaper option: **capture the avatar
  hash during the existing Discord sync (Epic 21)** when the member object is already fetched, instead
  of a separate call. **Steam**: SteamID lives in `GameSelection`; avatar via Steam Web API
  `GetPlayerSummaries`, **requires a public Steam profile** (same constraint as Epic 28).
- **Decision:** store a **cached, resolved `avatarUrl` + `avatarResolvedAt`** on `CommunityProfile`
  (snapshot of the URL, refreshed lazily/scheduled, e.g. stale after ~7 days or on profile edit),
  **not** a live call per page view. Resolution is an Infrastructure adapter
  (`AvatarResolverInterface`) with Discord + Steam implementations + null/stub.
- **Source precedence** when both are linked: the owner's explicit `avatarSource`, else default Discord
  → Steam → generated.
- **Fallback must cover load errors, not just null** (review #4): a snapshotted URL can later 404, so
  the client renders the deterministic **identicon/initials** on `onError`, not only when the stored URL
  is null. Never a broken image.

### G. Privacy / audience policy

- A single **`AudiencePolicy`** (Domain service) answers `canView(viewerId, profile, section)` given
  the section's audience and the viewer↔owner relationship
  (`self / friend / member / authenticated-non-member / anonymous`, review #10 - **`member` resolved by
  live `IS_MEMBER`, not `ROLE_MEMBER`**) and **block** state (block hides both directions, overrides
  everything). The live membership check is **injected** (an interface the Membership context
  satisfies), since Domain stays pure.
- Enforced **server-side in every read path** (profile, feed, comments, friends list), never only in
  the UI. The five viewer tiers are distinct.
- RGPD: profiles, friendships, activity and comments are personal data → a member can set their profile
  `private`-ish via `friends` audience and can delete their customization/social data. **Privacy-page
  caveat (review #7):** a dedicated privacy/RGPD page was **not found** in the frontend (only
  `/mentions-legales` exists) - the relevant story must **verify and, if absent, create** the
  privacy/RGPD section that documents this personal-data processing (not assume one exists).

### H. Activity feed model

- **Pull model for the MVP** (no write-time fan-out): `ActivityEntry` rows are written once at signal
  time (E.2) tagged with `actorId` **only**; the feed query reads *recent entries where actor ∈
  {self, friends}* and resolves visibility through `AudiencePolicy` against each actor's **current**
  profile audience (review #2 - audience is never stored on the entry, so changing a profile to
  `friends` retroactively hides its past activity from non-friends). Fan-out-on-write is explicitly
  deferred (revisit only if the pull query doesn't scale).
- Entries are **immutable**; deleting the underlying subject hides the entry (soft).

### I. Moderation & rate limiting

- Comments/kudos: per-user **rate limits** (reuse Symfony RateLimiter), **report** + **hide** (soft
  delete with `hiddenAt`), and an **admin moderation panel** listing reports. Friends-only default
  shrinks the blast radius.
- Block is the user-level primitive; admin hide/ban is the operator primitive.

### J. Data model (new tables, indicative)

`community_profile`, `community_friendship` (unique pair index), `community_block`,
`community_profile_comment`, `community_kudos` (unique actor+target), `community_activity_entry`
(index on `actor_id, occurred_at`), `community_achievement_grant` (unique user+key),
`community_notification` (index on `recipient_id, read_at`), `community_content_report` (review #11,
index on `resolved_at`). Achievement **definitions live in code**, not a table.

### K. Performance & scaling notes

- Enriched profile read is **composed of several queries** → batch/parallelise and lazily refresh the
  avatar URL. **Caching (review #3):** only the **public** slice (identity + aggregate stats) is safe to
  cache per profile; the audience-dependent sections (friends, feed, interactions) are composed
  per-request or cached under a key that includes the **viewer tier** (anonymous/member/friend/self) -
  never a single per-profile cache, which would leak across audiences.
- Feed query is the main risk → indexed `(actor_id, occurred_at)`, friends list bounded, paginate.
- Achievement recompute is O(user) and runs on demand / scheduled, off the request path.
- **Directory list ≠ full profile (review #13):** `/communaute` must use a dedicated **lightweight list
  read model** (one query returning the row fields: name, avatarUrl, level, presence flag), **not** the
  full multi-query `CommunityProfileQuery` per row, which would be N+1.

---

## Scope

### In scope
- New `Community` bounded context (4 layers) + DDD/services/Doctrine registration.
- Enriched, redesigned public profile (`/joueurs/{slug}`) + owner customization.
- Achievements catalog + deterministic award engine + level/XP.
- Mutual friendships + block + `friends` audience enforced server-side.
- Activity feed (own + friends'), pull model.
- Interactions: profile comments + kudos, with rate-limiting + report/hide + admin moderation.
- In-app notification center.
- Twitch/active-session presence on profile + feed.
- A `/communaute` directory (browse/search, top players, recently active, friends).

### Out of scope
- Avatar/banner **image upload** + MinIO storage + image moderation (deferred).
- DMs / private chat (Discord stays the chat tool); following (we ship mutual friends).
- Player-defined or seasonal achievements; cross-posting activity to Discord/socials.
- Email/Discord delivery of the new notifications (in-app only for the MVP).
- Write-time feed fan-out; reputation/karma; profile themes.

## Affected systems (verified)

- **api/ new `Community`** - all new aggregates/read models/endpoints/handlers/notification center.
- **api/ `Identity`** - expose a small identity read (name/slug/joinedAt/discordId) for the read model;
  no schema change beyond what avatar caching needs (cache lives on `CommunityProfile`).
- **api/ `GameSelection`** - source SteamID (avatar) + catalog (favorite-game covers).
- **api/ `Sessions`/`Registrations`** - add **one-line Messenger dispatches** at existing write sites
  (run outcome, attendance) consumed by `Community`; no behavioural change to those contexts.
- **api/ `Streaming`** - Twitch live status for presence.
- **api/ `Realtime`** - `RealtimePublisher` for feed/notification push.
- **frontend/** - `features/players` profile fully redesigned; new `features/community` (friends, feed,
  interactions, notifications, directory). Server Components for reads, TanStack Query for writes,
  Mercure for live feed/notifications.

## Story breakdown (re-sliced - vertical, shippable)

> Grouped by track. Each story names the **DDD artifacts it introduces** and its **deps**. Detailed
> story files land in `implementation-artifacts/30-*.md` when picked up.
>
> **Tracks ≠ phases:** tracks are *thematic* groupings; the *delivery* order is the Phasing section
> below (a track number does not map to a phase number).

### Track 0 - Foundation
- **30.1 - `Community` context skeleton + enriched profile read model + page shell.**
  New context (4 layers, DDD/services/Doctrine registration); `CommunityProfile` aggregate (created
  lazily on first view, **idempotent upsert on the unique `userId`** to avoid concurrent-insert races,
  review #9) + repository; `CommunityProfileQuery` composing Epic-18 stats + identity;
  redesigned `/joueurs/{slug}` Steam-style header + stat showcase. *Read-mostly; no social writes.*
  Deps: none.
- **30.2 - Avatar resolution + caching.** `AvatarResolverInterface` (Discord + Steam adapters + stub),
  cached `avatarUrl`/`avatarResolvedAt` on the profile, scheduled/lazy refresh, identicon fallback.
  Deps: 30.1.

### Track 1 - Personalization
- **30.3 - Profile customization (owner edit).** Bio, tagline, pronouns, social links, banner preset,
  favorite games, **profile audience** + `AudiencePolicy` (Domain) wired into the profile read.
  Deps: 30.1.

### Track 2 - Gamification
- **30.4 - Achievement catalog + deterministic engine + recompute command.** Code-defined
  `AchievementDefinition` registry, `AchievementGrant` records, evaluator over existing read models,
  `community:achievements:recompute` + scheduled pass. Surfaced on the profile (unlocked/locked).
  Deps: 30.1.
- **30.5 - Level & XP.** Derive XP from stats + grants, level curve, Steam-style level badge + progress.
  **Define the canonical XP formula** here, because the directory's "top players" (30.15) ranks on it -
  align with / reuse the Epic-18 community leaderboard so there are not two competing rankings
  (review #8). Deps: 30.4.
- **30.6 - Showcases.** Owner-arranged widgets (featured achievements, best runs, favorite games,
  most-played, currently-playing). Deps: 30.3, 30.4.

### Track 3 - Social graph
- **30.7 - Friendships + block.** `Friendship` + `Block` aggregates, request/accept/decline/remove,
  friends list, `AudiencePolicy` extended with friend/block tiers (enforced everywhere). Deps: 30.3.
- **30.8 - Activity feed infrastructure + write-site signals.** `ActivityEntry` + the thin Messenger
  dispatches at `Sessions`/`Registrations` write sites + idempotent `Community` handlers; backfill.
  Deps: 30.1 (signals can land before friends, surfaced in 30.9).
- **30.9 - Feed surfacing (own + friends').** Profile activity tab + a friends' feed on the dashboard,
  audience-filtered, paginated, optional Mercure live updates. Deps: 30.7, 30.8.

### Track 4 - Interactions & notifications
- **30.10 - Profile comments (guestbook).** `ProfileComment` + rate-limit + report/hide, audience-aware.
  Deps: 30.7.
- **30.11 - Kudos / reactions.** `Kudos` on runs + achievements, unique per actor+target. Deps: 30.4.
- **30.12 - In-app notification center.** `Notification` aggregate + center UI + Mercure push; emitted
  by friendship/comment/kudos/achievement handlers. Deps: 30.7, 30.10, 30.11.
- **30.13 - Admin moderation panel.** Reports queue, hide/restore, privacy-page update. Deps: 30.10.

### Track 5 - Presence & discovery
- **30.14 - Presence & live.** "Currently playing / live on Twitch" on profile + feed (reuse Epic 7 +
  active session). Deps: 30.1 (feed integration after 30.9).
- **30.15 - Community directory `/communaute`.** Browse/search players, top players (ranked on the
  canonical XP/leaderboard defined in 30.5 + Epic-18 - one source of truth, review #8), recently active,
  your friends - the social entry point. Built on a **lightweight list read model**, not per-row profile
  composition (review #13). Deps: 30.7, 30.5.

## Sequencing

1. **30.1 → 30.2 → 30.3** (foundation, avatar, customization+audience) - prerequisite for everything.
2. **Gamification** 30.4 → 30.5 → 30.6 and **Social** 30.7 → 30.8 → 30.9 proceed in **parallel** after
   30.3.
3. **Interactions** 30.10/30.11 after 30.7/30.4; **30.12 notifications** after they exist; **30.13
   moderation** right after 30.10.
4. **30.14 presence** any time post-30.1 (feed bits after 30.9); **30.15 directory** last (needs
   profiles + friends to be meaningful).

### Phasing / MVP cut line (review #5)

15 stories is a multi-month epic; ship in value-bearing phases rather than all-or-nothing:

- **Phase 1 - "A profile to be proud of" (no social):** 30.1 → 30.2 → 30.3 → 30.4 → 30.5 → 30.6.
  A beautiful, customizable public profile with avatar, achievements, level and showcases. Shippable
  and valuable on its own; **zero social-graph complexity, moderation or notifications**.
- **Phase 2 - "Community" (social + interactions):** 30.7 → 30.8 → 30.9 (friends + feed), then
  30.10 → 30.11 → 30.12 → 30.13 (comments/kudos/notifications/moderation). This is where the
  moderation/RGPD/notification weight lands - do not start it until Phase 1 is live.
- **Phase 3 - "Discovery":** 30.14 (presence) + 30.15 (directory).

Each story still ships only with all quality gates green (phpstan / php-cs-fixer / phpunit /
`app:architecture:ddd` + frontend typecheck / lint / build) per project standards.

## Risks / notes

- **No event bus** → the backfill-first recompute (E.1) is the source of truth; write-site dispatches
  (E.2) are best-effort freshness. Don't build a feature that *requires* a dispatch to never be lost.
- **Avatar** spans two contexts + external calls → cache + identicon fallback; never block a page on a
  Discord/Steam call.
- **Moderation/RGPD** → server-side `AudiencePolicy` on every read, block hides both ways, report/hide +
  admin panel from the start, privacy page updated.
- **Feed performance** → pull model + indexes for the MVP; fan-out-on-write only if needed.
- **Notification noise** → in-app only, per-type mute, sensible defaults. **Known limitation:** an
  off-site member won't see a friend request until they return (no email/Discord push in the MVP);
  accepted as a Phase-2 trade-off.
- **Rate limiting** → `symfony/rate-limiter` is present transitively; add a direct `composer require`
  if 30.10/30.11 rely on it.
- **Scope size** → 15 stories across 3 phases (see Phasing); ship **Phase 1** (rich personal profile +
  gamification) before the heavier social/moderation/notification machinery of Phase 2.

## Change Log

| Date       | Version | Description                                                        | Author |
|------------|---------|--------------------------------------------------------------------|--------|
| 2026-06-17 | 0.1     | Initial epic draft (scope locked with Jean).                       | Claude |
| 2026-06-17 | 0.2     | Architecture pass: context design, signal strategy, avatar, audience policy, feed model; re-sliced into 15 vertical stories. | Claude |
| 2026-06-17 | 0.3     | Review corrections: message ownership/dep direction (#1), read-time audience resolution (#2), viewer-tier cache (#3), avatar onError fallback + precedence (#4), explicit MVP phasing (#5), block/comment audiences (#6), privacy-page caveat (#7), single ranking source (#8), idempotent profile upsert (#9). | Claude |
| 2026-06-17 | 0.4     | Second pass: `members` = live `IS_MEMBER` not `ROLE_MEMBER` + full 5-tier viewer ladder (#10), added missing `ContentReport` aggregate/table (#11), achievements monotonic - recompute never revokes (#12), directory uses a lightweight list read model to avoid N+1 (#13), Discord avatar guild constraint + capture-at-sync option (#14); kudos-on-achievement targets a grant; tracks≠phases note. | Claude |
