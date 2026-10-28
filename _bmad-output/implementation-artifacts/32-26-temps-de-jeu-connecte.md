# Story 32.26: Le temps de jeu, compté sur le temps connecté avec un vrai client de jeu

**Status:** review
**Epic:** 32 - Recaps
**Date:** 2026-10-27
**Dépôts :** `Archipelago-Bridge` (dossier `bridge/`) et `archilan.fr` (API + frontend)
**Origine :** Jean le 2026-10-27 : une stat de temps passé sur une partie, à la manière de Steam (temps où le jeu est
ouvert), en sachant de façon fiable quand plus aucune connexion de jeu ne tient un slot.
**Suite de :** [16.18](16-18-import-d-une-seed-generee-ailleurs.md) (présence par Join/Part),
[9.59](9-59-deathlink-sur-les-slots.md) (tags des clients par slot), [32.25](32-25-morts-deathlink-et-succes.md).

## Story

En tant que joueur,
je veux voir combien de temps j'ai joué sur une partie, et sur toutes mes parties,
afin de comparer mon temps de jeu avec celui des autres et de débloquer des succès d'assiduité.

En tant qu'admin des succès,
je veux des faits de temps de jeu (total, plus longue session d'une traite),
afin d'écrire des conditions de succès dessus.

## Définition

**Temps de jeu = temps pendant lequel au moins un client de jeu (ni `TextOnly`, ni `Tracker`, ni `HintGame`) est
connecté au slot.** Comme Steam : l'AFK compte, un client laissé ouvert compte. C'est assumé et dit dans les libellés
(« temps connecté »). Pas de pondération par l'activité.

## Contexte

### Ce que le serveur Archipelago fournit (`MultiServer.py`, source dans `runner/docs archipelago/Archipelago-main`)

- `Join` (PrintJSON) porte `tags` (ligne 976).
- `Part` ne porte **pas** de champ `tags` (`on_client_left`, ligne 1006), mais son **texte** dit quel client part :
  - client de jeu : `"<nom> (Team #1) has left the game. Client(0.6.x), [...]."` ;
  - autres : `"has stopped tracking"` (`Tracker`), `"has stopped viewing"` (`TextOnly`), `"has stopped hinting"`
    (`HintGame`) - table `_non_game_messages`, ligne 956 ;
  - le texte finit par la liste des tags du client : `Client(0.6.7), ['Tracker'].`
- Quand le **dernier** client d'un slot part, le serveur passe le statut du slot à `CLIENT_UNKNOWN` (0) et le publie
  sur la clé `_read_client_status_{team}_{slot}` (`on_client_left` ligne 991, `on_client_status_change` ligne 881).
  Un client peut faire `Get` et `SetNotify` sur cette clé. Limites :
  - le statut compte **tous** les clients, trackers et `TextOnly` compris ;
  - après le goal il reste figé à `CLIENT_GOAL` (30) : `update_client_status` refuse de revenir en arrière
    (ligne 2208) ;
  - sur le slot où le bridge est lui-même connecté (seed importée, story 16.18, et toutes les parties après 9.56), il
    ne retombe jamais à 0 tant que le bridge est là.

### Ce que fait le bridge aujourd'hui

- `_playing_clients[slot]` compte les clients de jeu (Join/Part, story 16.18), `_connected_slots` en dérive
  l'ensemble des slots occupés, exposé en `connected` par `/slots` et `/players`.
- **Bug :** `_client_left` reçoit `packet.get("tags", [])`, toujours vide pour un `Part`, donc tout départ est pris
  pour celui d'un client de jeu : un tracker qui se déconnecte fait tomber la présence du jeu encore ouvert, et
  `_slot_client_tags` (9.59) retire une mauvaise entrée.
- Au démarrage (ou redémarrage) du bridge, les clients déjà connectés n'envoient pas de `Join` : le bridge les ignore
  jusqu'à leur prochaine reconnexion.
- **Bug :** `_playing_clients` et `_slot_client_tags` ne sont jamais remis à zéro quand la connexion WebSocket au
  serveur AP tombe (`run_with_reconnect`, `core/ap_client.py:1589`, ne touche qu'à `ws_connected`). Serveur AP
  arrêté : `_connected_slots` garde les slots d'avant la coupure. Serveur AP relancé : les clients refont un `Join` et
  le compte double, puis ne retombe plus jamais à 0. Le badge DeathLink (9.59) et `connected` en souffrent déjà ; pour
  le temps de jeu, ce serait du temps compté serveur éteint.
- `_api_heartbeat_loop` (`core/loops.py:230`) appelle `POST /api/v1/internal/sessions/{id}/heartbeat` toutes les
  30 s, sans corps. Symfony ne fait que rafraîchir `lastHeartbeatAt` (`HeartbeatController`,
  `SessionLifecycleManager::heartbeat`). Pour une tentative hebdo, la session n'existe pas et la réponse est un simple
  accusé.

### Pourquoi compter au heartbeat plutôt qu'à chaque Join/Part

Un intervalle ouvert par un évènement « connecté » doit être fermé par un évènement « déconnecté ». Or un crash du
bridge, une mise en pause, un arrêt du serveur AP ou une requête perdue laissent l'intervalle ouvert, et il faut
deviner quand le fermer. En comptant au heartbeat (« ces slots sont occupés maintenant »), chaque ligne n'est
prolongée que tant que les heartbeats arrivent : rien à fermer, et une panne coûte au plus un intervalle de
heartbeat.

## Acceptance Criteria

1. **Présence exacte au départ d'un client (bridge).** Pour un `Part`, le bridge déduit si le client était un client
   de jeu à partir du texte : liste de tags en fin de texte si elle se lit, sinon le verbe (`has left the game` = jeu,
   `has stopped …` = autre). Un tracker, un `TextOnly` ou un `HintGame` qui part ne change plus la présence ni les
   tags DeathLink (9.59) du client de jeu resté connecté ; `_slot_client_tags` retire l'entrée qui porte ces tags
   (à défaut la dernière, comme aujourd'hui). Si le texte ne se lit pas (format inconnu), comportement actuel conservé
   (départ compté comme client de jeu).
2. **Présence remise à zéro à chaque connexion (bridge).** À chaque (re)connexion WebSocket au serveur AP,
   `_playing_clients` et `_slot_client_tags` repartent de zéro, et `death_link_live` redevient `None`. Tant que la
   connexion est coupée, `_connected_slots` est vide.
3. **Resynchronisation (bridge).** Après chaque connexion, le bridge fait `Get` + `SetNotify` sur
   `_read_client_status_{team}_{slot}` pour chaque slot du roster (`team` du paquet `Connected` ; le `SetReply` ne
   porte que la clé et la valeur, le slot se lit dans la clé).
   - Un slot à statut `CLIENT_READY` (10) ou `CLIENT_PLAYING` (20), sans client de jeu connu, autre que le slot du
     bridge, est marqué occupé de façon **provisoire** (compte 1). Le prochain `Join` ou `Part` de ce slot corrige le
     compte.
   - `CLIENT_CONNECTED` (5) ne suffit pas : c'est le statut d'un tracker seul (les trackers n'envoient pas de
     `StatusUpdate`). `CLIENT_GOAL` (30) non plus : il reste figé même quand plus personne n'est connecté. Mieux vaut
     rater le temps d'un joueur jusqu'à sa prochaine reconnexion que compter des heures à un slot vide.
   - Un `SetReply` à 0 (plus aucun client sur le slot) remet le slot à zéro, quel que soit le compte : c'est le
     serveur qui fait foi.
   - Le slot du bridge n'est jamais resynchronisé (son statut reflète la connexion du bridge lui-même).
4. **Heartbeat porteur de la présence (bridge).** Le corps du heartbeat devient
   `{"connected_slots": [{"slot": 3, "name": "Zoe"}, ...]}` (slots de `_connected_slots`, nom = nom enregistré du
   slot, `_store.slot_name()`, celui de `session_slot.slot_name`, pas l'alias). Liste vide si la connexion AP est
   coupée. Un heartbeat sans corps reste accepté par Symfony (bridge ancien).
5. **Spans de jeu (API).** Nouvelle entité `SlotPlaySpan` (table `session_slot_play_span` : `id`, `session_id`,
   `slot_name`, `started_at`, `last_seen_at`), dans le contexte Sessions. `HeartbeatController` lit le corps et, pour
   une session connue, passe la commande `RecordSlotPresence(sessionId, slotNames)` (retour `void`). Pour chaque slot
   connecté :
   - si son dernier span a un `last_seen_at` à moins de **90 s** (3 heartbeats), `last_seen_at = now` ;
   - sinon un nouveau span s'ouvre avec `started_at = last_seen_at = now`.
   Un slot absent du heartbeat n'est pas touché (son span s'arrête de lui-même). Le tout dans la transaction du
   heartbeat, horloge injectée. Index sur `(session_id, slot_name)`. Migration.
6. **Durée.** Durée d'un span = `last_seen_at - started_at`. Temps de jeu d'un slot = somme de ses spans. Un span
   d'un seul heartbeat vaut 0 : le temps se compte à partir du deuxième heartbeat (perte d'au plus 30 s par
   connexion, assumée).
7. **Recap / résultats.** `RunResultsQuery` ajoute `playedSeconds` (entier, 0 si aucun span) à chaque slot ; il arrive
   ainsi dans `SessionRecapQuery` (qui réutilise `RunResultsQuery`). Le recap (`recap-api.ts`, `build-player-rows.ts`,
   `recap-player-table.tsx`) l'affiche par joueur sous un libellé « Temps connecté », et rien quand toute la partie est à 0 (parties jouées
   avant la story). Durée formatée par les helpers existants (jamais négative, story 32.24).
8. **Faits de succès.** Sur les slots que le membre joue (`DbalSlotPlayerSource`, co-joueurs compris, comme 32.25) :
   - `playedHours` : « Temps connecté sur les parties (heures, total) », entier arrondi vers le bas ;
   - `longestPlaySpanMinutes` : « Plus longue session de jeu d'une traite (minutes) » ;
   - `maxPlayedHoursInSession` : « Temps connecté sur une même partie (heures, record) ».
   Les tentatives hebdo ne comptent pas (pas de session, pas de span).
9. **Tests.**
   - Bridge : lecture du texte de `Part` (jeu, tracker, `TextOnly`, `HintGame`, texte illisible), tracker parti
     pendant que le jeu reste, remise à zéro à la reconnexion (pas de double compte après relance du serveur AP),
     resynchronisation (10 et 20 comptés, 5 et 30 ignorés, `SetReply` à 0, slot du bridge), corps du heartbeat (liste
     vide connexion coupée).
   - API : heartbeat avec et sans corps, prolongation et ouverture de span (seuil de 90 s), slot inconnu ignoré,
     `playedSeconds` dans les résultats, requête des trois faits (slot co-joué, autre membre, plusieurs spans).
   - Frontend : affichage et masquage du temps connecté dans le recap.
   - Gates verts partout.

## Tasks / Subtasks

- [x] Bridge : `Part` lu depuis le texte, `_client_left` et `_slot_client_tags` corrigés (AC 1) + tests
- [x] Bridge : remise à zéro à la connexion et resynchronisation par `_read_client_status` (AC 2, 3) + tests
- [x] Bridge : corps du heartbeat (AC 4) + tests
- [x] API : `SlotPlaySpan`, migration, `RecordSlotPresence` depuis le heartbeat (AC 5, 6) + tests
- [x] API : `playedSeconds` dans `RunResultsQuery` (AC 7) + tests
- [x] API : faits, requête DBAL, fournisseur (AC 8) + tests
- [x] Frontend : temps connecté dans le recap (AC 7) + tests
- [x] Gates

## Dev Notes

- Pas d'identité par humain dans Archipelago (le mot de passe est par slot) : en co-op, le temps du slot est crédité à
  chacun de ses membres, comme les autres stats de slot.
- Lien slot / membres : par `session_id` + `slot_name`, comme `DbalDeathLinkStatsQuery` (32.25). Le nom envoyé par le
  bridge doit donc être le nom enregistré, pas l'alias (`!alias` change le nom affiché, pas `session_slot.slot_name`).
  À noter hors périmètre : les lignes du flux (items, morts de 32.25) portent l'alias et sont jointes sur
  `slot_name` ; un joueur qui fait `!alias` sort de ces stats. Dette existante, à traiter à part si ça arrive.
- Visibilité : le temps connecté s'affiche comme les autres stats du recap, donc publiquement sur les recaps
  d'event publics (32.22), comme Steam par défaut.
- Le texte du `Part` est du texte d'affichage du serveur : parseur tolérant, testé sur les formes de la version
  embarquée, et repli sur le comportement actuel. À revérifier si l'image archipelago change de version majeure.
- Modèle proche : `SlotBlockEpisode` (story 40.1), un épisode par slot gardé en base parce que le push des joueurs n'a
  pas d'historique.
- Charge : au plus un `UPDATE`/`INSERT` par slot connecté toutes les 30 s et par partie en cours, négligeable.
- Pas d'historique : seules les parties qui tournent après le déploiement du bridge et de l'API ont un temps. Une
  estimation rétroactive par les checks (écarts entre checks plafonnés) reste possible dans une story à part si elle
  est demandée.
- Story 9.56 (bridge PR #18, ouverte) touche aussi `ap_client.py` et fait du slot du bridge un vrai slot dans toutes
  les parties : l'exclusion du slot du bridge (AC 2) doit marcher dans les deux cas.
- Déploiement : bridge taggé et `BRIDGE_VERSION` relevé, migration API. Un bridge ancien envoie un heartbeat vide :
  aucun span, rien ne casse.

## Dev Agent Record

### Completion Notes List

- Réalisation 2026-10-27.
  - Bridge (`feature/story-32-26-temps-de-jeu`) :
    - `_part_tags` lit la liste de tags en fin de texte du `Part` (`ast.literal_eval`), sinon le verbe
      (`_PART_VERBS`), sinon client de jeu ; `_client_left` retire l'entrée de `_slot_client_tags` qui porte ces
      tags (à défaut la dernière) ;
    - `_reset_presence` au début de chaque connexion et après chaque coupure (`_forget_presence_after_disconnect`),
      `death_link_live` remis à `None` ;
    - `_read_client_status_{team}_{slot}` ajouté au `Get` et au `SetNotify` de `_handle_connected` ;
      `_apply_server_client_status` (10 et 20 : occupé provisoire, `_provisional_slots` ; 0 : vidé ; 5, 30 et le
      slot du bridge ignorés) ; un `Join` ou un `Part` de jeu remplace le provisoire ;
    - `heartbeat_presence()` (nom enregistré via `_store.slot_name`, vide si `ws_connected` est faux), passé à
      `_api_heartbeat_loop` qui l'envoie en corps JSON.
    - `tests/test_play_time_presence.py` (32 tests) ; 263 au total, ruff et mypy verts.
  - API :
    - entité `SlotPlaySpan` (`session_slot_play_span`, migration `Version20261025200002`), `MAX_GAP_SECONDS = 90` ;
      `SlotPlaySpanRepositoryInterface` / `DoctrineSlotPlaySpanRepository` (cumul par slot calculé en PHP) ;
    - commande `RecordSlotPresence`, appelée par `SessionLifecycleManager::heartbeat` (session `running`
      seulement, même flush que le heartbeat) : le contrôleur garde un seul appel Application (AC-P4) et lit le
      corps (`connected_slots[].name`, corps absent = personne) ;
    - `RunResultsQuery` : `playedSeconds` par slot (donc dans le recap via `SessionRecapQuery`) ;
    - faits `playedHours`, `longestPlaySpanMinutes`, `maxPlayedHoursInSession` (`DbalPlayTimeStatsQuery`,
      `PlayTimeMetricProvider`).
    - Tests : `RecordSlotPresenceTest` (unitaire, `MockClock` : seuil de 90 s, slots inconnus, session non
      `running`, heartbeat vide) et `PlayTimeTest` (fonctionnel : endpoint avec et sans corps, résultats, faits
      avec co-joueur).
  - Frontend : `playedSeconds` optionnel dans `RecapPodiumSlot` (validé), `PlayerRow.playedSeconds`, colonne
    « Connecté » du tableau du recap, masquée quand aucun slot n'a de temps. Tests `build-player-rows.test.ts` et
    `recap-player-table.test.tsx`. Gates verts (1272 tests) avec `NEXT_PUBLIC_APP_URL` par défaut.
