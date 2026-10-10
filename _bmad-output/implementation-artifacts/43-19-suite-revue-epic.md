# Story 43.19: Suite de la revue de l'epic 43

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-10
**Dépend de:** 43.18

## Story

En tant qu'équipe ArchiLAN,
je veux traiter les constats restants de la revue de l'epic 43,
afin que les fonctions d'amis restent justes, rapides et cohérentes à l'usage.

## Contexte

Revue de l'epic 43 (2026-10-10) : les six points bloquants sont passés en 43.18 (#810). Cette story reprend les
autres constats, moyens et mineurs, regroupés par thème.

## Critères d'acceptation

### Runs perso (43.1, 43.12, 43.14, 43.17)

1. Annuler une run ferme ses invitations en attente et retire son ouverture (amis ou annonce) ; une run restaurée
   repart « Sur invitation ». Une run terminée ferme aussi ses invitations en attente.
2. Repasser une annonce par « Sur invitation » puis « Tous les membres » ne la rajeunit pas : son âge (et donc son
   expiration et son rang dans la liste) court depuis la première mise en ligne, tant qu'elle n'a pas expiré.
3. Deux relances simultanées vers le même joueur n'en envoient qu'une (mise à jour conditionnelle).
4. Une double demande de jointure (deux onglets) ne renvoie plus d'erreur 500.
5. Après une jointure, les deux listes (« Parties de tes amis », annonces) sont rafraîchies ; le réglage d'ouverture
   suit l'état du serveur.
6. Les invitations reçues se lisent sans une requête par invitation ; la liste des annonces est bornée.

### Duels hebdo (43.15)

7. Le plafond de duels compte la semaine entière (toutes les hebdos de la même semaine), en SQL.
8. Les notifications de duel mènent à une page où l'on peut répondre.
9. Le fil d'activité ne nomme pas un membre avec qui le lecteur a un blocage (adversaire d'un duel, nouvel ami).
10. Un membre suspendu ou banni ne peut pas gagner un duel ; les duels d'hebdos terminées ne sont plus relus à chaque
    affichage.

### Présence et suggestions (43.2, 43.5, 43.6, 43.7)

11. « Actifs récemment » compte toute session jouée dans les 24 h (dernier check, fin ou arrêt), pas seulement les
    sessions terminées, et montre la plus récente.
12. Le seuil « 2 sessions d'événement » des suggestions compte des événements distincts ; les sessions d'une run sont
    reconnues même après une relance.
13. Les requêtes de co-participation (suggestions, historique commun, faits sociaux) ne parcourent plus tout
    l'historique : elles partent des sessions du membre ; un slot non attribué ne compte pas comme un co-joueur.
14. Le texte d'aide du réglage de présence « Membres » parle d'adhérents.

### Communauté (43.10, 43.11, 43.13)

15. Quand un ami favori lance une run ouverte à ses amis, l'alerte d'activité part (43.11b, AC1).
16. Le plafond « une alerte par favori et par heure » tient compte de tous les favoris d'une alerte groupée.
17. Une alerte ne part pas pour un membre qui n'est plus ami ; retirer une amitié ou bloquer fait son ménage en une
    seule écriture.
18. Le bouton « Ajouter » du récap n'apparaît que sans relation existante ni blocage.
19. La notification push d'activité d'un favori mène au même endroit que la cloche.

20. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] Runs perso (AC 1 à 6)
- [x] Duels (AC 7 à 10)
- [x] Présence et co-participation (AC 11 à 14)
- [x] Communauté (AC 15 à 19)
- [x] Tests et gates

## Dev Notes

- **Runs perso**
  - `Run::cancel()` remet l'ouverture « Sur invitation » et efface l'annonce ; les deux chemins d'annulation de
    `PersonalRunDrafts` ferment les invitations en attente (`RunInvitationRepositoryInterface::closePendingForRun`).
    Une run terminée (complete, fin de session) n'est pas touchée en écriture : `RunInvitationsQuery::forRun` affiche
    « close » une invitation encore en attente sur une run terminale.
  - `openTo()` garde `listedAt` / `lastArrivalAt` ; `listForMembers()` ne remet l'âge à zéro que pour une première
    annonce ou une annonce expirée (`lastSignOfLife`).
  - Relance : `RunNudgeRepositoryInterface::claimNudge` (UPDATE conditionnel, une ligne touchée ou rien) pour une ligne
    existante ; la première relance garde l'insert + contrainte unique.
  - Double jointure : `AnswerRunInvitation::accept` verrouille la run comme `JoinOpenRun` (43.18) ; la seconde demande
    attend puis trouve le participant.
  - `RunRepositoryInterface::findByIds` pour les invitations reçues ; annonces limitées à 100.
  - Front : une jointure rafraîchit les deux listes ; `RunOpennessSetting` a une `key` (ouverture + `listedAt`).
- **Duels** : plafond compté par semaine ISO de l'hebdo (`WeeklyDuelContextQueryInterface::duelsCreatedInWeek`, SQL)
  ; `countCreatedSince` supprimé. Notification `weekly_duel` vers `/compte`. Un membre sans carte listable (banni,
  suspendu) est retiré avant le classement de la résolution. Les duels d'hebdos terminées ne traînent plus : la tâche
  de la 43.18 les résout dans le quart d'heure (pas de changement de `openDuelsOf`). La lecture qui applique les
  blocages (`forViewer`) reste en place.
- **Fil** : `CommunityFeedQuery` ne renseigne pas `withSlug` / `withName` quand le lecteur a un blocage avec ce
  membre (nouvel ami, adversaire d'un duel) ; un appel `existsEitherWay` par membre nommé distinct de la page.
- **Présence** : « Actifs récemment » = sessions non `running` dont `GREATEST(slot.last_check_at, s.finished_at)` est
  dans les 24 h (pas `stopped_at` : un crash ou un lancement raté n'est pas du jeu) ; slot affiché = le plus récent.
- **Co-participation** : suggestions et faits sociaux partent de `my_sessions` (sessions du membre) ; slot non attribué
  (`uid = ''`) écarté ; runs rattachées par `run.id = session.event_id` (toutes les sessions d'une run relancée) ;
  `COUNT(DISTINCT ...)` pour runs et événements. Historique commun : CTE limité aux deux membres.
- **Présence (texte)** : « Adhérents » et « Seuls les adhérents de l'association et tes amis… ».
- **Alertes** : lancement d'une run perso annoncé si elle était ouverte aux amis ou aux membres
  (`FriendActivitySourceQueryInterface::isRunOpenToFriends`) ; titre et lien restent réservés aux joueurs, comme la
  présence. Charge utile `actorIds` (tous les favoris d'une alerte groupée) prise en compte par le plafond horaire ;
  `actorSlug` pour le lien du push (profil). Le handler exige une amitié en cours.
- **Amitié** : retrait et blocage font le ménage (amitié, favoris, groupes) dans une transaction
  (`FriendshipRepositoryInterface::beginTransaction/commit/rollBack`).
- **Récap** : `canAdd` (aucune ligne d'amitié dans un sens ou l'autre, aucun blocage) ; le front n'affiche « Ajouter »
  que si `canAdd` n'est pas faux.
- **Tests** : `RunInvitationTest` (+1), `RunListingsTest` (+2), `WeeklyDuelTest` (plafond semaine), `CommunityFeedTest`
  (+1), `FriendsNowTest` (+1), `FriendSuggestionsTest` (+1), `FriendActivityTest` (+2 et run ouverte), `RecapExchangesTest` (+1).
- **Pas de migration.**
