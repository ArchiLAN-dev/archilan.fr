# Story 56.1 - Taille et part de progression d'un slot à la génération

**Status:** review
**Epic:** 56 - Équilibrage des parties (`_bmad-output/planning-artifacts/epics/epic-56-equilibrage.md`)
**Date:** 2026-11-21
**Issue:** #1174
**Dépend de:** aucune. Touche l'orchestrateur et l'image archipelago (dépôts séparés, PR sobres).

## Story

En tant qu'admin d'un event,
je veux connaître pour chaque slot généré la taille de son monde et la part des clés des autres qu'il détient,
afin de repérer le monde qui va bloquer la partie.

## Décisions

1. Trois chiffres par slot, lus dans la multidata de la génération :
   - `locations` : nombre d'emplacements du monde ;
   - `ownProgression` : objets de progression appartenant au slot, où qu'ils soient ;
   - `hostedProgression` : objets de progression **des autres slots** posés dans ce monde.
   La part hébergée d'un slot vaut `hostedProgression ÷ somme des hostedProgression`, et la part équitable vaut
   `locations ÷ somme des locations`.
2. La lecture se fait dans le conteneur Archipelago de l'orchestrateur, réseau coupé, avec l'unpickler restreint
   (même chemin que `SeedArchiveGatewayInterface::inspect`, story 16.18). L'API ne dépickle jamais.
3. Le flag de progression est celui de la multidata (`NetworkItem.flags & 0b001`). Un objet de progression marqué
   `skip_balancing` compte aussi.
4. Stockage :
   - session : nouvelles colonnes nullables sur `session_slot` (`locations_total`, `own_progression`,
     `hosted_progression`), écrites quand la sortie de génération est enregistrée (`RecordSessionGeneratedOutput`) ;
   - test de slot (11.11, 9.42) : renvoyées dans le résultat du test, non stockées.
5. Une session générée avant cette story garde des valeurs nulles ; aucun rattrapage automatique. Une commande
   `app:sessions:measure-slots {sessionId}` permet de mesurer une session déjà générée depuis son archive.

## Critères d'acceptation

1. Après la génération d'une session, chaque `session_slot` porte ses trois chiffres, qui correspondent à la
   multidata (vérifié sur une archive de référence).
2. Le résultat d'un test de slot renvoie `locations` et `ownProgression` (le reste est à 0 : un slot seul n'héberge
   rien des autres).
3. Une archive illisible ou ancienne ne fait pas échouer la génération : les chiffres restent nuls et un
   avertissement est journalisé (`LoggerInterface`).
4. La commande de mesure fonctionne sur une session déjà générée.

## Tests et gates

5. Orchestrateur et image : test sur une multidata de référence à 3 slots (dont un grand). API : test fonctionnel de
   l'enregistrement et de la commande, passerelle simulée.
6. `composer gates` et `pnpm gates` verts ; tests de l'orchestrateur et de l'image verts.

## Tasks / Subtasks

- [x] **Task 1** - Image archipelago : script de mesure lancé dans le conteneur isolé, à côté de l'inspection
  existante (vérifier la parité des imports, cf. mémoire `archipelago_script_parity`).
- [x] **Task 2** - Orchestrateur : renvoyer les mesures avec la sortie de génération et le résultat de test.
- [x] **Task 3** - API : migration `session_slot`, port et adaptateur, enregistrement, commande de mesure.
- [x] **Task 4** - Tests et gates.

## Dev Notes

- Mémoire `archipelago_image_copy` : tout module Python ajouté doit avoir son `COPY` dans l'image, avec le garde-fou
  d'import au build.
- Mémoire `ap_item_ids_per_game` : les ids d'objets sont uniques par jeu seulement ; le flag de progression vient de
  la multidata, pas d'un nom.

## Dev Agent Record

- Image archipelago (PR archilan-archipelago #56) : `slot_measures.measure(multidata)` partagé par
  `read_multidata.py` (clé `measures`) et `generate_multiworld.py` (ligne stderr `###ARCHILAN-MEASURES###` sur
  sa propre sortie). COPY + boucle CRLF + garde-fou d'import au build. Vérifié sur une vraie seed à 6 slots
  (Luigi's Mansion : 34,6 % des emplacements, 40 % des clés des autres).
- Orchestrateur (PR archilan-orchestrateur #50) : `PreflightGenerateMeasured`, `SlotPreflight.Measures`,
  `measures` dans `GET /preflight-generations/{id}`. L'inspection relaie déjà le JSON brut.
- API : colonnes nullables `session_slot.locations_total/own_progression/hosted_progression`
  (Version20261122100000), `SeedArchiveGatewayInterface::measure`, commande `MeasureSessionSlots` (outcome enum,
  avertissement journalisé, jamais d'échec de génération), `MeasureSessionSlotsJob` envoyé sur `async` après
  `RecordSessionGeneratedOutput`, console `app:sessions:measure-slots`, champs dans `SessionSlot::payload()`.
- CA 2 côté API (2026-11-22, accord de Jean) : `archilan/orchestrateur-client` v1.20.0 (`SlotPreflightJob::$measures`),
  `getSlotPreflight` et `YamlTestResultQuery` renvoient `measures` ; le test de YAML d'un joueur affiche
  « Ton monde : N emplacements, M objets de progression ».
- Déploiement : image archipelago puis orchestrateur, puis API. Une image plus ancienne laisse les chiffres nuls.
