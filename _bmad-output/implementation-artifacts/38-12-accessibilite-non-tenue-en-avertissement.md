# Story 38.12: Accessibilité non tenue en avertissement, comme le Launcher

**Status:** review
**Epic:** 38 - Santé des apworlds
**Date:** 2026-09-28
**Dépôts :** `archipelago` (générateur), `orchestrateur`, `archilan-orchestrateur-client`, `archilan.fr` (API, admin)

## Story

En tant qu'admin d'ArchiLAN,
je veux qu'une génération que le Launcher officiel d'Archipelago produit (avec un avertissement) passe aussi chez
nous, et que cet avertissement me soit montré,
afin de ne plus bloquer un jeu qui « marche en local » sans pour autant cacher le défaut de son apworld.

## Contexte

Diagnostic du 2026-09-28 sur Dragon Ball Z Budokai Tenkaichi 2 (apworld `f1a6a701…`, options par défaut) :
trois emplacements (`Discover: Evil Dragon`, `Negative Energy`, `Ultimate Dragonball`) sont inatteignables. Le
Launcher Windows 0.6.7 et notre image (Archipelago 0.6.7, même source) produisent le même remplissage, mais :

- `BaseClasses.MultiWorld.fulfills_accessibility` lève `FillError` sous `if __debug__:`, sinon journalise un
  avertissement et rend `False` ;
- le Launcher est compilé en mode optimisé (`__debug__` faux) : `Main.main` voit `False`, vérifie que la partie
  reste gagnable, journalise « Location Accessibility requirements not fulfilled. » et génère ;
- notre image lance Python normalement : la génération échoue.

Décision (2026-09-28) : s'aligner sur le Launcher **pour ce seul contrôle**, et rendre l'avertissement visible. Pas
de `python -O` global : il retirerait aussi les `assert` d'Archipelago, dont « Duplicate item reference » (Neon
White) qui protège d'une vraie corruption de partie.

## Critères d'acceptation

1. **Générateur** (`generate_multiworld.py`) : quand le contrôle d'accessibilité échoue, la génération se comporte
   comme le Launcher : partie gagnable -> elle continue et réussit ; partie non gagnable -> elle échoue comme
   avant (« Game appears as unbeatable »). Aucun autre contrôle n'est assoupli.
2. Une génération réussie avec accessibilité non tenue émet sur stderr une ligne
   `###ARCHILAN-WARNING### {"type": "accessibility", "message": "...", "missing": [...]}` (emplacements manquants).
3. **Orchestrateur** : le test d'un apworld réussi avec cet avertissement reste `passed` et porte le texte de
   l'avertissement (`warning`) ; un test réussi sans avertissement n'en porte pas.
4. **API** : le verdict lu de l'orchestrateur transporte `warning` ; un candidat qui passe avec avertissement est
   promu comme un autre (c'est un succès).
5. **Admin** : la page du jeu affiche, sous « Test de génération : réussi », l'avertissement (« N emplacements
   inatteignables : … ») avec l'explication : la partie reste gagnable, les objets placés là sont perdus ; à
   signaler à l'auteur de l'apworld.
6. Les gates des trois dépôts passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - Générateur : contrôle d'accessibilité en avertissement, ligne `ARCHILAN-WARNING`,
  tests.
- [x] **Task 2** (AC 3) - Orchestrateur : lecture de l'avertissement sur une génération réussie, champ `warning`
  du verdict, tests.
- [x] **Task 3** (AC 4) - API : `warning` dans le verdict (passerelle, double, types), après `warning` dans
  `ApworldPreflight` du paquet `archilan/orchestrateur-client` v1.11.0 (confirmé par le user le 2026-09-28).
- [x] **Task 4** (AC 5) - Admin : affichage sur la page du jeu.
- [x] **Task 5** (AC 6) - Gates, PR par dépôt, ordre de déploiement.

## Dev Agent Record

- **archipelago** (PR #29, `80168be`) : `generation_setup.soften_accessibility_check()` enveloppe
  `MultiWorld.fulfills_accessibility` : un `FillError` dont le message commence par « Could not access required
  locations for accessibility check. » devient `False` + un enregistrement (message sans la liste des
  placements, borné à 1200 caractères, emplacements manquants) ; tout autre `FillError` remonte. Armé dans
  `generate_multiworld.py` (qui émet une ligne `###ARCHILAN-WARNING###` par enregistrement après une génération
  réussie) et dans `reachable.py`. Tests : `tests/test_accessibility_warning.py` (132 passés au total). Vérifié
  en réel : BT2 `f1a6a701…`, YAML par défaut, image `archipelago:latest` avec ces fichiers montés, lancée comme
  un test d'orchestrateur -> code 0, partie produite, avertissement émis.
- **orchestrateur** (PR #29, `af9e744`) : `docker.generationWarnings` (fonction pure) lit les lignes sentinelles ;
  `PreflightGenerate` rend `(imageID, warning, err)` et lit stderr aussi sur un succès ; `ApworldPreflight.Warning`
  (stockage, API, Swagger) ; `applyVerdict` le pose, `markPending` l'efface. `go vet` et `go test` verts.
- **archilan-orchestrateur-client** (PR #12 et #13, tag `v1.11.0` sur `617c105`) : `ApworldPreflight::$warning`
  (chaîne vide par défaut, dernier paramètre ; `blocksUsage()` inchangé). La PR #13 monte `version` dans
  `composer.json` : oubliée dans la #12, Composer ignorait le tag (`Skipped tag v1.11.0, tag (1.11.0.0) does not
  match version (1.10.0.0)`) ; le tag a été replacé sur son merge (jamais consommable avant).
- **API** : `archilan/orchestrateur-client` `>=1.11.0` (lock mis à jour par l'API GitHub, métadonnées
  identiques au format existant) ; `RunnerGateway::preflightPayload` porte `warning` (vide pour un apworld sans
  verdict) ; types `warning?: string` dans le port et le double. Un candidat qui passe avec avertissement est
  promu (test).
- **Admin** : `ApworldPreflightWarning` sous « Test de génération : réussi » : nombre et liste des emplacements
  inatteignables, explication (partie gagnable, objets perdus, à signaler à l'auteur) ; texte brut pour un autre
  avertissement.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `tests/test_accessibility_warning.py` (archipelago) | 8 échecs | 132 passés |
| `generationWarnings`, verdict avec avertissement, exposition API (orchestrateur) | ne compile pas | verts |
| `ApworldsClientTest` (client) | 2 échecs | 99 passés |
| `RunnerGatewayImageTest`, `DecideApworldCandidatesTest`, `AdminGameApworldImageTest` (API) | 1 échec | verts |
| `apworld-preflight-warning.test.tsx` | module absent | vert |

Gates : `composer gates` (2446 tests), `pnpm gates` (579 tests, build), `go vet` / `go test`, `pytest` verts.

### Ordre de déploiement

Chaque morceau est inoffensif seul : générateur (image archipelago), orchestrateur, puis l'API et le front. Tant
que l'image n'est pas redéployée, les jeux concernés échouent comme avant.

