# Story 11.10 - Co-joueurs d'un slot de session d'événement

**Status:** review
**Branche:** `feature/epic-11-story-10-co-joueurs-slot-event` (depuis `feature/epic-11-story-9-selection-des-slots`)
**Epic:** 11 - Session Management UX/UI Overhaul (`_bmad-output/planning-artifacts/epics/epic-11-session-management-uxui-overhaul.md`)
**Date:** 2026-11-10
**Dépend de:** 11.9 (choix des slots d'une session), 16.17 (co-joueurs des parties perso).

## Story

En tant qu'admin d'un événement,
je veux ajouter des co-joueurs à un slot d'une session d'événement,
afin qu'un inscrit qui n'a pas son propre slot dans cette session y ait quand même accès (nom de slot, connexion,
suivi) en jouant sur le slot d'un autre.

## Contexte

Demande de Jean le 2026-11-10, juste après la 11.9 : avec la sélection des slots, des inscrits à l'événement peuvent
ne pas être dans une session créée. Ils doivent pouvoir y entrer comme co-joueurs d'un slot.

Existant à réutiliser : `Sessions\Domain\Entity\SlotCoPlayer`, `SlotCoPlayerRepositoryInterface` et
`SlotCoPlayerRoster` (story 16.17), aujourd'hui pilotés par `PersonalRuns\Application\Service\RunSlotCoPlayers` (le
propriétaire de la partie remplace la liste entière ; le co-joueur ne touche jamais au YAML du slot).

## Décisions de Jean (2026-11-10)

1. Les co-joueurs sont ajoutés par l'admin **et** par le titulaire du slot.
2. Éligibles : les inscrits **confirmés** de l'événement.
3. Un inscrit déjà titulaire d'un slot de la session peut aussi être co-joueur d'un autre slot.
4. Le co-joueur a **tout** l'accès du titulaire pour ce slot : nom de slot, connexion, page de la session, suivi,
   notifications.
5. Le co-joueur **n'a pas le droit d'appeler le Passeur**.

6. Le co-joueur compte dans les succès et les statistiques comme ayant joué le slot (comme sur les parties perso,
   story 16.17).

## Critères d'acceptation

1. Sur une session d'événement, l'admin et le titulaire d'un slot ajoutent ou retirent des co-joueurs, choisis
   parmi les inscrits confirmés de l'événement (le titulaire exclu).
2. Un co-joueur, même sans slot à lui dans la session, y a le même accès que le titulaire pour ce slot.
3. Le YAML du slot reste celui du titulaire ; le co-joueur ne le modifie pas.
4. Un appel du Passeur par un co-joueur est refusé, sans débit.
5. Retirer le co-joueur lui retire l'accès.

## Dev Agent Record

- API :
  - `Registrations/Application/Service/EventSlotCoPlayers` : `view` (admin, titulaire, co-joueurs ; candidats =
    inscrits confirmés hors titulaire, pour qui gère) et `replace` (admin ou titulaire, liste entière, diff comme
    `RunSlotCoPlayers`, recalcul des succès). Réutilise `SlotCoPlayer`, `SlotCoPlayerRoster`.
  - `EventSlotCoPlayersController` : `GET` et `PUT /api/v1/events/{eventId}/slots/{slotId}/co-players`.
  - Connexion et patchs : `PlayerConnectionQueryInterface::findLatestSessionIdForPlayer(eventId, registrationId,
    userId)` trouve aussi la session où le membre joue un slot en co-joueur, limitée à l'événement. La connexion
    renvoie `eventId`, et par slot `slotId` et `coPlayed`.
  - Passeur : `SessionQuery::doesUserHoldSlot` (titulaire seul sur une session d'événement ; une partie perso garde
    sa règle). L'offre renvoie `canCall` ; l'appel d'un co-joueur est refusé (403) sans débit.
  - Accès déjà en place pour un co-joueur (inchangés) : indices, suivi, statistiques, succès (`SlotsPlayedBy`,
    `DbalSlotPlayerSource`), autorisation de session (inscription confirmée).
- Front :
  - `SlotCoPlayers` (parties perso) reçoit la sauvegarde en `onSave` ; `features/events/event-slot-co-players*`.
  - Admin : colonne « Co-joueurs » dans le tableau des slots du détail de session.
  - Joueur : sur la page de connexion, le titulaire gère les co-joueurs de chaque slot ; un slot joué en co-joueur
    est signalé.
  - Passeur : bouton désactivé et message pour un co-joueur.
- Hors périmètre : nettoyage des co-joueurs quand un slot est retiré d'une inscription (lignes sans effet, le slot
  n'étant plus dans une nouvelle session).
- Tests : `EventSlotCoPlayersTest` (4), `CallThePasseurTest` (+1), `event-slot-co-players.test.ts` (4),
  `passeur.test.tsx` (+1). Gates API et front verts.
