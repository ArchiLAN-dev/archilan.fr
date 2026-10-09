# Story 43.1: Inviter des amis dans une run perso

**Status:** draft
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

- [ ] **Domaine** (`PersonalRuns`) : entité `RunInvitation` (statuts pending / accepted / declined / closed),
      règles de renvoi et de clôture ; tests unitaires.
- [ ] **Migration** + dépôt Doctrine.
- [ ] **Application** : commandes `InviteFriendsToRun`, `AcceptRunInvitation`, `DeclineRunInvitation` ; lecture
      des amis derrière une interface Application (pas d'import du domaine `Community`).
- [ ] **Présentation** : `POST /runs/{runId}/invitations`, `GET /runs/{runId}/invitations`,
      `POST /run-invitations/{id}/accept`, `POST /run-invitations/{id}/decline`, `GET /account/run-invitations`.
- [ ] **Notifications** : type `run_invitation` ajouté à `PUSHABLE_TYPES` ; `messageFor` / `hrefFor` côté front.
- [ ] **Front** : modale de sélection d'amis, bloc « Invitations » sur la page de run et dans `/compte/parties`.
- [ ] Tests fonctionnels (invitation, rejoindre, refus, blocage entre-temps, run terminée, plafonds) et gates.

## Notes

- La dépendance PersonalRuns vers Community passe par une interface Application (même schéma que
  `CommunityPresenceQueryInterface`) pour respecter `app:architecture:ddd`.
- Socle de 43.13 (inviter un groupe) et 43.14 (run ouverte aux amis).
- Rejoindre une run déjà lancée (`active` / `idle`) reste possible comme avec le lien ; l'invité arrive alors
  sans slot, comme aujourd'hui.
