# Story 9.8 - Admin UI Session Creation, Monitoring and Controls

Status: done

## Review Findings

- The session list did not present itself as a history section and did not show duration, despite the acceptance criterion requiring status, date, and duration.
- The detail view rendered duplicate YAML ZIP download buttons for `generated` sessions.
- Crashed sessions only showed the restart action, without any visible error or diagnostic state.
- Live SSE/action updates changed the detail card but did not keep the backing session list in sync, so returning to history could show stale statuses.

## Corrections

- The sessions list is now labeled as session history and displays creation date plus computed duration.
- Session updates now also update the in-memory history list.
- `generated` sessions now show a single YAML ZIP download action.
- Crashed sessions now display the runner error when available, with a fallback diagnostic message, before the restart control.

## Validation

- `pnpm lint -- src/features/admin/admin-session-page.tsx`
- `pnpm typecheck`

## Residual Test Gap

- No frontend test runner is configured in `frontend/package.json`, so I could not add the requested functional UI tests in this story without first introducing a test stack.
