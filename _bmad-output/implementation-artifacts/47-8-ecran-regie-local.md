# Story 47.8 - L'écran régie lit les flux locaux

**Status:** review
**Epic:** 47 - Multistream des participants, partie 2 « Diffusion locale par MediaMTX »
(`_bmad-output/planning-artifacts/epics/epic-47-multistream.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-47-story-8-ecran-regie-local` (depuis la branche de la 47.7, PR empilée sur #1094)
**Dépend de:** 47.6 (état des flux), 47.2 (écran `/o/streams`), 47.4 (lecteur WHEP).

## Story

En tant que régie,
je veux que l'écran `/o/streams` montre aussi les écrans que les joueurs envoient au serveur média de la LAN,
afin de passer leurs parties à l'antenne sans qu'ils streament sur Twitch.

## Décisions

1. **`GET /api/v1/events/{id}/broadcast-streams`** : chaque entrée a un `kind`.
   - `twitch` : l'entrée actuelle, inchangée.
   - `local` : `userId`, `slug`, `displayName`, `whepUrl` (`https://<hôte>:8889/<chemin>/whep`). Une entrée par
     inscrit dont l'agent voit le flux en direct (47.6), inscription réservée, diffusion non refusée, événement avec
     serveur média.
   - Un joueur à la fois en local et sur Twitch n'apparaît qu'une fois, en local (latence de la LAN, pas de
     dépendance à Internet).
   - Les entrées locales passent en tête, puis Twitch.
2. **Écran `/o/streams`** : une case locale est un `WhepPlayer` (muet, reconnexion automatique, coupure vue en 3 s,
   47.4), sans la barre d'état de la page d'essai, avec le même cartouche de nom que les cases Twitch. Même mosaïque,
   même rotation, même plafond.
3. **Rafraîchissement** de l'écran : 15 s au lieu de 60 s, pour qu'une case disparaisse vite quand l'agent ne voit
   plus le flux (la lecture Twitch reste en cache côté API).
4. **Lecture publique** : l'endpoint reste public (une source OBS n'a pas de session). L'adresse WHEP n'est lisible
   que depuis la LAN, et la lecture n'y demande pas de clé (47.4) : un joueur de la salle qui lit l'endpoint peut
   voir les flux que la régie peut montrer. Accepté, ces flux sont faits pour passer à l'antenne.
5. La mosaïque publique (47.3) et la page de l'événement lisent `participant-streams`, pas cet endpoint : elles
   ignorent les flux locaux sans changement.

## Critères d'acceptation

1. Un joueur que l'agent voit en direct apparaît en `local` avec son adresse WHEP ; il disparaît quand l'agent ne le
   voit plus ou s'il refuse la diffusion.
2. Un joueur à la fois local et Twitch n'apparaît qu'une fois, en local.
3. `/o/streams` lit les cases locales en WHEP et les cases Twitch en iframe, dans la même grille.
4. `participant-streams` ne renvoie aucun flux local.

## Tests et gates

5. Fonctionnels : `broadcast-streams` avec flux local, refus, flux coupé, doublon Twitch.
6. Front : garde de type des deux sortes, rendu d'une case locale et d'une case Twitch, clés stables.
7. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 4) - Entrées `local` dans `broadcast-streams`.
- [x] **Task 2** (AC 3) - Écran `/o/streams` et lecteur WHEP sans barre.
- [x] **Task 3** (AC 5-7) - Tests, gates.

## Dev Agent Record

### Completion Notes

- API :
  - `ParticipantTwitchLinksQueryInterface::localBroadcastRegistrants` (DBAL), avec les mêmes filtres que la liste
    Twitch (réservée, diffusion acceptée, compte ni supprimé ni bloqué, événement non terminé). Jointure gauche sur
    le profil : un joueur sans profil passe sous le nom de son compte ;
  - `LocalStreamsState::liveRegistrations` et `MediaPublishCredentials::whepUrl` ;
  - `ParticipantStreamsView::forEventBroadcast` renvoie les entrées `local` triées par nom, puis les `twitch` sans
    les joueurs déjà en local.
- Front :
  - `BroadcastStream` (union `twitch` / `local`), garde `isBroadcastStream`, clé `broadcastStreamKey` ;
  - `BroadcastCell` lit une case locale avec `WhepPlayer bare` (la vidéo seule, le cartouche de nom reste celui de
    la case) ;
  - rafraîchissement à 15 s.
- Essai réel en local, en HTTPS :
  - montage : certificat mkcert pour `media.localtest.me`, MediaMTX 1.21.3, deux mires ffmpeg sur deux inscrits de
    la démo, l'agent et le serveur dev sur `localhost:3000` ;
  - l'écran montre les deux flux en 1280x720 côte à côte, avec leurs noms ;
  - une mire coupée quitte l'écran en 24 s (délai SRT de MediaMTX, passage de l'agent, rafraîchissement), l'autre
    reste ;
  - données de démo remises en l'état ; recette ajoutée au README de `ops/lan-media`.
- Sur `127.0.0.1:3000`, le serveur dev existant n'hydratait pas la page : il faut ouvrir `localhost`.
- Gates : suite API complète en ParaTest (3885 tests), PHPStan, cs-fixer, DDD, rector ; front typecheck, lint,
  1655 tests, build.

### File List

- api/src/Streaming/Application/Query/ParticipantStreamsView.php, ParticipantTwitchLinksQueryInterface.php
- api/src/Streaming/Infrastructure/Dbal/DbalParticipantTwitchLinksQuery.php
- api/src/Streaming/Application/Service/LocalStreamsState.php
- api/src/Streaming/Application/Support/MediaPublishCredentials.php
- api/tests/Functional/ParticipantStreamsTest.php, api/tests/Unit/Streaming/ParticipantStreamsViewTest.php
- frontend/src/features/streaming/participant-streams-api.ts, broadcast-screen.tsx, broadcast-screen.test.tsx,
  whep-player.tsx
- ops/lan-media/README.md
