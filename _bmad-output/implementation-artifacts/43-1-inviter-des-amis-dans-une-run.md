# Story 43.1: Inviter des amis dans une run perso

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02

## Story

En tant que propriétaire d'une run perso,
je veux inviter directement des amis depuis la page de la run,
afin qu'ils la rejoignent en un clic sans que j'aie à copier le lien d'invitation sur Discord.

## Contexte

Voir `_bmad-output/planning-artifacts/epics/epic-43-amis-utiles.md`. Aujourd'hui l'invitation passe uniquement
par le lien à jeton (`Run::inviteToken`, `GET /api/v1/runs/invite/{token}/preview`,
`GET /api/v1/runs/join/{token}`). Les notifications passent par `Notifier::notify()` et, pour les types
« poussables » (`PushMessageFactory::PUSHABLE_TYPES`), par Web Push (story 40.2). Rejoindre crée un
`RunParticipant` via `PersonalRunDrafts::joinByToken` (qui ne refuse aujourd'hui qu'une run annulée).

## Critères d'acceptation

1. Sur la page d'une run non terminale, le propriétaire voit un bouton « Inviter des amis » qui ouvre la liste
   de ses amis acceptés (recherche par pseudo), avec cases à cocher. Les amis déjà participants ou ayant une
   invitation en attente sont marqués et non cochables.
2. Valider crée une **invitation** par ami (run, invité, invitant, date) ; l'invité reçoit une notification
   `run_invitation` (« *X* t'invite dans *Titre de la run* ») qui part aussi en Web Push.
3. Depuis la notification ou `/compte/parties` (bloc « Invitations » en tête de page, au-dessus des parties
   créées et rejointes regroupées par la 16.22), l'invité voit l'invitation et peut **rejoindre** (même logique
   métier que le lien à jeton, extraite pour être partagée, mais en `POST`) ou **refuser**. Le propriétaire voit l'état de chaque invitation
   (en attente / acceptée / refusée).
4. Une invitation n'est valable que si l'amitié est toujours acceptée, sans blocage, et que la run n'est ni
   terminée ni annulée ; sinon, rejoindre renvoie une erreur métier claire et l'invitation est close.
5. Anti-spam : une seule invitation ouverte par (run, invité) ; une invitation refusée ne peut être renvoyée
   qu'après 24 h ; 20 invitations maximum par run et par jour.
6. Régénérer le lien à jeton n'annule pas les invitations nominatives ; terminer ou annuler la run les clôt.
7. Notification et push après commit, en asynchrone ; une erreur d'envoi n'annule jamais l'invitation.
8. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Domaine** (`PersonalRuns`) : entité `RunInvitation` (statuts pending / accepted / declined / closed),
      règles de renvoi et de clôture ; tests unitaires.
- [x] **Migration** + dépôt Doctrine.
- [x] **Application** : commandes `InviteFriendsToRun`, `AcceptRunInvitation`, `DeclineRunInvitation` ; lecture
      des amis derrière une interface Application (pas d'import du domaine `Community`).
- [x] **Présentation** : `POST /runs/{runId}/invitations`, `GET /runs/{runId}/invitations`,
      `POST /run-invitations/{id}/accept`, `POST /run-invitations/{id}/decline`, `GET /account/run-invitations`.
- [x] **Notifications** : type `run_invitation` ajouté à `PUSHABLE_TYPES` ; `messageFor` / `hrefFor` côté front.
- [x] **Front** : modale de sélection d'amis, bloc « Invitations » sur la page de run et dans `/compte/parties`.
- [x] Tests fonctionnels (invitation, rejoindre, refus, blocage entre-temps, run terminée, plafonds) et gates.

## Notes

- La dépendance PersonalRuns vers Community passe par une interface Application (même schéma que
  `CommunityPresenceQueryInterface`) pour respecter `app:architecture:ddd`.
- Socle de 43.13 (inviter un groupe) et 43.14 (run ouverte aux amis).
- Rejoindre une run déjà lancée (`active` / `idle`) reste possible comme avec le lien ; l'invité arrive alors
  sans slot, comme aujourd'hui.

## Dev Agent Record

### Notes

- Une invitation par (run, invité) : la ligne est rouverte quand on réinvite (refus de plus de 24 h, ou invitation
  close). Le plafond de 20 par jour compte les envois et renvois des 24 dernières heures ; un lot qui le dépasse
  est refusé en entier (429).
- Clôture paresseuse (AC 6) : une run terminée ou annulée disparaît des invitations reçues, et rejoindre clôt
  l'invitation (409). La suppression d'une run supprime ses invitations.
- `RunJoiner` porte la logique « rejoindre » partagée par le lien (`joinByToken`) et l'invitation : le
  participant et l'invitation acceptée partent dans le même flush.
- Notification `run_invitation` (payload `fromUserId`, `inviterName`, `invitationId`, `runId`, `runTitle`) envoyée
  après l'enregistrement ; `Notifier` est best-effort, le push part en asynchrone (`SendWebPushJob`).
- Lecture des amitiés via `RunInviteFriendsQueryInterface` (DBAL sur les tables community), sans import du domaine
  Community.

### File List

- `api/src/PersonalRuns/Domain/Entity/RunInvitation.php`, `Domain/Repository/RunInvitationRepositoryInterface.php`,
  `Infrastructure/Doctrine/DoctrineRunInvitationRepository.php`, `api/migrations/Version20261009110000.php`
- `api/src/PersonalRuns/Application/Command/InviteFriendsToRun.php` (+ `Result`, `Outcome`),
  `AnswerRunInvitation.php` (+ `Result`, `Outcome`)
- `api/src/PersonalRuns/Application/Query/RunInvitationsQuery.php`, `RunInviteFriendsQueryInterface.php`,
  `Infrastructure/Dbal/DbalRunInviteFriendsQuery.php`, `Application/Support/RunJoiner.php`
- `api/src/PersonalRuns/Application/Service/PersonalRunDrafts.php`, `Presentation/Controller/RunInvitationController.php`
- `api/src/Community/Application/Support/PushMessageFactory.php`, `api/config/services.yaml`
- Tests : `api/tests/Functional/RunInvitationTest.php`, `api/tests/Unit/PersonalRuns/RunInvitationTest.php`,
  `PushMessageFactoryTest`, `PersonalRunDraftsGetTest`, `PersonalRunDraftsListMineTest`
- `frontend/src/features/personal-runs/run-invitations-api.ts`, `run-invitations.tsx` (+ test),
  `personal-run-detail-page.tsx`, `personal-runs-list-page.tsx`
- `frontend/src/features/community/notification-center.tsx` (+ test), `notification-content.ts`
