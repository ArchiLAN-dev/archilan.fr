# Story 32.25: Les morts DeathLink gardées, et des conditions de succès sur les morts

**Status:** review
**Epic:** 32 - Recaps
**Date:** 2026-10-27
**Dépôts :** `Archipelago-Bridge` (dossier `bridge/`) et `archilan.fr` (API + frontend)
**Origine :** Jean le 2026-10-27, après la story 9.59 : connaître les morts d'une partie et en faire un type de
condition de succès.

## Story

En tant qu'admin des succès,
je veux des conditions sur les morts DeathLink (morts envoyées, joueurs emportés, record en une partie),
afin de récompenser (ou moquer) les joueurs qui meurent et tuent tout le monde avec eux.

## Contexte

- **Le bridge ne reçoit aucune mort aujourd'hui.** Le serveur AP ne transmet un `Bounce` qu'aux clients dont les
  tags croisent ceux du paquet (`MultiServer.py:2149-2160`). Le bridge se connecte avec `["TextOnly"]` seulement
  (`bridge/core/ap_client.py`, `Connect` de la connexion principale), donc `_handle_bounced` ne tourne jamais.
- Le bridge émet déjà un évènement de flux `{"type":"death_link","source","cause","timestamp"}`, poussé à Symfony
  (`feed-push`) et relayé en direct sur Mercure `runs/{id}/feed`. Symfony ne le garde pas :
  `SessionFeedEvent::PERSISTED_TYPES` = item-received, hint, goal (story 32.6 prévoyait d'ajouter les morts « si un
  usage apparaît »).
