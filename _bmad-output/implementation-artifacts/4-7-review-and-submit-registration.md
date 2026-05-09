# Story 4.7 - Review and Submit Registration

**Status:** done  
**Validation:** RegistrationSubmitTest 10 tests, 61 assertions - PHPStan level max: 0 errors - PHP CS Fixer clean on touched backend files

## Changes

### Backend

**`api/src/Registrations/Application/RegistrationSubmission.php`** (new)
- `submit(string $registrationId, string $userId): ?array` - guards: registration exists, belongs to user, `isReserved()`
- If game selection enabled and no games selected → `['outcome' => 'error', 'code' => 'games_required', 'message' => '...']`
- On success → `['outcome' => 'confirmed', 'registrationId', 'eventTitle', 'selectedGameIds']`

**`api/src/Registrations/Presentation/RegistrationController.php`**
- Added `RegistrationSubmission` import and constructor parameter (alphabetically between `$registrationGameSelection` and `$reserveRegistration`)
- New endpoint: `POST /api/v1/registrations/{registrationId}/submit` → `submitRegistration()`
  - 404 if null
  - 422 with `$result['code']` / `$result['message']` if outcome is error
  - 200 with `data: { registrationId, eventTitle, selectedGameIds }` + `meta.message` on confirmed

**`api/tests/Functional/RegistrationSubmitTest.php`** (new - 7 tests, 37 assertions)
- Anonymous 401
- Unknown registration 404
- Wrong-user 404
- Cancelled registration 404
- Game selection enabled, no games selected → 422 with `code: 'games_required'`
- Game selection enabled, games selected → 200 with correct data shape
- Game selection disabled → 200 confirmed without games requirement

**`api/tests/Functional/RbacEnforcementTest.php`**
- Added `['method' => 'POST', 'path' => '/api/v1/registrations/nonexistent/submit']` to `protectedRequests()`

### Frontend

**`frontend/src/app/evenements/[eventSlug]/inscription/[registrationId]/recap/page.tsx`** (new)
- Page shell wrapping `<RegistrationRecapGate params={params} />`
- `robots: { index: false, follow: false }` - private page

**`frontend/src/features/events/registration-recap-gate.tsx`** (new)

**Data loading:**
- Checks auth via `GET /account/profile` - redirects to `/connexion` on 401/403
- Loads recap data from `GET /api/v1/registrations/{id}/game-selection` (reuses existing endpoint)
- `parseRecapData()` - extracts `registrationId`, `eventTitle`, `gameSelectionEnabled`, `selectedGameIds`, `selectedGamesWithOptions` (filtered to only selected games)

**Gate states:** `loading` → spinner | `not_found` → XCircle + link | `error` → AlertCircle + message | `data` → recap view

**Recap view:**
- `RegistrationProgressIndicator` - step 2 (Récapitulatif) active, steps 0–1 shown as ✓
- Event name section
- Games section (only when `gameSelectionEnabled`): per-game `GameRecapCard` showing key options (basic/required) with current values; "-" in red for null values
- "← Modifier ma sélection" link back to `/jeux`
- "Confirmer l'inscription" button → POSTs to `/api/v1/registrations/{id}/submit`
  - Disabled while submitting
  - 422 body error message shown inline
  - On success → transitions to `ConfirmationScreen`

**`ConfirmationScreen`:**
- Large CheckCircle icon + "Inscription confirmée !" heading
- Personalized message with `eventTitle`
- List of confirmed game names
- "← Retour à l'événement" link to `/evenements/${eventSlug}`

## API contract

```
POST /api/v1/registrations/{id}/submit
→ 200 { data: { registrationId, eventTitle, selectedGameIds }, meta: { message } }
→ 422 { error: { code: 'games_required', message: string, details: {} } }
→ 404 if registration not found / wrong user / cancelled
→ 401 if unauthenticated
```

### Review Findings

- [x] [Review][Patch] Final submission does not validate required randomizer options server-side; a registration with selected games but missing required option values is confirmed successfully [api/src/Registrations/Application/RegistrationSubmission.php:44]
