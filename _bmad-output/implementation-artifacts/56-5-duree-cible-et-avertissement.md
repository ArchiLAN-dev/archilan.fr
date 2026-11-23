# Story 56.5 - Durée cible, avertissement au YAML et préréglages par jeu

**Status:** review
**Epic:** 56 - Équilibrage des parties (`_bmad-output/planning-artifacts/epics/epic-56-equilibrage.md`)
**Date:** 2026-11-21
**Issue:** #1178
**Dépend de:** 56.1 (mesure d'un test de slot), 56.2 (durée estimée).

## Story

En tant que joueur qui règle son YAML pour un event,
je veux savoir combien de temps mon monde va durer par rapport à la cible de l'event, et choisir un format tout prêt,
afin de venir avec un monde à la bonne taille.

## Décisions

1. **Durée cible** : champ sur l'event (heures). Par défaut, la durée de l'event moins 20 %. Aussi sur un template
   hebdo et sur une partie privée (optionnel).
2. **Avertissement** : à l'enregistrement du YAML d'un slot d'event, le joueur peut lancer « Tester ma config » (le
   test de 11.11 ouvert au joueur pour son slot, avec les mêmes garde-fous). Le résultat affiche « environ N checks,
   environ X h ; la cible de l'event est Y h ». Au-delà de la cible, un message d'alerte, sans blocage.
3. **Préréglages par jeu** : sur la curation des options d'un jeu (9.51 à 9.53), un admin définit jusqu'à trois
   préréglages nommés (court, moyen, long), chacun un ensemble de valeurs d'options. Le joueur en applique un à son
   YAML en un clic ; il peut ensuite modifier les valeurs.
4. Le test joueur est limité (un test en cours par slot, et un nombre de tests par heure) pour ne pas saturer
   l'orchestrateur.

## Critères d'acceptation

1. Un event a une durée cible, par défaut la durée de l'event moins 20 %, modifiable par un admin.
2. Un joueur teste son slot et voit sa taille, sa durée estimée et la cible ; au-delà, une alerte.
3. Un admin définit des préréglages pour un jeu ; un joueur en applique un à son YAML.
4. La limite de tests est respectée.

## Tests et gates

5. Fonctionnels : cible par défaut, test joueur (droits, limite), préréglages. Front : résultat du test, alerte,
   application d'un préréglage.
6. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** - Durée cible sur l'event et la partie privée (template hebdo différé, voir Dev Agent Record).
- [x] **Task 2** - Test de slot ouvert au joueur, limite, affichage du résultat.
- [x] **Task 3** - Préréglages par jeu : stockage, écran admin, application côté joueur.
- [x] **Task 4** - Tests et gates.

## Dev Notes

- Mémoire `yaml_roundtrip_lossy` : le YAML envoyé au générateur n'est pas celui du joueur ; un préréglage s'applique
  aux valeurs d'options, pas au texte.

## Dev Agent Record

### Implémentation

- **Cible d'event** : colonne `event.target_hours` (nullable). `Event::targetHours()` rend la valeur de l'admin ou
  la durée de l'event moins 20 % ; le formulaire admin (fieldset « Durée cible d'un monde ») montre la valeur par
  défaut en placeholder, vide = défaut, 1 à 2000 h sinon 422. Le rapport d'équilibre (56.3) lit la même cible.
- **Cible de partie privée** : colonne `run.target_hours`, `PUT /api/v1/runs/{id}/target-hours` (propriétaire ou
  co-organisateur), réglage dans l'onglet Réglages tant que la partie est en brouillon ; vide = pas de cible.
- **Test joueur** : le test 9.57 existait déjà, ouvert au joueur, avec son quota (5 tests par 10 minutes) : le
  critère 4 est couvert sans nouveau code. Le résultat lit `?eventId=` ou `?runId=` et ajoute `estimate`
  (emplacements, heures via la durée estimée 56.2, source, cible, `overTarget`). La cible d'une partie n'est lue que
  pour ses participants. L'écran affiche « Environ X h de jeu pour toi ; la cible est de Y h. » et, au-delà, une
  alerte non bloquante.
- **Préréglages** : colonne `game.option_presets` (JSON, 3 au plus, `{name, values}`), validés contre les types
  d'options (toggle booléen, choice dans sa liste, range entier dans ses bornes). Écran admin « Formats tout prêts »
  dans l'onglet apworld ; côté joueur, barre de boutons au-dessus de l'éditeur (slot d'event et slot de partie
  privée). `applyOptionPreset` met tout le poids sur la valeur visée et laisse les autres options intactes.

### Différé

- **Template hebdo** : pas de cible. Une partie hebdo n'a pas de durée fixe à viser ; à reprendre si le besoin
  apparaît.

### Fichiers

- API : `Event`, `AdminEventDrafts`, `AdminEventView`, `Run`, `PersonalRunDrafts`, `SetRunTargetHours(+Outcome)`,
  `RunTargetHoursController`, `Game`, `AdminGameLibrary`, `AdminGameLibraryController`, `GameYamlTestQuery`,
  `GameYamlTestController`, `RegistrationGameSelection`, `PersonalRunGameSelection`, `BalanceReportQuery`,
  migrations `Version20261123100001` et `Version20261123100002`, test `TargetDurationAndPresetsTest`.
- Front : `game-yaml-test(-api)`, `yaml-option-editor`, `option-preset-bar`, `slot-yaml-gate`, `events-api`,
  `personal-run-slot-yaml-page`, `personal-run-detail-page`, `run-target-hours`, `personal-runs-api`, `types`,
  `admin-event-form`, `admin-events-api`, `admin-game-option-presets`, `admin-game-apworld-tab`, `admin-games-api`,
  `archipelago-yaml` ; tests `archipelago-yaml-presets`, `world-duration-note`, `admin-game-option-presets`,
  `game-yaml-test-api`.
