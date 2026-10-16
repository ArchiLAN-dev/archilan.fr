# Story 44.1 - Détecter les apworlds qui demandent un fichier à la génération

**Status:** done
**Epic:** 44 - Fichiers requis à la génération (ROM fournie par le joueur)
**Date:** 2026-10-14

## Epic 44 en bref

Certains apworlds lisent un fichier du joueur pendant la génération, presque toujours une ROM. Archipelago le demande
alors par une fenêtre de sélection, impossible dans notre conteneur. On ne stocke jamais de ROM : le fichier sera
demandé dans l'interface avant chaque génération, contrôlé (MD5), transmis au conteneur puis supprimé.

- **44.1 (cette story)** : détection et verdict lisible.
- 44.2 : transport temporaire du fichier jusqu'au conteneur (API, orchestrateur, image).
- 44.3 : demande du fichier et contrôle MD5 sur les écrans de génération, admin et joueur.
- 44.4 : régénérations (suivi exact) sans le fichier.

Inventaire du 2026-10-14 (405 worlds, apworlds de dev) : 56 déclarent un fichier requis, 8 bloquent la génération
aux options par défaut (A Link to the Past, Donkey Kong Country 3, Mario Kart 64, Ocarina of Time, Paper Mario,
Shining in the Darkness, Wario Land, Yoshi's Island). Les autres ne s'en servent que côté client.

## Story

En tant qu'admin d'ArchiLAN,
je veux qu'un apworld qui demande un fichier à la génération soit reconnu comme tel, avec le fichier nommé,
afin de ne plus voir un échec obscur et de savoir quels jeux attendent la fourniture du fichier.

## Contexte

`settings.py` d'Archipelago : un réglage `Path` de host.yaml marqué `required`, lu alors que le fichier manque,
lance `browse()` (kdialog, zenity, sinon tkinter). Notre tkinter est un faux module : la génération casse plus loin
avec une erreur sans rapport. Avec `settings.no_gui = True`, Archipelago lève à la place
`FileNotFoundError: <fichier> does not exist, but <Groupe>.<clé> is required`.

Les verdicts de test (import 9.38, candidat 38.6, test tournant 38.9) transportent la fin du stderr, qui se termine
par la ligne `###ARCHILAN-FAILURE###` (9.43) : l'enrichir suffit, l'orchestrateur n'a pas à changer.

## Critères d'acceptation

1. **Plus de fenêtre** : `generate_multiworld.py` et `reachable.py` génèrent avec `settings.no_gui = True`.
2. **Fichier nommé** : quand la génération échoue sur un fichier requis, la ligne `###ARCHILAN-FAILURE###` porte
   `requiredFile: {setting, file, description, extension, md5s}` (description et MD5 lus sur le réglage du world ;
   absents si introuvables, sans faire échouer l'émission).
3. **Incident dédié** : un verdict en échec pour fichier requis ouvre un incident `required_file` (et non
   « test en échec » ni « régression d'image »), avec un message lisible : « Ce jeu demande le fichier
   « DKC3 (US) ROM File » (.sfc) à la génération. ArchiLAN ne sait pas encore le fournir. » Il se ferme comme les
   autres incidents du hash servi (verdict réussi, autre hash servi). Pas de nouvel essai immédiat pour « seed
   malchanceuse » : le fichier manquera encore.
4. **Génération réelle** : l'échec d'une run sur un fichier requis affiche ce même message au joueur
   (`GenerationFailureParser`).
5. Tests (pytest côté image : enregistrement du fichier requis, absence de fenêtre ; PHPUnit : lecture de
   l'enregistrement, type d'incident, message joueur) et gates verts des deux dépôts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - Dépôt archipelago : `generation_setup.disable_file_dialogs()` et
  `required_file_record(exc, world_types)` ; appel dans `generate_multiworld.py` et `reachable.py` ; tests.
- [x] **Task 2** (AC 3, 4) - API : lecture partagée de l'enregistrement (`RequiredGenerationFile`) ;
  `ApworldIncidentType::RequiredFile` ; `ReconcileApworldIncidents` ; `GenerationFailureParser` ; libellé admin.
- [x] **Task 3** (AC 5) - Tests et gates.

## Dev Agent Record

- Image (dépôt archipelago) : `generation_setup.disable_file_dialogs()` (`settings.no_gui`) et
  `required_file_record(exc, world_types)` ; appelés dans `generate_multiworld.py` (ligne d'échec enrichie de
  `requiredFile`) et `reachable.py`. Tests `tests/test_required_file.py`. Vérifié de bout en bout sur une image
  locale avec l'apworld DKC3 du catalogue : `requiredFile` porte la description « DKC3 (US) ROM File », `.sfc` et le MD5.
- API : `RequiredGenerationFile` (Sessions, Support) et `GenerationFailureParser::requiredFile()` ; le message lisible
  remplace l'erreur brute partout où le parseur résume un échec (run, test de config, candidat, incidents, alerte).
  `ApworldIncidentType::RequiredFile` ; `ReconcileApworldIncidents` l'ouvre sans nouvel essai immédiat.
- Front : libellé « Fichier requis à la génération » (santé des apworlds, notification).
- Hors périmètre, noté : DKC3 n'est plus fourni par Archipelago 0.6.8 (l'apworld du catalogue le remplace).
