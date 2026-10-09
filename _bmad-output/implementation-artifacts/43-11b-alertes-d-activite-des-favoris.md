# Story 43.11b: Alertes d'activité des favoris et préférences de notification

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-10
**Découpée de:** 43.11 (partie alertes)
**Dépend de:** 43.11a, 43.6

## Story

En tant que joueur,
je veux être prévenu de ce que font mes amis favoris,
afin de ne pas rater leur prochaine partie ou leur inscription à un événement.

## Contexte

Les notifications et le Web Push existent (`Notifier`, story 40.2), mais le caractère « poussable » est une liste
statique par type (`PushMessageFactory::PUSHABLE_TYPES`, `isPushable()` statique) : aucune préférence par
utilisateur.

## Critères d'acceptation

1. Pour chaque favori, le viewer reçoit une notification `friend_activity` quand l'ami :
   - s'inscrit à un événement à venir (`Registration` réservée) ;
   - lance une session d'événement ou une run perso ouverte aux amis (43.14), dont le viewer n'est pas déjà
     participant ;
   - atteint l'objectif d'une partie.
   Le réglage de présence de l'ami (43.6) prime : rien n'est annoncé à qui ne peut pas voir sa présence.
2. Anti-bruit : au plus une alerte par favori et par heure, et au plus 10 alertes `friend_activity` par jour et par
   destinataire.
3. Préférences dans `/compte` pour `friend_activity` : « Cloche + push » / « Cloche seulement » / « Rien ».
   Par défaut : cloche seulement.
4. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Domaine/Migration** : préférences de notification par (utilisateur, type), socle réutilisable.
- [x] **Application** : la décision « pousser ou non » devient une lecture des préférences du destinataire
      (en gardant `PUSHABLE_TYPES` comme valeur par défaut des types existants).
- [x] **Application** : identifier ou créer les points d'émission post-commit (inscription, lancement de
      session, objectif atteint) et y brancher un message Messenger `FriendActivityJob`.
- [x] **Front** : écran de préférences, `messageFor` / `hrefFor`.
- [x] Tests (plafonds, réglage de présence, préférences) et gates.

## Notes

- Les préférences par type serviront aussi à 43.1 (`run_invitation`) et 43.12 (`run_nudge`).
- Le lancement d'une run ouverte aux amis attend 43.14 ; sans elle, seul le lancement d'une session d'événement
  est émis.

## Dev Notes

- **Préférences** : `NotificationPreference` (`community_notification_preference`, migration
  `Version20261010110000`), enum `NotificationChannel` (`bell_push` / `bell` / `none`). Types configurables et
  défauts dans `NotificationPreferenceService::CONFIGURABLE` : `friend_activity` (cloche seulement),
  `run_invitation` et `slot_unblocked` (cloche + push, comme avant). Tout autre type garde la règle fixe de 40.2.
  Routes `GET /api/v1/community/notification-preferences` et `PUT .../{type}` (`{channel}`, 422 si type ou canal
  inconnu).
- `NotificationService::notify` lit le canal du destinataire : `none` = rien n'est enregistré (donc une alerte coupée
  ne compte pas dans les plafonds), push seulement si `bell_push` et que `PushMessageFactory` a un texte pour le type.
- **Émission** (post-commit, best-effort, `FriendActivityJob` asynchrone) : `ReserveRegistration` (réservation),
  `SessionLifecycleManager::transition` (premier passage en `running`, le même `shouldNotify` que les mails de
  lancement), `RecordSlotGoal` (goal d'un slot hors hebdo).
- **Handler** `FriendActivityJobHandler` :
  - acteurs = l'inscrit, les joueurs de la session ou ceux du slot (owners et co-joueurs via `DbalSlotPlayerSource`) ;
  - destinataires = ceux qui ont étoilé l'acteur (`FriendFavoriteRepositoryInterface::starredBy`), hors joueurs de
    la session ;
  - présence 43.6 : un acteur en « personne » n'est jamais annoncé (les autres réglages montrent la présence aux
    amis) ;
  - inscription : seulement un événement public à venir ; lancement : seulement une session d'événement (les runs
    perso attendent 43.14) ; une hebdo n'a pas de contexte et n'est jamais annoncée ;
  - titre et lien selon ce que le destinataire peut ouvrir (`FriendSessionContextQueryInterface`, 43.5) ;
  - anti-bruit : une alerte par job et destinataire (plusieurs favoris ensemble = « X et N autres favoris »), une
    alerte par favori nommé et par heure, 10 par 24 h glissantes (`DAILY_CAP`).
- Formulation neutre « s'inscrit à » (pas d'accord en genre).
- Front : `friendActivityTitle` (cloche), `messageFor` / `hrefFor` (événement, run, sinon profil de l'ami), carte
  « Ce qui me prévient » sur `/compte/notifications`.
- Tests existants ajustés : `CapacityNotificationTest` et `SessionLifecycleTest` comptaient tous les messages du
  transport ; ils filtrent désormais leur propre type.
- Tests : `FriendActivityTest` (inscription + plafond horaire, présence masquée, lancement hors joueurs et run perso
  ignorée, goal via le callback, plafond quotidien, préférences et push), `friend-activity-notification.test.tsx`,
  `notification-preferences-card.test.tsx`.
