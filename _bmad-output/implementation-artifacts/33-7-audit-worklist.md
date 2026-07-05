# Story 33.7 - Audit Worklist (React 19 / Next 15 best-practices pass)

Scope of record (AC1). Three parallel audits of `frontend/src` (353 TS/TSX files, 15 features)
vs `frontend/AGENTS.md`, 2026-07-05, develop = d19d8aa. Nothing outside this list is touched.

## A. Verified-clean axes (recorded, no work)

AC-TS2 `any`: 0. AC-API5 staleTime: 32/32 explicit (+ non-zero QueryClient default). AC-HK5
initialDataUpdatedAt: 1/1 prop-sourced (leaderboard-client is the exemplar). AC-API2 throwing
api fns: 0. AC-TS4 guard colocation: 0 orphans. AC-TS5 interface: 1/1 legitimate (declaration
merging for window.Twitch). AC-ENV1 process.env in src: 0 (eslint-enforced; next.config/jest
setup sanctioned by the eslint whitelist). AC-NX2 params: all ~30 dynamic pages await
Promise-params, no route.ts exists. AC-NX5 notFound(): 8/8. AC-CO2 prop types: 0 violations.
AC-ST3 useRef discipline: 0 violations (all refs are DOM/timers/guards/latest-mirrors).

## B. Items to FIX

