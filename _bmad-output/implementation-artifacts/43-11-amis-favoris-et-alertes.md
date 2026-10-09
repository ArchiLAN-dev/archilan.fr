# Story 43.11: Amis favoris et alertes d'activité

**Status:** draft
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02
**Dépend de:** 43.6

## Story

En tant que joueur,
je veux marquer quelques amis comme favoris et être prévenu de ce qu'ils font,
afin de ne pas rater leur prochaine partie ou leur inscription à un événement.

## Contexte

Équivalent des favoris Xbox / Steam (épinglés en haut, alertes quand ils lancent un jeu). Les notifications et
le Web Push existent (`Notifier`, story 40.2), mais le caractère « poussable » est une liste statique par type
(`PushMessageFactory::PUSHABLE_TYPES`, `isPushable()` statique) : aucune préférence par utilisateur.

## Critères d'acceptation

1. Un ami peut être marqué « favori » (étoile) depuis `/compte/amis` ou son profil ; les favoris passent en tête
   dans l'annuaire filtré sur les amis et dans 43.5. Maximum 15 favoris.
2. Pour chaque favori, le viewer reçoit une notification `friend_activity` quand l'ami :
   - s'inscrit à un événement à venir (`Registration` réservée) ;
   - lance une session d'événement ou une run perso ouverte aux amis (43.14), dont le viewer n'est pas déjà
     participant ;
   - atteint l'objectif d'une partie.
   Le réglage de présence de l'ami (43.6) prime : rien n'est annoncé à qui ne peut pas voir sa présence.
3. Anti-bruit : au plus une alerte par favori et par heure, et au plus 10 alertes `friend_activity` par jour et par
   destinataire.
4. Préférences dans `/compte` pour `friend_activity` : « Cloche + push » / « Cloche seulement » / « Rien ».
   Par défaut : cloche seulement.
5. Être mis en favori n'est **pas** visible de l'ami concerné.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Domaine/Migration** : table `community_friend_favorite (user_id, favorite_user_id)`. Pas de drapeau sur
      `community_friendship` : la ligne est canonique et partagée par les deux parties (`pairKey`).
- [ ] **Domaine/Migration** : préférences de notification par (utilisateur, type), socle réutilisable.
- [ ] **Application** : la décision « pousser ou non » devient une lecture des préférences du destinataire
      (en gardant `PUSHABLE_TYPES` comme valeur par défaut des types existants).
- [ ] **Application** : identifier ou créer les points d'émission post-commit (inscription, lancement de
      session, objectif atteint) et y brancher un message Messenger `FriendActivityJob`.
- [ ] **Front** : étoile, tri, écran de préférences, `messageFor` / `hrefFor`.
- [ ] Tests (plafonds, réglage de présence, préférences, favori retiré par fin d'amitié ou blocage) et gates.

## Notes

- Story lourde. Découpage possible : **43.11a** favoris + tri (petit, sans notification), **43.11b** alertes +
  préférences de notification.
- Les préférences par type serviront aussi à 43.1 (`run_invitation`) et 43.12 (`run_nudge`).
- Fin d'amitié ou blocage : supprimer le favori dans les deux sens.
