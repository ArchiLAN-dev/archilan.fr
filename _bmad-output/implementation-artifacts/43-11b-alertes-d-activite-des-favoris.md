# Story 43.11b: Alertes d'activité des favoris et préférences de notification

**Status:** draft
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

- [ ] **Domaine/Migration** : préférences de notification par (utilisateur, type), socle réutilisable.
- [ ] **Application** : la décision « pousser ou non » devient une lecture des préférences du destinataire
      (en gardant `PUSHABLE_TYPES` comme valeur par défaut des types existants).
- [ ] **Application** : identifier ou créer les points d'émission post-commit (inscription, lancement de
      session, objectif atteint) et y brancher un message Messenger `FriendActivityJob`.
- [ ] **Front** : écran de préférences, `messageFor` / `hrefFor`.
- [ ] Tests (plafonds, réglage de présence, préférences) et gates.

## Notes

- Les préférences par type serviront aussi à 43.1 (`run_invitation`) et 43.12 (`run_nudge`).
- Le lancement d'une run ouverte aux amis attend 43.14 ; sans elle, seul le lancement d'une session d'événement
  est émis.
