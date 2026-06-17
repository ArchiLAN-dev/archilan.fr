# Story 17.10: relaunch an idle run even without a save (restart from the seed)

Status: done

Repo: `archilan.fr` (monorepo, `api/` + `frontend/`) - branch from `develop`.

## Story

As the owner of a private run that went idle **without a usable save**,
I want to relaunch it without recreating the whole private game,
so that I keep my configuration, slots and invite link - the game simply restarts from its generated
seed when no save can be reloaded.

## Context

After the epic-17 restart redesign, `initiateRestart` still blocked resume when
`pausedWithoutSave` (or a null save key), forcing the owner to delete + recreate the run. But the
orchestrateur's `relaunch-from-save` (story 17.6) already handles the no-save case gracefully: it
re-creates the AP server on the **retained volume**, where AP loads the latest `.apsave` if present
and otherwise **starts fresh from the multidata** (the generated seed). So the only blocker was the
Symfony gate. A paused-without-save run should be relaunchable (progress restarts) - no recreation.

## Acceptance Criteria

1. `SessionLifecycleManager::initiateRestart` no longer returns `no_save_available`: an **idle**
   session is relaunchable whether or not it has a save. (Status/ownership checks unchanged.)
2. When there is no save key, `ResumeRunJob` is still dispatched (empty save key); the orchestrateur
   relaunch loads the save if present, else restarts from the seed.
3. Frontend IDLE panel: the relaunch button is **active in both cases**. Copy sets expectations:
   - with save → "Reprendre … la dernière sauvegarde sera chargée" (button "Reprendre manuellement");
   - without save → "aucune sauvegarde : la partie redémarrera depuis le début (même configuration)"
     (button "Relancer depuis le début").
4. Tests updated: the former `no_save_available` cases now return `202` + `restarting`; existing
   owner/admin/403 and `/restarted` callback tests stay green.
5. Gates green: API (phpstan, php-cs-fixer, phpunit, app:architecture:ddd), Frontend (typecheck, lint,
   build).

## Tasks / Subtasks

- [x] Task 1 - Remove the `no_save_available` gate (and the save-key assert) in `initiateRestart`;
      dispatch `ResumeRunJob` with `getLastSaveKey() ?? ''` (AC 1, 2).
- [x] Task 2 - Frontend: active button + conditional copy/label for the no-save case (AC 3).
- [x] Task 3 - Update `SessionRestartTest` (former 422 cases → 202) (AC 4).

## Dev Notes

- The orchestrateur relaunch requires the session's generated output (multidata), which always exists
  for a session that has run; the retained volume holds it. No new backend call - reuses 17.8's
  `ResumeRunJobHandler` → `RunnerGatewayInterface::relaunchFromSave`.
- Without a save, relaunching discards any unsaved progress (there is none recoverable anyway). The UI
  copy states this; no confirm modal (deletion already has one).
- Supersedes the interim "can't be resumed, delete it" copy from the #103 contradiction fix.

### References

- `api/src/Sessions/Application/SessionLifecycleManager.php` - `initiateRestart`.
- Orchestrateur relaunch (no-save = fresh seed): story 17.6, `archipelago/ap_server.sh` (loads
  `*.zip`/`.archipelago` from `/data/output`; `.apsave` auto-loaded if adjacent).
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` - IDLE panel.

## Dev Agent Record

## Change Log

- 2026-06-10 - Story created and implemented (status: review).