| # | Item | Disposition |
|---|------|-------------|
| F1 | ~13 real array-index list keys (AC-KEY1): `install-steps-view.tsx:53` (self-contradicting its own line-15 comment), `install-steps-editor.tsx:74`, `game-selection-gate.tsx:376,210`, `personal-run-game-selection-page.tsx:431`, `registration-recap-gate.tsx:149,164`, `event-feed.tsx:202`, `admin-registration-detail.tsx:585`, `admin-achievements-dashboard.tsx:616`, `admin-guided-game-creation.tsx:400,412`, `admin-catalogue-sync-page.tsx:484,1055`, `admin-session-page.tsx:609,689`, `community-profile-customization-form.tsx:401`, `yaml-option-editor.tsx:1001` | Replace with stable/composite keys per AC-KEY2 |
| F2 | `features/admin/admin-new-game-page.tsx:46` default export (AC-CO3) | Named export + update the route importer |
| F3 | `features/events/seat-counter.tsx` carries `"use client"` with zero hooks/handlers/browser APIs (AC-NX4) | Remove the directive |
| F4 | `components/legal-placeholder.tsx` - `LegalPlaceholder` + `LegalField` never imported | Delete the file |
| F5 | 4 clear AC-HK3 render impurities: `date-time-picker.tsx:86` (`new Date()` in body) + `:76,:79` (eager `useState(new Date()...)` args), `admin-membership-dashboard.tsx:376` (same), `account-moderation-controls.tsx:93` (`new Date()` in JSX `min`) | Lazy `useState(() => ...)` initializers / hoist into mount-stable state |
| F6 | 8 network-data `as` casts (AC-TS3): `account-registrations.tsx:93`, `admin-catalogue-sync-page.tsx:113`, `signup-form.tsx:65`, `session-connection-gate.tsx:498`, `admin-session-page.tsx:228` (res.json casts); `personal-run-slot-detail-page.tsx:607`, `admin-slot-reachability-page.tsx:456` (error-body), `event-registration-cta.tsx:52` | `as unknown` + field-level narrowing (repo's established manual-parse pattern) |
| F7 | ~16 bare `react-hooks/*` eslint-disables without justification (AC-HK1 spirit: "never suppress without understanding why"): 7 set-state-in-effect, 6 exhaustive-deps, 3 refs (2 already justified, 1 purity correct) | Add one-line factual justification to each |
| F8 | 4 SIMPLE fetch-in-effect components (AC-NX1/AC-API4/AC-ST2): `overlay-links-panel.tsx` (fetchOverlaySlots), `admin-slot-switcher.tsx` (players list), `game-request-section.tsx` (2 lists), `join-page.tsx` (invite preview) | Convert to TanStack Query (explicit staleTime); raw fetches move into feature api modules. Fallback: any that proves entangled joins C2 with rationale |
| F9 | CSS trivia: `live-twitch-badge.tsx:33` `style={{color:"#ef4444"}}`, `info-tooltip.tsx:73` static left/top/visibility, `twitch-mini-player.tsx:281` `cursor:"grab"`, `goal-celebration.tsx:341` `borderRadius:14` (AC-CSS1); Discord `#5865F2`/`#4752C4` duplicated in `account-profile.tsx:88,102` + `discord-button.tsx:11` (AC-CSS2) | Static styles → Tailwind utilities; Discord color → single shared token/constant, identical values (zero visual change) |

## C. Accepted as-is (with rationale)

| # | Item | Rationale |
|---|------|-----------|
| C1 | `apiFetch(...)` called directly in ~86 component/page files (strict AC-API1 reading) | `apiFetch` is the sanctioned typed transport wrapper (`lib/apiFetch.ts`); relocating every call into `{module}-api.ts` is a mega-refactor with zero behaviour gain. Follow-up candidate (joins C2's story). |
| C2 | Complex fetch-in-effect pages (~14 files): auth-context bootstrap, admin/page, admin-session-page, admin-registration-dashboard, admin-user-directory, admin-event-game-selection, admin-guided-game-creation, admin-slot-reachability, registration-eligibility-gate (poll), session-connection-gate, personal-run-slot-detail/-yaml, weekly-run-slot-page, reachable-overlay (SSE hybrid) | Entangled state machines (multi-step gates, polling, SSE, AbortControllers). Converting them is a dedicated "TanStack Query migration" story (AC-NX1 + AC-API1 + AC-ST2 residual) - recorded in section D, not absorbable in a pass. |
| C3 | ~22 SSE `JSON.parse(event.data) as DomainType` casts + generic `as T` in `use-sse.ts`/`use-overlay-stream.ts` | Single-source Mercure hub events, one repeated legacy pattern (Reachability/Hints shapes across 4 pages). Needs a shared typed-SSE-with-guards layer - follow-up candidate (section D). Generic `as T` transports are by-design (consumer owns validation). |
| C4 | ~20 `as Record<string, unknown>` first-step casts in manual field-by-field parsers | Materially equivalent to a type guard (every field is narrowed before use); rewriting to `isX` guards is churn without safety gain. `admin-event-edit-page.tsx:169` (`as AdminEventFormData`) is the one closer-to-violation case - rides with C2's page. |
| C5 | ~40 index keys on fixed-count skeletons / static decorative arrays (particles, blobs, status dots) | Lists never reorder/filter - index keys are stable by construction. |
| C6 | Decorative hex palettes: goal-celebration, item-toast, pixel-trophy confetti/gradients, overlay panel backgrounds, SVG fills in streaming badges | Deliberate visual art palettes; tokenizing them is a design-system decision (epic: no visual redesign). |
| C7 | `community-activity.tsx:35,78` composite keys embedding `i` (`${type}-${occurredAt}-${i}`) | Type+timestamp prefix makes collisions/reorders effectively impossible; the `i` suffix only disambiguates same-ms duplicates. |
| C8 | 7 AC-HK2 setState-in-effect sites | All are sync state-reconciliation (prop→state resets, localStorage hydration), each already eslint-acknowledged; F7 adds the missing justifications. Restructuring would complicate the components for no behaviour gain. |
| C9 | 8 `Date.now()` relative-time/duration helpers invoked during render | Client components rendering relative timestamps; drift risk is a re-render showing a fresher "il y a X min" - the desired behaviour. Linter-invisible module helpers; not worth an architecture change. |
| C10 | `Math.random()` in `goal-celebration.tsx` lazy `useState` initializers | Client-only celebration overlay (no SSR of this subtree); lazy init runs once - the AGENTS.md `useRef`-init escape hatch equivalent. |
| C11 | 2 hardcoded `https://archilan.fr/...` Mercure topic URLs (`live-seat-counter.tsx:36`, `admin-registration-dashboard.tsx:134`) | Topics are protocol IDENTIFIERS that must byte-match what the api's RealtimePublisher publishes - they are not fetch URLs (AC-API3 targets base URLs). Deriving from env would silently desync the subscription. |
| C12 | `process.env` in `next.config.ts`/`jest.setup.ts` | Sanctioned by the repo's own eslint whitelist. |
| C13 | `env.mercurePublicUrl as string` redundant cast (`admin-registration-dashboard.tsx:192`) | Harmless; file rides with C2's future migration. |

## D. Out of scope - recorded follow-up candidates

- **"TanStack Query migration" story**: the C2 pages + C1 apiFetch relocation + C4's admin-event-edit-page cast - one bounded story with a per-page worklist.
- **"Typed SSE layer" story**: shared `isReachabilityData`/`isHintsData`/slots guards + a guard-aware `useSse` - kills the C3 casts (~22) in one move.
- Discord/social design-token sweep if the design system grows (C6).
