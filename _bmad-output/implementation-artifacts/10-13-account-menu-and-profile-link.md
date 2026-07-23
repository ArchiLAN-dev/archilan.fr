# Story 10.13: Account dropdown menu with a profile link in the nav

**Status:** done
**Epic:** 10 - Design & identité visuelle
**Date:** 2026-07-22

## Story

As a signed-in member,
I want a "Profil" entry in the top nav and a single account menu,
so that I can reach my public profile quickly without the bar filling up with a button per action.

## Context

When signed in, the right side of the nav was a row of buttons: bell, `Admin` (if admin), `Mon
espace`, `Se déconnecter`. Adding a profile link there would have made an already busy cluster
worse. The account actions collapse into one avatar dropdown instead, which both declutters the bar
and gives "Mon profil" a natural home.

### Decisions

- **Avatar dropdown, chosen by the author over two lighter options** (swap `Mon espace` for `Profil`,
  or drop a content link). It scales as the account surface grows and is the conventional pattern.
- **The trigger shows the community profile photo**, falling back to initials on a tinted disc when
  there is none or the image fails. The photo lives in the Community context, not the session, so it
  is fetched client-side under the **same** query key the settings form uses (`community-my-profile`)
  - served from cache, not refetched. That GET (`editableForUser`) reads with null-fallbacks and does
  **not** create a profile row, so putting the fetch in the shell has no side effect.
- **`slug` is added to the session user.** The public profile is `/joueurs/{slug}`, and the slug was
  not on `AuthUser`. `/account/profile` already returned it; `AuthController::userPayload` (login /
  me / refresh) now returns it too, so the link works immediately after login, not only on the next
  profile fetch. Slug is nullable - no slug, no profile link (the menu simply omits it).
- **Disclosure, not an ARIA `menu`.** The panel holds plain links plus a logout button, so tab order
  and Enter are native. It closes on outside-pointer, Escape, and navigation. A real `role=menu`
  would owe arrow-key navigation this does not implement.
- **The bell stays outside the menu.** It is a glanceable indicator, not an account action.
- **`ProfileAvatar` is not reused** for the trigger: it is hard-sized (`size-24`) and always wraps
  the decorative `AvatarFrame` (thick border, `rounded-2xl`), which reads wrong at 32px. The menu
  renders a compact round avatar of its own, mirroring the same img + initials-fallback behaviour.

## Acceptance Criteria

1. Signed in, the nav right side is the bell plus one avatar button; clicking it opens a panel with
   Mon profil (when a slug exists), Mon espace, Administration (admin only) and Se déconnecter.
2. The avatar is the community photo, with an initials fallback on missing/failed image.
3. `slug` is present on `AuthUser` and returned by both `/account/profile` and the `AuthController`
   payload; the profile link points at `/joueurs/{slug}` and is omitted when slug is null.
4. The panel closes on outside click, Escape and navigation.
5. The mobile menu gains a "Mon profil" link (when a slug exists); it stays a flat stack, no dropdown.
6. Gates green both sides.

## Tasks / Subtasks

- [x] **Task 1 - Session slug** (AC 3). `AuthController::userPayload` returns `slug`; `AuthUser` gains
      `slug: string | null`.
- [x] **Task 2 - UserMenu** (AC 1, 2, 4). New `features/auth/user-menu.tsx`: disclosure dropdown,
      community-avatar trigger with initials fallback, outside/Escape/navigate close.
- [x] **Task 3 - Wire the shell** (AC 1, 5). Desktop cluster becomes bell + `UserMenu`; mobile menu
      gains a `Mon profil` link.
- [x] **Task 4 - Gates** (AC 6).

## Dev Notes

- The community avatar query shares the settings form's key, so no extra request on pages that render
  the form; elsewhere it is one cached fetch per session. It is a `?.avatarUrl` read, resilient to a
  null profile.
- No backend cross-context coupling was added for the avatar: the shell fetches Community directly
  rather than having Identity's `/account/profile` reach into Community for an avatar URL.

### Project Structure Notes

- `api/src/Identity/Presentation/Controller/AuthController.php` (slug in payload)
- `frontend/src/features/auth/auth-context.tsx` (AuthUser.slug)
- `frontend/src/features/auth/user-menu.tsx` (new)
- `frontend/src/components/public-shell.tsx` (desktop + mobile nav)

### References

- [Source: frontend/src/features/players/profile-avatar.tsx] - the img + initials pattern mirrored here
- [Source: frontend/src/features/community/community-profile-api.ts] - `fetchMyCommunityProfile`

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

- API: phpstan / cs-fixer / ddd / rector clean; 1594 tests green on an isolated DB.
- Frontend: typecheck, lint, 224 tests, build green.

### File List

- `api/src/Identity/Presentation/Controller/AuthController.php`
- `frontend/src/features/auth/auth-context.tsx`
- `frontend/src/features/auth/user-menu.tsx`
- `frontend/src/components/public-shell.tsx`
