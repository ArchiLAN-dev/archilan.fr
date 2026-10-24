# Story 16.24 - La fanfare de goal est coupée par défaut

**Status:** review
**Epic:** 16 - Parties privées
**Date:** 2026-10-22
**Origine :** demande de Jean le 2026-10-22 : « Faudrait ne pas activer par défaut la musique d'atteinte de goal ».

## Constat

À l'atteinte d'un goal, `GoalCelebration` joue une fanfare chiptune (`useChiptune`). Le choix vient de
`localStorage` (`archilan_fanfare`, réglé par le `FanfarePicker` des pages de slot d'une partie privée et d'une hebdo).
Sans choix enregistré, `readFanfarePref()` renvoie `random` : tout joueur qui n'a jamais ouvert le sélecteur entend
une fanfare au hasard.

L'overlay de stream « goals » (story 29.2) affiche la même célébration. Son paramètre `sound` (`sound=1`) est
documenté mais jamais branché : la célébration y joue toujours, au choix du `localStorage` de la source OBS, donc
`random`.

## Critères d'acceptation

1. Sans préférence enregistrée, aucune fanfare : `readFanfarePref()` renvoie `disabled`.
2. Une préférence enregistrée (une fanfare, `random` ou `disabled`) est respectée comme avant.
3. Le sélecteur affiche « désactivée » comme état initial d'un joueur sans préférence.
4. Overlay « goals » : la fanfare suit le paramètre `sound` (`sound=1` = une fanfare au hasard, sinon rien), au lieu
   du `localStorage` de la source.
5. Tests : préférence par défaut, préférence enregistrée respectée, overlay silencieux sans `sound=1`.

## Tâches

- [x] `use-fanfare-preference.ts` : défaut `disabled`.
- [x] `GoalCelebration` / `useChiptune` : fanfare imposée optionnelle ; l'overlay passe `sound`.
- [x] Tests, `pnpm gates`.

## Dev Agent Record

### Completion Notes

- `readFanfarePref()` : défaut `disabled` (côté serveur, sans valeur stockée ou valeur inconnue). Une valeur stockée
  (`random` compris) est respectée : un joueur qui a choisi « aléatoire » l'entend toujours.
- `useChiptune(forced?)` et `GoalCelebration` (`fanfare?`) : une fanfare imposée remplace le choix stocké.
  L'overlay « goals » passe `goalsFanfare(params)` (`overlay-params.ts`) : `sound=1` donne `random`, sinon rien.
  Changement visible pour les streamers : la fanfare de l'overlay demande désormais `&sound=1` dans l'URL de la
  source (le paramètre était documenté en 29.2 mais n'était pas branché ; le panneau des liens d'overlay ne
  l'expose pas).
- AC3 : le sélecteur lit la même fonction, il s'ouvre donc sur « désactivée » sans préférence.
- Tests : `use-fanfare-preference.test.ts` (défaut, serveur, valeur inconnue, valeurs stockées, overlay).
- `pnpm gates` OK (1223 tests).

### File List

- `frontend/src/features/reachability/use-fanfare-preference.ts`
- `frontend/src/features/reachability/use-chiptune.ts`
- `frontend/src/features/reachability/goal-celebration.tsx`
- `frontend/src/features/overlay/goals-overlay.tsx`
- `frontend/src/features/overlay/overlay-params.ts`
- `frontend/src/features/reachability/use-fanfare-preference.test.ts` (nouveau)
