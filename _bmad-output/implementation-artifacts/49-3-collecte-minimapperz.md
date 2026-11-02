# Story 49.3 - Collecter les parties et créditer les pelles

**Epic :** 49 - Minimapperz (`planning-artifacts/epics/epic-49-minimapperz.md`)
**Statut :** review
**Date :** 2026-11-02
**Branche :** `feature/epic-49-story-3-collecte-minimapperz` (partie de la 49.2)

## Story

En tant que joueur inscrit, je veux recevoir mes pelles d'event peu après chaque partie Minimapperz, afin de voir
mes trouvailles récompensées pendant la LAN.

## Critères d'acceptation

1. **Collecte.** Toutes les minutes (Scheduler, message routé `async`), pour chaque event dont l'intégration est
   activée **et qui est en cours** : `GET /games?since=` chez Minimapperz. Premier passage sans `since` ; ensuite
   `since` = fin de la partie la plus récente déjà vue, moins 10 minutes de recouvrement.
2. **Crédit.** Pour chaque joueur d'une partie dont le `code` appartient à un **inscrit actif** de l'event : pelles
   par partie, plus le bonus si `won`, en pelles d'event (`PelleReason::Minimapperz`). Libellé « Minimapperz : partie
   gagnée » ou « Minimapperz : partie jouée (Ne place) ». Les points Minimapperz ne fixent jamais un montant.
3. **Une fois.** Clé d'unicité `minimapperz:{partie}:{code}` (empreinte du code si la clé dépasse 120 caractères) :
   une partie lue plusieurs fois n'est créditée qu'une fois.
4. **Plafond.** Par membre et par event : la partie qui franchit le plafond rapporte ce qui reste, les suivantes
   rien. Vérifié aussi sous le verrou du ledger.
5. **Pendant l'event seulement.** Rien n'est lu avant le début ni après la fin de l'event ArchiLAN, et une partie
   finie avant le début n'est pas créditée. Raison : la conversion des pelles d'event en fin d'event s'écrit une
   fois par membre (`event-conversion:{event}:{membre}`), un crédit arrivé après ne serait jamais converti.
6. **Erreurs.** Clé refusée (401/403) : l'intégration se coupe et l'admin voit pourquoi. Minimapperz injoignable :
   journalisé, retenté à la passe suivante, curseur inchangé. Un membre banni ou supprimé est sauté, les autres
   continuent.
7. **Admin.** La vue des réglages ajoute `collection` (date, statut, parties lues, pelles créditées à la dernière
   passe, curseur). `GET .../minimapperz/players` liste les joueurs Minimapperz avec le membre relié, les non reliés
   en premier.
8. **Front.** Onglet Minimapperz : ligne d'état de la dernière collecte, bloc « Joueurs sur Minimapperz » chargé à la
   demande. Historique des pelles : raison « Minimapperz ».

## Tâches

- [x] Port : `games(slug, key, since)` et `players(slug, key)`, records `MinimapperzGame`, `MinimapperzGamePlayer`,
  `MinimapperzPlayer`, lecture stricte dans `MinimapperzHttpClient` (`since` envoyé en UTC).
- [x] `PelleReason::Minimapperz`, état de collecte sur `MinimapperzSetting` (migration `Version20261102120002`),
  `findEnabled()`, `holderOf()`.
- [x] Commande `CollectMinimapperzGames`, message + handler, `Schedule` toutes les minutes, routage `async`.
- [x] Requête `MinimapperzPlayers`, route admin `players`.
- [x] Tests : `MinimapperzCollectionTest` (9 fonctionnels), cas client dans `MinimapperzHttpClientTest`.
- [x] Front : état de collecte, `admin-minimapperz-players.tsx`, libellé de raison, tests jest.
- [x] Gates API et front verts.

## Notes de dev

- Modèle : `CreditBlindTestSource` (plafond vérifié dans la transaction de `RecordPelleMovement`).
- Hypothèse non encore vérifiée chez Minimapperz : un code n'est utilisé que par un compte (question ouverte de
  l'epic). Si deux comptes partagent un code, les deux parties sont créditées au même membre, dans la limite du
  plafond.
- À dire à l'organisation : terminer la LAN Minimapperz avant la fin de l'event ArchiLAN ; les parties de la
  dernière minute peuvent ne pas être lues.