- `source` est le nom que le client du mort donne (en pratique le nom ou l'alias du slot). Les lignes du flux
  désignent les slots par leur nom (`sender_name`), le lien qu'utilisent déjà les requêtes de succès
  (`DbalItemsFromOthersQuery` : `session_slot` par `slot_name`, puis `DbalSlotPlayerSource` vers les membres).
- Story 9.59 : le bridge sait, par slot, si un client connecté joue avec DeathLink (`death_link_live`). Au moment
  d'une mort, les slots où c'est vrai, sauf celui du mort, sont les **victimes probables**.
- Succès : faits du catalogue `AchievementMetricCatalog` + un fournisseur `AchievementMetricProviderInterface`
  (tag auto) ; l'éditeur admin lit la liste des faits depuis l'API, sans changement front.

## Acceptance Criteria

1. **Le bridge reçoit les morts.** La connexion principale annonce `["TextOnly", "DeathLink"]`. Le bridge reste
   `TextOnly` (pas compté présent, story 16.18 ; pas de badge, story 9.59) et n'envoie jamais de mort de lui-même.
2. **Évènement enrichi.** L'évènement `death_link` garde `source`, `cause`, `timestamp` et gagne :
   - `sender` : `{slot, name, game}` du mort, `source` résolu par alias puis par nom de slot ; `name` est le nom
     du slot (celui des autres lignes du flux). Absent si `source` ne correspond à aucun slot ;
   - `victims` : noms des slots dont `death_link_live` est vrai à cet instant, sauf le mort, triés.
3. **Persistance.** `death_link` rejoint `PERSISTED_TYPES` (le type garde son nom : le relais Mercure en direct ne
   change pas). La ligne garde le mort dans `sender_*`, la cause dans `text`, et une nouvelle colonne JSON nullable
   `victim_names` (migration). Sans `sender` (bridge ancien ou nom inconnu), la ligne est gardée avec
   `sender_name` = `source`, pour ne rien perdre.
   `GET /sessions/{id}/feed` renvoie `victims` (liste, vide si aucune).
4. **Lecteurs du flux inchangés.** Toutes les lectures existantes filtrent par type (graphe, échanges, quêtes,
   items reçus, timeline, primes, bronze) : vérifier qu'aucune ne compte une mort.
5. **Faits de succès.**
   - `deathLinkDeaths` : morts envoyées par DeathLink (total), sur les slots que le membre joue ;
   - `deathLinkVictims` : joueurs emportés (total des victimes probables de ces morts) ;
   - `maxDeathLinkDeathsInSession` : record de morts envoyées en une même partie.
   Libellés explicites (« estimation » pour les victimes). Les tentatives hebdo, sans slot de session, ne comptent
   pas (comme `itemsFromOthers`).
6. **Timeline.** Le journal de la timeline (recap et page de partie) affiche une mort : badge « Mort », le joueur,
   la cause, « emporte X, Y » ; un filtre « Morts » rejoint « Indices » et « Objectifs ».
7. **Tests.** Bridge : tags de connexion, enrichissement (résolution, victimes, source inconnue). API : persistance
   (avec et sans `sender`), sortie du flux, requête des trois faits (slot co-joué, autre membre, hebdo ignorée),
   catalogue. Frontend : ligne et filtre du journal. Gates verts partout.

## Tasks / Subtasks

- [x] Bridge : tag DeathLink, `sender` et `victims` (AC 1, 2) + tests
- [x] API : type persisté, colonne `victim_names` + migration, sortie du flux (AC 3, 4)
- [x] API : faits, requête DBAL, fournisseur (AC 5) + tests
- [x] Frontend : journal et filtre (AC 6) + tests
- [x] Gates

## Dev Notes

- Limites à garder en tête (et dans les libellés) : seules les morts envoyées par DeathLink sont vues ; avec
  `death_link_amnesty`, un jeu n'en envoie qu'une toutes les N ; les victimes sont une estimation (un jeu peut
  ignorer une mort reçue en menu ou en cinématique).
- `POST /deathlink` (bridge, admin) envoie un `Bounce` que le bridge reçoit aussi désormais : sa `source` ne
  correspond à aucun slot, la ligne est gardée sans slot et ne compte pour aucun membre.
- Déploiement : bridge taggé et `BRIDGE_VERSION` relevé ; seules les sessions lancées ensuite enregistrent des
  morts. Migration API à passer.

## Dev Agent Record

### Completion Notes List

- Réalisation 2026-10-27.
  - Bridge (`feature/story-32-25-deathlink-deaths`) : `CONNECT_TAGS = ("TextOnly", "DeathLink")` sur la connexion
    principale (la sonde de connexion en tant que slot, story 9.30, reste `TextOnly`) ; `_handle_bounced` ajoute
    `sender` (`_slot_of_death_source` : alias puis nom de slot ; le nom émis est l'alias, comme les lignes d'items)
    et `victims`. 4 tests de plus dans `tests/test_death_link.py` ; 231 au total, ruff et mypy verts.
  - API : `SessionFeedEvent::TYPE_DEATH = 'death_link'` dans `PERSISTED_TYPES` (le nom du bridge est gardé : pas de
    correspondance dans `FeedPushController`, le relais Mercure en direct reste identique) ; colonne
    `victim_names` (JSON, migration `Version20261025200001`) ; `RecordSessionFeedEvent` met la cause dans le texte
    et retombe sur `source` sans `sender` ; `SessionFeedQuery` renvoie `victims`.
  - Lecteurs vérifiés (AC 4) : `FeedGraphBuilder`, `DbalRecapExchangesQuery`, `DbalItemsFromOthersQuery`,
    `DbalSharedHistoryQuery` (filtre item ; seul son `MIN(occurred_at)` de borne n'en a pas, sans effet), quêtes
    hebdo et de bienvenue (`DbalSlotCheckSource::types()`), `SettleItemBounties`, `CreditRunBronze`, et côté front
    les builders du recap (`item-received` seulement) et le journal.
  - Succès : `deathLinkDeaths`, `deathLinkVictims`, `maxDeathLinkDeathsInSession`
    (`DbalDeathLinkStatsQuery`, `DeathLinkMetricProvider`). Tests `DeathLinkStatsTest` (slot co-joué, source
    inconnue, autre type ignoré, passage par `MetricBagBuilder`) et `SessionFeedEndpointTest` (avec et sans
    `sender`).
  - Frontend : facette « Morts », ligne « Mort » dans `LogRowContent`, `victims` optionnel dans `FeedEvent`.
    Gates verts (1269 tests) avec `NEXT_PUBLIC_APP_URL` par défaut.
