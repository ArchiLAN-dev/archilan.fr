# Story 16.27 - Co-organisateur d'une partie privée

**Status:** review
**Epic:** 16 - Parties privées
**Date:** 2026-11-22
**Issue:** #387
**Dépend de:** aucune.

## Story

En tant que propriétaire d'une partie privée,
je veux donner à un ou plusieurs participants les droits de la piloter,
afin que la partie continue quand je ne suis pas là.

## Décisions (Jean : « fait au mieux », 2026-11-22)

1. **Plusieurs co-organisateurs** possibles, choisis parmi les participants. Le rôle est porté par la participation
   (`run_participant.co_owner_since`) : un participant qui quitte la partie perd le rôle avec elle.
2. **Seul le propriétaire** nomme et retire un co-organisateur, à tout moment. Un co-organisateur ne nomme personne.
   Pas de transfert de propriété.
3. **Ce que le rôle ouvre** (ce qui manque quand le propriétaire est absent) :
   - lancer la partie, y compris le premier lancement, l'arrêter, la resynchroniser ;
   - régler l'override de configuration de la partie ;
   - le mot de passe admin du serveur (commandes `!admin`) et l'extrait du journal d'une génération échouée.
4. **Ce qui reste au propriétaire** : le **spoiler** (il dévoile tout le multiworld à quelqu'un qui joue), terminer
   ou annuler la partie, le lien d'invitation, les participants, le renommage, la visibilité du récap. Ouvrir le
   spoiler plus tard reste une ligne à changer si le besoin se confirme.
5. Chaque nomination et chaque retrait sont journalisés (`LoggerInterface` : qui, à qui, quand).

## Critères d'acceptation

1. Le propriétaire nomme et retire un co-organisateur parmi les participants
   (`PUT /api/v1/runs/{runId}/participants/{userId}/co-owner`, `{"coOwner": bool}`) ; tout autre appelant reçoit 403,
   un non-participant 404.
2. Un co-organisateur lance, arrête et resynchronise la partie, et règle son override de configuration.
3. Un co-organisateur n'a ni le spoiler, ni la fin ou l'annulation de la partie, ni la gestion des participants.
4. La fiche de la partie dit qui est co-organisateur, et montre au co-organisateur les contrôles qui lui sont ouverts.

## Tâches

- [x] Domaine : rôle sur `RunParticipant`, `Run::isStartAllowedFor` et règle d'administration.
- [x] Application : commande de nomination, contrôles délégués (lancement, arrêt, resynchronisation, override),
  payload (`isCoOwner`, `canAdminister`, `coOwner` par participant, mot de passe admin).
- [x] Front : bascule par participant pour le propriétaire, badge, contrôles du co-organisateur.
- [x] Tests et gates.

## Dev Agent Record

- Rôle : `run_participant.co_owner_since` (migration `Version20261122100002`), `RunParticipant::makeCoOwner/revokeCoOwner`,
  `Run::isAdministeredBy`, `Run::isStartAllowedFor(..., $isCoOwner)`.
- Nomination : `SetRunCoOwner` (journalisée), `PUT /api/v1/runs/{runId}/participants/{userId}/co-owner`.
- Délégué : lancement (premier compris) et fichiers requis, arrêt, resynchronisation (`forceReconcile`), override de
  configuration (sans trace admin pour un co-organisateur), mot de passe admin et extrait de journal.
- Inchangé (propriétaire seul) : spoiler, fin, annulation, suppression, lien d'invitation, invitations, renommage, récap.
- Front : `run-co-owner.tsx` (badge et bascule), fiche de partie gardée par `administers`.
- Tests : `RunCoOwnerTest` (7), `run-co-owner.test.tsx`.
