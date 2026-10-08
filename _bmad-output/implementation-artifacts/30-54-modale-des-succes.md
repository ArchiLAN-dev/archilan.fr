# Story 30.54: Une modale de succès qui se lit

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-10-08

## Story

En tant que membre,
je veux une modale de succès claire, avec une progression qui se voit et des conditions en phrases,
afin de comprendre d'un coup d'œil ce qu'il me reste à faire.

## Contexte

Retour de Jean (2026-10-08) sur la modale de la story 30.53 : la barre de progression se voit mal (piste
`surface-2` sur fond `background`, presque sans contraste), l'interface mérite une reprise générale, et les
conditions se lisent comme une formule (« Items reçus d'autres joueurs : au moins 3 000 ») au lieu d'une phrase.

## Critères d'acceptation

1. **Barres lisibles** : piste contrastée (`border`), plus épaisse, remplissage en dégradé lumineux (vert une fois
   atteint), pourcentage et « Encore N » sous la barre, « 1 240 / 3 000 » à droite.
2. **Progression globale** en tête : un pourcentage pour toute la règle (moyenne pour « toutes », meilleure pour « au
   moins une », tout ou rien pour « aucune » ; jamais 100 % avant le déblocage) et « N conditions remplies sur M ».
3. **En-tête** : grande image (anneau, grisée tant que le succès n'est pas obtenu), description, pastilles d'état
   (débloqué le … / à obtenir), « Attribué par l'équipe », rareté, collection.
4. **Groupes** : rail et étiquette de couleur par mode (toutes, au moins une, aucune), « rempli » coché.
5. **Conditions en phrases** : « Jouer 10 parties », « Recevoir 3 000 items d'autres joueurs (hors release et
   collect) », « Atteindre son objectif à « ArchiLAN #3 » », « Remporter le superlatif « Le Parrain » 3 fois »,
   « Compléter entre 500 et 2 000 checks » ; singulier et article (« Jouer une partie ») ; « Ne pas … » sous « aucune » ;
   un critère inconnu retombe sur son libellé.
6. **Admin, « En clair »** : les mêmes phrases (« Pour le débloquer : ouvrir le coffre des quêtes 10 semaines
   d'affilée. ») au lieu de « Débloqué si Plus longue série de semaines avec le coffre : au moins 10. » (retour de Jean).
7. Gates verts ; tests (phrases, score global, rendu, « En clair »).

## Tasks / Subtasks

- [x] **Task 1** (AC 5) - `achievement-phrasing.ts` : une phrase par critère ; tests.
- [x] **Task 2** (AC 1-4) - `achievement-details.tsx` : barre, progression globale, en-tête, groupes ; tests.
- [x] **Task 3** (AC 6) - `ruleInFrench()` de l'admin passe par `conditionPhrase()` ; « ne pas … » sous « aucune » ; tests.
- [x] **Task 4** (AC 7) - Gates.

## Dev Agent Record

- `conditionPhrase()` : verbe, quantité (« plus de », « exactement », « entre … et … »…), nom au singulier ou au pluriel
  avec son article ; événement et superlatif par leur nom tiré du libellé de l'API.
- `progressScore()` et `conditionsMet()` pour la tête de la modale ; `Bar` commune (piste `bg-border`, `h-2` / `h-3`,
  largeur minimale visible dès 1 %).
- `achievement-rules.ts` : `ruleInFrench()` réutilise `conditionPhrase()` (un objectif d'événement par le titre de
  l'événement des options). Les pastilles de la liste admin gardent leur forme courte.
- Front seulement, aucune API touchée.
