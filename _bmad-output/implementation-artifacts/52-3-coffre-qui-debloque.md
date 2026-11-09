# Story 52.3 - Le coffre qui débloque

**Status:** review
**Epic:** 52 - Le Passeur (`_bmad-output/planning-artifacts/epics/epic-52-passeur.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-52-story-3-coffre-qui-debloque` (monorepo, empilée sur `feature/epic-52-story-2-creneaux-presence`, PR #1049) ; branche homonyme dans
`Archipelago-Bridge` si une route du bridge est ajoutée
**Dépend de:** rien pour le calcul ; 52.2 pour l'absence en événement.

## Story

En tant que plateforme,
je veux savoir, pour un joueur bloqué, quels objets le débloquent et dans quels mondes absents ils se trouvent,
afin de proposer au Passeur un coffre précis à ouvrir.

## Contexte (cartographie du 2026-11-09)

- Blocage d'un slot : `api/src/Sessions/Application/Service/SlotBlockTracker.php`, sortie de BK notifiée
  (`NotifySlotUnblockedJob`, epic 40). **Limite constatée le 2026-11-09 : le suivi ne tourne que pour les parties
  privées** (pas d'événement, pas d'hebdo, pas de seed importée). Le Passeur en événement en a besoin.
- Bridge :
  - `GET /slots/{slot}/items/missing` : objets du slot pas encore reçus, avec le slot qui les contient ;
  - `GET /slots/{slot}/item-locations` : emplacement et statut (atteignable ou non) de chaque objet du slot, depuis
    le cache du suivi exact (`reachable.py`, 17.28) ;
  - `GET /slots/{slot}/spoiler`.
- Présence par slot : Join/Part lus par le bridge, instantanés `RecordPlayersSnapshot`.
- Les classifications d'objet (progression, utile, remplissage) sont dans la multidata (`read_multidata.py`) : à
  vérifier si le bridge les expose déjà, sinon les ajouter à `items/missing`.

## Décisions

0. **Le Passeur n'existe que pour un slot en BK.** Pas de BK, pas de candidat, pas de bouton. Le début de l'épisode
   de BK sert aussi de point de départ au prix dégressif (52.4).
0bis. **Suivi des BK étendu aux sessions d'événement** : `SlotBlockTracker` ouvre et ferme aussi les épisodes des
   slots d'une session d'événement quand le Passeur y est activé (ou pour toutes les sessions d'événement, si c'est
   plus simple et sans coût notable). La notification de sortie de BK (40.1) reste réservée aux parties privées ;
   seuls les épisodes sont enregistrés. Hebdos et seeds importées restent hors suivi.
1. **Un candidat** = un objet de progression destiné au joueur bloqué, pas encore reçu, situé dans un emplacement
   non ouvert d'un monde **absent**.
2. **Absent** (voir l'epic) :
   - en événement : hors créneau déclaré (52.2) et aucun client connecté sur le slot ;
   - en partie privée : aucun client connecté depuis le délai réglé par le propriétaire (2 h par défaut) ; un slot
     jamais connecté compte dès que le délai depuis le lancement est écoulé.
3. **Jamais un objet destiné à l'absent** : seuls les objets de l'acheteur sont candidats.
4. **Un seul exemplaire** d'un objet donné (objets progressifs, doublons) : celui dont l'emplacement est le plus
   tardif dans le monde de l'absent (sphère la plus haute du spoiler), pour abîmer le moins son exploration.
5. **Classement** des candidats : celui qui rend atteignables le plus d'emplacements chez l'acheteur selon le suivi
   exact ; à égalité, ordre stable par id d'emplacement. Si le calcul exact n'est pas disponible (jeu non suivi),
   repli : premier candidat par sphère croissante.
6. **Lecture seule** : un service d'Application `PasseurCandidates` derrière des ports (bridge, présence, créneaux),
   sans effet de bord, testable sans serveur.

## Critères d'acceptation

0. Les épisodes de BK sont suivis pour les slots d'une session d'événement (ouverture, fermeture, fermeture au
   redémarrage du serveur comme en 40.3), sans notification de sortie de BK.
1. Pour un slot bloqué avec un objet de progression chez un absent : un candidat est renvoyé (monde, emplacement,
   objet, sphère).
2. Aucun candidat si : le slot n'est pas bloqué, l'objet est chez un présent, l'objet est destiné à l'absent, ou
   l'emplacement est déjà ouvert.
3. Un objet progressif présent en plusieurs exemplaires ne donne qu'un candidat.
4. Le classement suit la décision 5, repli compris.
5. Route admin de diagnostic `GET /api/v1/admin/sessions/{id}/slots/{slot}/passeur-candidates` pour vérifier le
   calcul sur une vraie partie avant 52.4.

## Tests et gates

6. Unitaires : sélection, exclusions, dédoublonnage, classement, repli, avec des ports bouchonnés.
7. Fonctionnel : la route admin (droits, forme de la réponse).
8. Tests du bridge si une route ou un champ est ajouté.
9. `composer gates` (et gates du bridge) verts.

## Tasks / Subtasks

- [x] **Task 0** (AC 0) - `SlotBlockTracker` étendu aux sessions d'événement, tests.
- [x] **Task 1** (AC 1-4) - Ports et service `PasseurCandidates`, tests unitaires.
- [x] **Task 2** - Classification des objets exposée par le bridge si elle manque.
- [x] **Task 3** (AC 5) - Route admin de diagnostic.
- [x] **Task 4** (AC 9) - Gates verts, Dev Agent Record.

## Dev Agent Record

### Livré

- **Task 0** : `SlotBlockTracker` suit aussi les sessions d'événement (session sans run dont l'`event_id` désigne un
  vrai événement ; une hebdo, qui y porte l'id de son run hebdo, reste hors suivi). Les épisodes s'ouvrent et se
  ferment comme en partie privée, fermeture au redémarrage comprise, mais toujours en silence : pas de
  `NotifySlotUnblockedJob`, pas de `SlotBlockRelease` pour le récap.
- **Task 1** :
  - domaine pur `PasseurSelection` (`candidates`, `byRank`) sur la valeur `PasseurItem` : progression seulement,
    monde absent seulement, un exemplaire par objet (sphère la plus haute, monde non suivi en dernier), classement
    par gain mesuré puis id d'emplacement, non mesurés ensuite par sphère croissante ;
  - `SlotAbsence` : en événement, hors créneau (52.2) et aucun client vu depuis 90 s (heartbeat, 32.26) ; en partie
    privée, aucun client depuis 2 h, compté depuis le premier lancement pour un slot jamais joué (le réglage du
    propriétaire arrive en 52.5) ;
  - `PasseurCandidatesQuery::forSlot()` : pas de BK, pas de candidat (décision 0) ; renvoie aussi `blockedSince`,
    point de départ du prix de 52.4. Port `PasseurBridgeInterface` (adaptateur HTTP `BridgePasseurGateway` avec le
    jeton interne, doublure `StubPasseurBridge`).
- **Task 2** (bridge, branche homonyme) : `GET /slots/{slot}/passeur/items` (objets dus au slot dans les
  emplacements non ouverts des autres mondes, avec classification et sphère lues dans le résultat du suivi exact)
  et `POST /slots/{slot}/passeur/unlocks` (au plus 10 candidats : le suivi exact recalcule l'atteignable du slot
  avec chaque objet en plus, sans cache ; 503 `tracking_unavailable` si le jeu n'est pas suivi). Le transport vers
  le tracker est factorisé (`_run_reachable`), sans changement de comportement.
- **Task 3** : `GET /api/v1/admin/sessions/{id}/slots/{slot}/passeur-candidates` (admin) : `blocked`,
  `blockedSince`, `absentSlots`, `ranking` (`exact` ou `sphere`), `candidates` (monde, emplacement, objet, sphère,
  `unlocks`).

### Notes

- Le gain mesuré coûte un calcul du suivi par candidat : seuls les 10 premiers (ordre des sphères) sont mesurés, les
  autres suivent. Pour 52.4, prévoir de ne mesurer qu'à l'ouverture du panneau, pas à chaque poussée d'état.
- Les classifications existaient dans le résultat du suivi exact (`item.flags` de chaque emplacement) ; repli sur
  la table du bridge par id d'objet pour un monde pas encore calculé.

### Tests

- API : `tests/Unit/Sessions/PasseurSelectionTest.php`, `SlotAbsenceTest.php`, `SlotBlockTrackerTest.php`
  (événement suivi en silence, hebdo ignorée), `tests/Functional/PasseurCandidatesTest.php` (droits, pas de BK,
  classement exact, repli par sphère, 503, 404).
- Bridge : `tests/test_passeur.py` (8 tests : liste, exclusions, sphère absente, jeton, mesure, 503, plafond, et le
  calcul « et si » qui ne touche pas au cache).

### Gates (2026-11-09)

- API : php-cs-fixer, phpstan src+tests, DDD, rector, ParaTest 3704 tests OK.
- Bridge : pytest 271 OK, ruff OK, mypy (seule l'erreur Windows connue sur `open_unix_connection`).
