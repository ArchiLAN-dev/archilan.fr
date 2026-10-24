# Story 41.41: App de blind test, le cœur (sans interface)

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-24
**Dépôt :** `ArchiLAN-dev/archilan-blind-test` (service séparé, comme l'orchestrateur)
**Origine :** Jean le 2026-10-24 (« okay pour la totale » : créer et amorcer l'app). Architecture :
https://claude.ai/artifact/89BdZXDv3WwaKzozJpauR4 ; côté site : story 41.40.

## Story

En tant qu'équipe ArchiLAN,
je veux le cœur de l'app de blind test, testé et sans interface,
afin que la régie et la page téléphone (story suivante) n'aient plus qu'à l'afficher.

## Critères d'acceptation

1. **Projet** : Symfony 7.4, PHP 8.4, même outillage que l'API (phpstan max + strict-rules, php-cs-fixer @Symfony,
   PHPUnit sans notice), CI GitHub Actions sur PR et `main`.
2. **Playlist** : un fichier YAML de morceaux `{id, file, duration}` avec leurs sources
   `{type: game|song|screen|meme, answer, aliases}` ; chargé et validé (id unique, durée > 0, au moins une source).
3. **Horloge** : à partir de l'instant de départ, des pauses et des sauts de la régie, l'horloge donne le morceau en
   cours et la position dans le morceau ; la fenêtre d'acceptation d'un morceau court de son début à 10 s après sa
   fin.
4. **Correspondance** : minuscules, sans accents ni ponctuation, articles de tête retirés (the, le, la, les, l'),
   chiffres romains en chiffres, espaces réduits ; égalité avec la réponse ou un alias, à une distance d'édition de 1
   (jusqu'à 8 caractères) ou 2 (au-delà) ; jamais de correspondance partielle ; une proposition touche au plus une
   source, la plus proche.
5. **Anti-spam** : 5 propositions fausses par joueur et par morceau, puis plus rien jusqu'au morceau suivant ; une
   proposition toutes les 2 s au plus ; une source ne compte qu'une fois par joueur et par morceau.
6. **Jeton de participation** : vérifie le jeton du site (`payload.signature`, HMAC-SHA256, `exp`) et en lit
   `{userId, eventId, displayName}`.
7. **Crédit** : un client qui signe `{timestamp}.{corps}` vers `POST /api/v1/integrations/blind-test/credits` avec la
   clé `blindtest:{event}:{morceau}:{source}:{joueur}` ; file de renvoi : seuls réseau et 5xx se réessaient, 401,
   403, 409 et 422 sont définitifs.
8. **Hors périmètre** : pages régie et téléphone, Mercure, persistance des parties (story suivante).

## Tâches

- [x] Squelette Symfony + outillage + CI.
- [x] `Playlist` (chargement YAML, validation).
- [x] `PlaylistClock`.
- [x] `AnswerMatcher`.
- [x] `ProposalPolicy` (anti-spam, une source une fois).
- [x] `JoinToken`.
- [x] `CreditClient` + règle de renvoi.
- [x] Tests unitaires de chaque pièce.

## Dev Agent Record

- Dépôt créé le 2026-10-24 (privé), commit initial `92d838c` sur `main`, CI verte (gates + `lint:container`).
- Correspondance : une faute est pardonnée sur les lettres, jamais sur les nombres (« Final Fantasy » ne vaut pas
  « Final Fantasy VII », « Final Fantasy 8 » non plus). Limite connue : « Mega Man X » et « Mega Man 10 » se
  normalisent pareil ; un alias ou une réponse plus précise le corrige au cas par cas.
- 35 tests unitaires ; aucune interface ni persistance (story suivante).
