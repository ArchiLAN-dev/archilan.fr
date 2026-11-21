# Story 41.73: Quêtes de la semaine, page dédiée et chargement progressif

**Status:** review (hotfix v1.0.2)
**Epic:** 41 - Pelles
**Date:** 2026-11-21
**Origine :** incident du 2026-11-21 (hotfix v1.0.1) : `/me/quests` dépassait 30 s une semaine d'événement et le bloc
des quêtes restait vide. Le hotfix a filtré les comptes par membre et indexé le fil ; Jean veut aussi une meilleure
UX et un calcul serveur plus solide.
**Dépend de :** 41.6, 41.15, 41.16, 41.17, 41.34, 41.68, 41.69 (quêtes, objectifs, coffre, historique, série, paliers,
quête collective).

## Story

En tant que membre,
je veux voir mes quêtes de la semaine tout de suite, puis ma progression dès qu'elle est calculée,
afin de savoir quoi faire sans attendre un calcul, et sans que la page casse quand le serveur est chargé.

## Contexte

- Aujourd'hui, une seule route (`GET /me/quests`, `MyWeeklyQuests::of`) renvoie tout d'un coup : les quêtes servies,
  leurs objectifs **avec la progression**, le coffre, la quête collective (total de la communauté et part du membre),
  l'historique et la série. La progression demande les requêtes lourdes sur le fil des sessions ; tout le reste est
  léger (planning de la semaine, `pelle_movement`).
- Le bloc vit au milieu de « Mes pelles » (`/compte/pelles`), entre le solde et l'historique des mouvements.
- Le total de la quête collective est le même pour tous les membres, mais il est recalculé à chaque affichage, pour
  chacun.

## Critères d'acceptation

1. **Deux temps côté API.**
   - `GET /me/quests` ne renvoie plus que ce qui est rapide : la semaine, la date de renouvellement, les quêtes
     servies (titre, description, récompense, paliers, cosmétique, objectifs avec leur cible, leur portée et leur
     unité, **sans** valeur courante), le fait qu'une quête soit déjà payée, le coffre (récompense, payé ou non),
     la quête collective (sans total), l'historique et la série. Aucune requête sur le fil des sessions.
   - `GET /me/quests/progress` renvoie la progression : la valeur courante de chaque objectif, quêtes faites, paliers
     atteints, nombre de quêtes faites pour le coffre, total et part du membre pour la quête collective.
2. **Calcul serveur borné.**
   - Le total de la quête collective est mis en cache (5 minutes), commun à tous les membres.
   - La progression d'un membre est mise en cache 1 minute.
   - Les requêtes de progression ont un délai maximal (`statement_timeout` de 10 s) : au-delà, la route répond
     `503 quests_progress_unavailable` au lieu d'occuper un worker PHP 30 s.
3. **Page dédiée `/compte/quetes`**, entrée « Quêtes » dans le menu du compte (groupe « Compte », avant « Pelles »).
   Elle montre les quêtes de la semaine, le coffre, la quête collective, la série et l'historique.
4. **Chargement progressif.**
   - Les quêtes s'affichent dès la première réponse, avec leurs objectifs.
   - Tant que la progression n'est pas arrivée, chaque barre d'objectif montre un squelette animé à la place du
     chiffre ; il en va de même pour le coffre et la quête collective.
   - Si la progression échoue, les quêtes restent affichées, avec un message « Progression indisponible pour le
     moment » et un bouton « Réessayer ».
   - Les animations respectent `prefers-reduced-motion`.
5. **« Mes pelles »** garde un résumé compact (quêtes faites sur servies, coffre, renouvellement) avec un lien
   « Voir mes quêtes » vers la page dédiée ; le détail et l'historique des quêtes quittent cette page.
6. **Compatibilité** : les liens existants vers les quêtes (notifications, accueil, annonce Discord) mènent à la page
   dédiée.
7. Gates verts ; tests :
   - API : `/me/quests` ne lit pas le fil (le résultat est complet sans aucun check) ; `/me/quests/progress` donne
     les mêmes chiffres qu'avant ; le cache du total collectif ; le 503 quand la requête dépasse son délai ;
   - front : squelettes pendant le chargement, erreur avec « Réessayer », résumé de « Mes pelles », entrée de menu.

## Tâches

- [x] API : découpage `MyWeeklyQuests` en définition et progression, nouvelle route (critère 1).
- [x] API : caches et délai maximal (critère 2).
- [x] Front : page `/compte/quetes`, menu, chargement progressif (critères 3 et 4).
- [x] Front : résumé sur « Mes pelles », liens existants (critères 5 et 6).
- [x] Tests et gates (critère 7).

## Hors périmètre

- Précalcul des compteurs (table de compteurs alimentée au fil de l'eau) : à envisager si le cache ne suffit pas.

## Dev Agent Record

Livrée en hotfix (v1.0.2), à la demande de Jean.

- Critère 1 : `MyWeeklyQuests::of()` (sans le fil : `progress` false, `current`, `chest.done`, `collective.total`,
  `collective.mine` et `collective.done` à null, une quête n'est faite qu'une fois payée) et `progress()` (le calcul
  complet), route `GET /api/v1/me/quests/progress`.
- Critère 2 : pool `weekly_quests.cache` (ArrayAdapter en test) ; progression gardée 60 s par membre et par semaine,
  total collectif 300 s ; `WeeklyQuestsQueryInterface::within()` fait le calcul dans une transaction avec
  `SET LOCAL statement_timeout` (10 s), SQLSTATE 57014 traduit en `ServiceUnavailableException`
  `quests_progress_unavailable` (503).
- Critères 3 et 4 : page `/compte/quetes` (`QuestsPanel` : quêtes, premiers pas, historique), entrée « Quêtes » du
  menu. Deux requêtes (`["my-quests"]`, `["my-quests", "progress"]`) : la vue prend la progression dès qu'elle
  arrive ; squelettes `animate-pulse motion-reduce:animate-none` sur les barres, le coffre et la jauge collective ;
  échec de la progression : quêtes gardées, « Progression indisponible pour le moment » et « Réessayer ».
- Critères 5 et 6 : `QuestsSummary` sur « Pelles » (nombre de quêtes, gain maximal, renouvellement, lien) ;
  notification `quests_renewed`, encart de l'accueil et annonce Discord vers `/compte/quetes`.
- Tests : `WeeklyQuestsTest` (vue rapide sans progression, cache d'une minute, délai coupé par `pg_sleep`),
  `QuestCollectiveTest` (vue rapide puis progression) ; front : squelettes, erreur de progression, quête payée
  pleine, résumé, lien de notification.
