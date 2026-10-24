# Story 3.14: « Tout sélectionner » n'est plus bridé par la limite par inscription

**Status:** review
**Epic:** 3 - Bibliothèque de jeux et sélection par événement
**Date:** 2026-10-24
**Origine :** relevé le 2026-10-24 en discutant d'un plafond de jeux par event ; Jean a confirmé que la limite par
inscription suffit. Traité à sa demande le même jour.

## Story

En tant qu'admin d'un événement,
je veux ajouter en masse des jeux au catalogue de l'event,
afin de ne pas être bloqué par la limite de slots qu'un joueur peut prendre.

## Contexte

- `gameSelectionMax` est la **limite de slots par inscription** (`Event::gameSelectionMaxPerRegistrant`), appliquée
  côté serveur à chaque inscription (`RegistrationGameSelection`, `AdminRegistrationModification`).
- La story 3.11 (AC3) l'appliquait aussi au « Tout sélectionner » du catalogue admin : avec une limite de 3, l'admin
  ne pouvait pas proposer plus de 3 jeux d'un coup, alors que le catalogue n'a aucune limite. Le serveur, lui,
  accepte un catalogue de toute taille.

## Critères d'acceptation

1. « Tout sélectionner (résultats filtrés) » ajoute tous les jeux filtrés, quelle que soit la limite par
   inscription. Le message « Limite de N atteinte » disparaît.
2. Le champ « Limite de slots par inscription » précise qu'il borne les jeux qu'un joueur peut choisir, pas le
   catalogue.
3. Remplace l'AC3 de la story 3.11.

## Tâches

- [x] Front : `selectAllFiltered` sans plafond, `bulkNotice` retiré, aide du champ.
- [x] Test front de la sélection en masse.
