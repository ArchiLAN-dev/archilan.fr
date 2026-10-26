# Story 41.57: La page Pelles de l'événement en onglets

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-26
**Dépôt :** `archilan.fr` (frontend seul)
**Origine :** Jean le 2026-10-26 : la page admin des pelles de l'événement accumule les informations (distribution,
blind test, membres). Il demande des onglets, et des interrupteurs à la place des cases à cocher.

## Story

En tant qu'admin d'un événement,
je veux la page des pelles rangée en onglets, avec des interrupteurs pour les réglages,
afin de trouver d'un coup d'œil ce que je viens faire.

## Critères d'acceptation

1. **En-tête commun** : titre, phrase sur l'échéance, chiffres (distribuées, en circulation, et après la fin :
   converties, détruites) restent au-dessus des onglets.
2. **Onglets** : « Membres (n) », « Distribuer », « Blind test ». Les deux derniers n'existent pas une fois
   l'événement terminé. Les onglets suivent le motif ARIA (tablist, flèches gauche et droite) de la fiche membre ; l'onglet
   ouvert est dans l'adresse (`?onglet=`), donc un rechargement ou un lien rouvre le même. Défaut : « Membres ».
   L'onglet « Blind test » porte une pastille quand le blind test est ouvert.
3. **Distribuer** : montant et libellé, puis les destinataires en choix segmenté « Tous les inscrits (n) » /
   « Une sélection ». En sélection, la liste des inscrits non bannis se coche dans l'onglet même, avec « Tout
   cocher » et « Tout décocher ». Le bouton dit toujours à combien de membres il distribue ; confirmation inchangée.
4. **Blind test** : deux blocs.
   - **Accès** : interrupteur « Blind test ouvert », identifiant pour l'app (copier), lien vers la console.
   - **Gains** : pelles par source, plafond par membre, interrupteur « Les non-inscrits gagnent des pelles ».
   Chaque interrupteur porte son libellé et une phrase d'explication. Enregistrement inchangé (un bouton).
5. **Membres** : la liste en lecture, avec solde, « banni », « non inscrit ».
6. Aucun changement d'API.

## Tâches

- [x] Onglets de la page (`admin-event-pelles-page.tsx`), état dans l'adresse.
- [x] Distribution : choix segmenté, sélection dans l'onglet, tout cocher / décocher.
- [x] Carte blind test : blocs Accès et Gains, `Switch`.
- [x] Tests (`event-pelles.test.tsx`, `blind-test.test.tsx`) ; `pnpm gates`.
