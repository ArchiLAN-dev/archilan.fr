# Story 41.17: Annonces « cherche joueurs »

**Status:** draft
**Epic:** 41 - Des amis qui servent à jouer
**Date:** 2026-10-02
**Dépend de:** 41.14

## Story

En tant que propriétaire d'une run perso en préparation,
je veux publier une annonce visible de tous les membres,
afin de compléter la partie au-delà de mes amis.

## Contexte

Équivalent du *Looking for Group* Xbox. Étend le réglage d'ouverture de 41.14 avec une valeur « Membres » et
une annonce. Le texte libre est un contenu utilisateur : le signalement existe (`ContentReport`,
`ReportCategory`, modération 30.13 / epic 39).

## Critères d'acceptation

1. Le réglage d'ouverture d'une run `draft` gagne la valeur « Membres », qui exige une annonce : message court
   (280 caractères max), jeux déjà choisis (automatique), places voulues, date prévue optionnelle.
2. Une page « Parties qui cherchent des joueurs » (connectés seulement, liée depuis `/communaute`) liste les
   annonces ouvertes, les plus récentes d'abord, avec les amis du viewer déjà inscrits mis en avant.
3. Rejoindre depuis l'annonce : même logique que 41.14 ; mêmes règles de places et de disparition.
4. Une annonce est signalable (`ContentReport`) ; un membre sanctionné ne peut ni publier ni rejoindre ; un
   blocage cache l'annonce dans les deux sens.
5. Une annonce sans arrivée depuis 14 jours expire (la run repasse « Sur invitation »), le propriétaire est
   prévenu.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Domaine/Migration** : `pitch`, `plannedFor`, `listedAt` sur `Run` ; expiration.
- [ ] **Application** : `OpenRunListingsQuery`, intégration du signalement (nouveau type de cible).
- [ ] **Tâche planifiée** : expiration des annonces.
- [ ] **Front** : formulaire d'annonce, page de liste, bouton signaler.
- [ ] Tests et gates.
