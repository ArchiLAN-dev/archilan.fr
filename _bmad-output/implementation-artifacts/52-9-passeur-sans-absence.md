# Story 52.9 - Le Passeur sans condition d'absence

**Status:** review
**Epic:** 52 - Le Passeur (`_bmad-output/planning-artifacts/epics/epic-52-passeur.md`)
**Date:** 2026-11-10
**Branche:** `feature/epic-52-story-9-passeur-sans-absence` (depuis `develop`)
**Dépend de:** 52.3, 52.4, 52.5 (mergées, v0.61.0).

## Story

En tant que joueur en BK,
je veux pouvoir faire ouvrir le coffre qui me débloque dans n'importe quel autre monde,
afin de ne pas rester bloqué parce que son joueur est là mais occupé sur un autre jeu, ou pas encore assez loin pour
atteindre ce coffre.

## Contexte

- Jusqu'ici (52.3) le Passeur n'ouvrait que le monde d'un **absent** (`SlotAbsence`) : en événement, hors de ses
  créneaux déclarés et sans client depuis 90 s ; en partie privée, sans client depuis `passeurAbsenceMinutes` (2 h).
- Décision de Jean (2026-11-10) : retirer la notion d'absence. Un joueur présent peut ne pas être disponible sur ce
  jeu-là (il joue à un autre de ses slots, il fait une pause) ou ne pas pouvoir encore atteindre ce coffre dans son
  jeu. La présence ne dit pas si le coffre sera ouvert bientôt.
- Le bridge (`GET /slots/{slot}/passeur/items`) renvoie déjà les objets du joueur dans **tous les autres mondes**
  (jamais le sien) : rien à changer côté bridge.

## Décisions

1. **Candidats** : les objets de progression destinés au joueur, dans un emplacement non ouvert de **n'importe quel
   autre monde**. Le classement (suivi exact, sinon sphères) ne change pas.
2. **Inchangé** : le BK comme condition, le prix dégressif et son plancher, la monnaie (argent en événement, bronze
   en privée), la **part versée au joueur du monde ouvert**, la **limite par monde et par jour** (qui protège ce
   joueur), la notification et l'encart « ce que le Passeur a ouvert », pas de refus possible.
3. **Réglage retiré** : `passeurAbsenceMinutes` (délai d'absence en partie privée) disparaît de la configuration, des
   overrides et de l'admin. Une valeur déjà enregistrée est ignorée.
4. **`SlotAbsence` supprimée** ; la route de diagnostic admin ne renvoie plus `absentSlots`.
5. **Libellés** : plus de « joueur absent » côté joueur ni côté admin ; on nomme le joueur du monde (« le coffre de
   Bob »), l'encart dit « Le Passeur a ouvert N emplacements de ton monde ». Les noms techniques hérités (`absent_*`
   en base, `absentName` / `absentPlayerName` dans l'API) sont gardés pour ne pas migrer pour rien ; ils désignent le
   joueur du monde ouvert.
7. **Temps minimum en BK** (demande de Jean, 2026-11-10) : le Passeur ne peut être appelé qu'après
   `passeurMinBlockedMinutes` de BK (30 min par défaut, 0 à 1440, réglable en profil et en override). Pendant
   l'attente le joueur voit déjà le coffre, le bouton est grisé avec « disponible à HH:MM » ; l'appel est refusé
   (`passeur_too_early`). Le prix part du prix de départ à ce moment-là et ne baisse qu'ensuite : attendre le délai ne
   fait pas déjà baisser le prix.
6. Les créneaux de présence (52.2) restent : la carte des dépendances (52.7) et le départ assisté (52.6) s'en servent.

## Critères d'acceptation

1. Un joueur en BK se voit proposer un objet de progression dans le monde d'un joueur connecté et dans ses créneaux.
2. Son propre monde n'est jamais proposé.
3. La part, la limite par monde et par jour et la notification du joueur du monde ouvert fonctionnent comme avant.
4. `passeurAbsenceMinutes` n'existe plus dans l'API de configuration ni dans l'admin ; une configuration enregistrée
   qui le contient se charge sans erreur.
5. Aucun texte affiché ne parle d'absent pour le Passeur.
6b. Avant `passeurMinBlockedMinutes` de BK, l'offre montre le coffre avec `availableAt` et l'appel est refusé sans
    débit ; le prix baisse à partir de `availableAt`.

## Tests et gates

6. Unitaires : `PasseurSelection` sans filtre d'absence. Fonctionnels : candidat dans le monde d'un présent, achat
   dans le monde d'un présent, configuration sans le réglage.
7. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] Task 1 : `PasseurSelection`, `PasseurCandidatesQuery`, `PasseurCandidates`, route de diagnostic ; suppression de
  `SlotAbsence` (AC 1, 2)
- [x] Task 2 : retrait de `passeurAbsenceMinutes` (config, override, `PasseurTerms`, admin) (AC 4)
- [x] Task 3 : libellés API et front (AC 5)
- [x] Task 4 : tests et gates (AC 3, 6, 7)

## Dev Agent Record

- `SlotAbsence` et son test supprimés ; `PasseurSelection::candidates($items)` ne garde que les objets de progression.
- `PasseurCandidatesQuery` appelle toujours le bridge ; la route de diagnostic ne renvoie plus `absentSlots`.
- `passeurAbsenceMinutes` retiré de `SessionServerConfig`, `SessionConfig`, `SessionConfigOverride`, `PasseurTerms`
  et de l'admin (page de configuration, formulaire d'override, aides). Une valeur stockée est ignorée
  (`PasseurAbsenceSettingGoneTest`).
- Libellés : panneau « chez Bob et t'envoyer », encart « Le Passeur a ouvert N emplacements de ton monde »,
  préférence de notification, « Part du joueur du monde ouvert (%) ».
- Tests fonctionnels : Bob est **présent** (créneau en cours) dans `PasseurCandidatesTest` et `CallThePasseurTest`,
  le Passeur ouvre quand même son monde.
- Gates : api (phpstan, cs-fixer, ddd, rector, 3758 tests ; un échec de renommage du cache de routes entre workers
  ParaTest, sans lien, vert en relance) et front (typecheck, lint, 1497 tests, build) verts.
- Temps minimum en BK : `passeurMinBlockedMinutes` (SessionServerConfig, SessionConfig, override), `PasseurTerms::opensAt`,
  offre avec `availableAt` (prix et prochaine baisse comptés depuis), refus `passeur_too_early` dans `CallThePasseur`.
  Front : champ admin et override, panneau en attente. Tests : `PasseurMinBlockedSettingTest`, deux tests fonctionnels
  dans `CallThePasseurTest` (épisode de test passé à 40 min de BK), deux tests front. Gates api et front verts.
