# Story 47.1 - Le joueur accepte que la régie diffuse son stream

**Status:** ready-for-dev
**Epic:** 47 - Multistream des participants (`_bmad-output/planning-artifacts/epics/epic-47-multistream.md`)
**Date:** 2026-10-31
**Branche:** `feature/epic-47-story-1-consentement-regie` (depuis `develop`)

## Story

En tant que joueur inscrit à un événement qui streame,
je veux dire si la régie ArchiLAN peut rediffuser mon stream,
afin qu'on ne montre sur la chaîne de l'association que ceux qui sont d'accord.

En tant que régie,
je veux la liste des joueurs en live qui ont accepté,
afin de construire l'écran OBS de la story 47.2.

## Contexte

- `DbalParticipantTwitchLinksQuery::forEvent()` (contexte Streaming) lit déjà la table `registration` : inscriptions
  `reserved` d'un événement non terminé, jointes au compte et au profil communautaire (chaîne Twitch lue dans les
  liens sociaux). `ParticipantStreamsView` ajoute le statut live (un appel Helix en cache) et trie les lives en tête.
- Le joueur retrouve son inscription sur `/evenements/[eventSlug]/inscription/[registrationId]/recap`.
- L'admin voit les inscriptions sur `/admin/evenements/[eventId]/inscriptions` (`RegistrationList`, cellule
  Participant déjà enrichie par la story 46.3).

## Décisions

1. **Le consentement vit sur l'inscription**, pas sur le compte : on accepte pour un événement donné. Colonne
   `registration.broadcast_consent`, `false` par défaut ; les inscriptions existantes partent à `false`.
2. **Le joueur seul le change**, sur sa propre inscription, tant qu'elle est confirmée (`reserved`) et que
   l'événement n'est pas terminé. Route `PUT /api/v1/registrations/{registrationId}/broadcast-consent`
   `{consent: bool}`. Un admin ne le coche pas à la place du joueur.
3. **Lecture régie** : `GET /api/v1/events/{eventId}/broadcast-streams`, publique comme participant-streams (une source
   OBS n'a pas de session), qui ne renvoie que les lives ayant accepté, au même format. Elle réutilise
   `ParticipantStreamsView` (même cache Twitch). Événement inconnu 404, terminé liste vide.
4. **Libellé** de la case sur le récap de l'inscription : « La régie ArchiLAN peut diffuser mon stream Twitch
   pendant l'événement », avec une ligne d'aide : « Seulement si ta chaîne Twitch est dans les liens de ton profil.
   Tu peux changer d'avis jusqu'à la fin de l'événement. »
5. **Admin** : une icône « Diffusable » dans la cellule Participant des inscriptions qui ont accepté, et le champ
   `broadcastConsent` dans la lecture admin des inscriptions.

## Critères d'acceptation

1. Le joueur coche ou décoche la case sur le récap de son inscription ; l'état est gardé au rechargement.
2. La route refuse : un autre utilisateur (403), une inscription inconnue (404), une inscription annulée ou un
   événement terminé (422), un corps invalide (400), sans session (401).
3. `broadcast-streams` ne renvoie que les inscrits confirmés, en live et qui ont accepté.
4. L'admin voit l'icône sur les inscriptions qui ont accepté.
5. Tests : domaine, fonctionnels (route, refus, lecture régie), front (case, appel, icône admin).
6. `composer gates` et `pnpm gates` verts ; vérification réelle.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1-3) - API : colonne et migration, méthode du domaine, commande, route, lecture régie.
- [ ] **Task 2** (AC 1, 4) - Front : case sur le récap, icône dans la liste admin.
- [ ] **Task 3** (AC 5-6) - Tests, gates, vérification réelle.

## Dev Agent Record

### Agent Model Used

### Completion Notes List

### File List
