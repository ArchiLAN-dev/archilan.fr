# Story 47.9 - Le suivi côté admin

**Status:** review
**Epic:** 47 - Multistream des participants, partie 2 « Diffusion locale par MediaMTX »
(`_bmad-output/planning-artifacts/epics/epic-47-multistream.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-47-story-9-suivi-admin` (depuis la branche de la 47.8, PR empilée sur #1095)
**Dépend de:** 47.6 (état des flux).

## Story

En tant qu'orga, le jour J,
je veux voir d'un coup d'œil si l'agent de la régie tourne et qui diffuse,
afin de repérer un joueur qui n'arrive pas à envoyer son écran, ou un agent arrêté.

## Décisions

1. **`GET /api/v1/admin/events/{id}/local-streams`** (admin) :
   - `mediaServerHost` (null : diffusion locale non configurée) ;
   - `agentSeenSecondsAgo` (null : agent jamais vu), `liveCount` ;
   - `streams` : un élément par inscrit déjà vu, `registrationId`, `live`, `cutMinutesAgo` (null s'il est en direct).
   Les durées sont calculées par l'API : l'écran n'a pas besoin de l'horloge du navigateur.
2. **Hub de l'événement**, groupe Jour J : entrée « Serveur média », vers la fiche de l'événement (où se règlent le
   serveur et l'agent). État :
   - « Non configuré » sans serveur média ;
   - « Agent jamais vu » ;
   - « Agent vu il y a X s - N en direct » tant que l'agent envoie (30 s au plus) ;
   - « Agent muet depuis X min » au-delà.
   Relu toutes les 15 s.
3. **Page des inscriptions** : colonne « Diffusion locale », seulement quand l'événement a un serveur média :
   « En direct », « Coupé il y a X min », ou « Jamais ». Relue toutes les 15 s.
4. **Procédure** : le panneau « Configuration de l'agent » (47.6) gagne un lien vers `ops/lan-media` sur GitHub.

## Critères d'acceptation

1. L'admin lit l'état ; un non-admin reçoit 403, un événement inconnu 404.
2. Le hub affiche l'état de l'agent selon les quatre cas.
3. La page des inscriptions montre la colonne avec un serveur média, et pas sans.

## Tests et gates

4. Fonctionnel : endpoint (sans serveur, agent jamais vu, flux en direct et coupé, 403, 404).
5. Front : libellés du hub (quatre cas), cellule de la colonne (trois cas), garde de type.
6. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Endpoint admin.
- [x] **Task 2** (AC 2) - Entrée du hub.
- [x] **Task 3** (AC 3) - Colonne de la page des inscriptions, lien de procédure.
- [x] **Task 4** (AC 4-6) - Tests, gates.

## Dev Agent Record

### Completion Notes

- `GET /api/v1/admin/events/{eventId}/local-streams` (admin) : `mediaServerHost`, `agentSeenSecondsAgo`, `liveCount`, et par inscription `live` / `cutMinutesAgo`. Les durées sont calculées par l'horloge de l'API : les écrans n'ont pas d'horloge à lire pendant le rendu. 404 sur un événement inconnu.
- Hub, section Jour J : entrée "Serveur média" (vers la fiche de l'événement) avec 4 états : "Non configuré", "Agent jamais vu", "Agent vu il y a X s - N en direct", "Agent muet depuis X min" (plus de 30 s sans rapport). Rafraîchie toutes les 15 s.
- Page des inscriptions : colonne "Diffusion locale" (En direct / Coupé il y a X min / Jamais) seulement quand l'événement a un serveur média ; même clé de requête que le hub.
- Panneau de configuration de l'agent : lien vers la procédure `ops/lan-media` sur GitHub.
- Gates : API (rector, cs-fixer, phpstan, ddd, rector dry-run, 3886 tests en parallèle) et front (typecheck, lint, 1663 tests, build) verts.

### File List

- `api/src/Streaming/Application/Service/AdminLocalStreams.php` (nouveau)
- `api/src/Streaming/Presentation/Controller/LocalStreamsController.php`
- `api/tests/Functional/LocalStreamsReportTest.php`
- `frontend/src/features/admin/admin-events-api.ts`
- `frontend/src/features/admin/admin-local-streams.test.tsx` (nouveau)
- `frontend/src/features/admin/admin-registration-dashboard.tsx`
- `frontend/src/features/admin/event-hub/admin-event-hub.tsx`
- `frontend/src/features/admin/event-hub/event-hub.test.tsx`
- `frontend/src/features/admin/media-agent-config.tsx`
