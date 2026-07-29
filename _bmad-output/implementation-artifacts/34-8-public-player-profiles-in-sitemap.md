# Story 34.8: Public player profiles in the sitemap

Status: review
**Epic:** 34 - SEO & Search Visibility
**Date:** 2026-07-29

## Story

As a player who chose to make my ArchiLAN profile public,
I want my profile page to be discoverable through the site's sitemap,
so that my pseudo and run history can be found from a search engine.

## Context

Product decision (owner, 2026-07-29): profiles are indexable **iff their audience is `public`** -
the visibility the member explicitly chose on /compte/confidentialite. The profile page already
enforces this for anonymous viewers (a non-public profile renders the not-found branch, which is
`noindex`), so the only missing piece was enumeration: the sitemap cannot list what nothing
enumerates, and the community directory endpoint lists every member regardless of audience (its
purpose is the logged-in directory, not the crawler surface).

## Acceptance Criteria

1. An anonymous endpoint lists exactly the slugs of profiles with `audience = public`, excluding
   deleted and banned accounts, with the profile row's real `updatedAt`.
2. The sitemap emits `/joueurs/{slug}` for those profiles, `lastModified` from that timestamp;
   members/friends-only profiles never appear (matching the page's own visibility gate).
3. The sitemap's never-500 contract holds when the endpoint fails.
4. Gates green both sides.

## Dev Notes (implementation, 2026-07-29)

- API: `PublicProfileSlugsQueryInterface` (Community/Application/Query) +
  `DbalPublicProfileSlugsQuery` (join `community_profile` x `user`, `audience = 'public'`,
  `slug IS NOT NULL`, `deleted_at IS NULL`, `banned_at IS NULL`) +
  `GET /api/v1/community/public-profile-slugs` (anonymous - it exposes nothing a crawler could not
  already see by visiting the pages). Functional test covers the audience filter and the banned
  exclusion.
- The absent-row default is irrelevant here: enumeration selects rows whose audience column IS
  `public`, so dormant accounts without a profile row (story 30.28's concern) can never appear.
- Frontend: `getPublicProfileSlugs` (players api, [] on failure) feeds `/joueurs/{slug}` sitemap
  entries; `/joueurs` removed from the sitemap test's forbidden list with a comment.
- Suspended-until accounts stay listed (temporary state); banned and deleted do not.
