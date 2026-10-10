# Story 43.14: Run ouverte aux amis

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02
**Dépend de:** 43.1

## Story

En tant que propriétaire d'une run perso en préparation,
je veux l'ouvrir à tous mes amis,
afin qu'ils la trouvent et la rejoignent sans que j'invite chacun.

## Contexte

Aujourd'hui une run perso n'est accessible que par lien ou (avec 43.1) invitation nominative. Les lobbies
Archipelago (ap-lobby, multiworld.gg) rassemblent un groupe avant la génération : la run en `draft` joue ce rôle.

## Critères d'acceptation

1. Une run `draft` a un réglage d'ouverture : Sur invitation (défaut, comportement actuel) / Amis.
2. « Amis » : la run apparaît dans un bloc « Parties de tes amis » (`/compte/parties` et encart 43.5) pour les
   amis acceptés du propriétaire, qui peuvent la rejoindre directement (même logique que 43.1).
3. Le propriétaire peut fixer un nombre de places (optionnel) ; la run disparaît du bloc quand elle est pleine,
   quitte `draft`, ou repasse « Sur invitation ».
4. Blocages respectés dans les deux sens.
5. Le propriétaire est notifié à chaque arrivée (`run_joined`, cloche seulement).
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Domaine** (`Run`) : `openness`, `seatsWanted` ; changement refusé hors `draft`.
- [x] **Migration**.
- [x] **Application** : `FriendsOpenRunsQuery`, commande `JoinOpenRun`.
- [x] **Présentation/Front** : réglage sur la page de run, bloc « Parties de tes amis », notification.
- [x] Tests (ouverture x relation, places, blocage, transitions) et gates.

## Notes

- L'ouverture à tous les membres, avec annonce publique, est la story 43.17.

## Dev Notes

- **Domaine** : `Run::openTo(openness, seatsWanted, now)` (draft seulement, `DomainException` sinon ; valeurs
  `invite` / `friends`, places 1 à `MAX_SEATS_WANTED` = 30, remises à null en « Sur invitation »),
  `isOpenToFriends()` (friends et draft), `isFull(joined)`. Les places comptent les membres entrés, propriétaire
  exclu (il peut avoir sa propre ligne `run_participant` pour ses slots).
- **Migration** `Version20261010140000` : `run.openness` (défaut `invite`), `run.seats_wanted` (nullable).
- **Application** :
  - `SetRunOpenness` (Updated / NotFound / Forbidden / Locked / Invalid).
  - `JoinOpenRun` (Joined / NotFound / Full) : même jointure que le lien et l'invitation (`RunJoiner`), même
    contrôle d'amitié et de blocage que 43.1 (`RunInviteFriendsQueryInterface::canInvite`). Une run non ouverte,
    ou un non-ami, reçoit NotFound (la run reste cachée). Une invitation par nom en attente pour la même run passe
    à `accepted`. Le propriétaire reçoit `run_joined` (hors `CONFIGURABLE` et hors `PushMessageFactory` : cloche
    seulement), après l'enregistrement.
  - `FriendsOpenRunsQuery::forViewer` via `DbalFriendsOpenRunsQuery` (amitié acceptée, aucun blocage dans un sens
    ou l'autre, run draft ouverte, viewer pas déjà dedans) ; les runs pleines sont filtrées.
- **Routes** : `PUT /api/v1/runs/{runId}/openness` `{openness, seatsWanted}`, `GET /api/v1/account/friends-open-runs`,
  `POST /api/v1/runs/{runId}/join-open` (404 si plus ouverte, 409 si pleine). La fiche de run expose `openness`
  et `seatsWanted`.
- **Front** : `friends-open-runs-api.ts`, `friends-open-runs.tsx` (`FriendsOpenRuns`, `RunOpennessSetting`,
  `seatsLabel`). Réglage sous les invitations dans l'en-tête de la run (propriétaire, draft) ; bloc sur « Mes
  parties » sous les invitations et dans l'encart « Mes amis en ce moment » (43.5) ; `run_joined` dans la cloche,
  lien vers la run.
- **Tests** : `RunOpenToFriendsTest` (5), `friends-open-runs.test.tsx` (4) ; `friends-now-card.test.tsx` mocke
  désormais `next/navigation`.
- **A déployer** : la migration `Version20261010140000`.
