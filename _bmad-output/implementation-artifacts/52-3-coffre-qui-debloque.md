# Story 52.3 - Le coffre qui débloque

**Status:** ready-for-dev
**Epic:** 52 - Le Passeur (`_bmad-output/planning-artifacts/epics/epic-52-passeur.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-52-story-3-coffre-qui-debloque` (monorepo, depuis `develop`) ; branche homonyme dans
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

- [ ] **Task 0** (AC 0) - `SlotBlockTracker` étendu aux sessions d'événement, tests.
- [ ] **Task 1** (AC 1-4) - Ports et service `PasseurCandidates`, tests unitaires.
- [ ] **Task 2** - Classification des objets exposée par le bridge si elle manque.
- [ ] **Task 3** (AC 5) - Route admin de diagnostic.
- [ ] **Task 4** (AC 9) - Gates verts, Dev Agent Record.
