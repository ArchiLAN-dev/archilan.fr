# Story 17.35: Une génération longue n'est plus déclarée en échec par le garde-fou

**Status:** review
**Epic:** 17 - Sessions et orchestrateur
**Date:** 2026-11-10
**Origine :** session de l'ArchiLAN 4 (plus de 80 mondes, 8 997 lieux) : la génération a réussi en 30 min environ
côté orchestrateur, mais le site l'a déclarée en échec. Hotfix v0.65.1 demandé par Jean le 2026-11-10.

## Story

En tant qu'admin d'un événement,
je veux qu'une génération qui dure plus de 20 minutes aille à son terme,
afin que la session de la LAN puisse être lancée.

## Contexte

- Le garde-fou (`CleanupStaleSessionsHandler`, toutes les 2 min) juge une session `generating` bloquée au-delà de
  20 min (`Session::STALE_THRESHOLDS`) et appelle `SessionLifecycleManager::reconcilePending`.
- `reconcilePending` n'acceptait que la réponse `generated` de l'orchestrateur ; toute autre, y compris
  `generating` alors que le conteneur tournait, menait à « Génération bloquée : aucun retour de l'orchestrateur ».
- L'orchestrateur surveille déjà le conteneur : son sweeper prolonge le délai tant qu'il tourne et signale
  `session.crashed` quand il meurt. Le `session.generated` arrivé après coup était refusé (`failed -> generated`
  n'existe pas) : la génération réussie était perdue.

## Critères d'acceptation

1. Session `generating` au-delà du seuil, orchestrateur qui répond `generating` : rien n'est forcé (`skipped`),
   la session reste `generating` et son run lié n'est pas touché.
2. Orchestrateur qui répond `generated` : la session passe `generated` (inchangé).
3. Orchestrateur muet ou dans un autre état (`crashed`...) : la session passe `failed` avec le message actuel
   (inchangé).
4. Le bouton de forçage manuel (17.14) suit la même décision.

## Tâches

- [x] `SessionLifecycleManager::reconcilePending`, cas `generating` : `skipped` si l'orchestrateur répond
      `generating`.
- [x] Tests fonctionnels `ReconcilePendingSessionTest` : génération en cours laissée tranquille, génération
      `crashed` passée en échec.

## Hors périmètre

- Accepter un `session.generated` tardif sur une session `failed` : inutile une fois le garde-fou corrigé, et le
  run lié a déjà été remis en brouillon par l'échec.
- Raccourcir les générations (spoiler par défaut au niveau 2) : à mesurer d'abord sur les horodatages du journal.
