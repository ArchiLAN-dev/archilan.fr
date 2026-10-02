# Story 41.1: Registre des pelles et portefeuille

**Status:** ready-for-dev
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-02

## Story

En tant que membre,
je veux voir mon solde de pelles et l'historique de ce que j'ai gagné et dépensé,
afin de savoir d'où viennent mes pelles et ce que j'en ai fait.

En tant qu'admin,
je veux créditer ou débiter des pelles à un membre, de façon tracée, et voir combien de pelles circulent,
afin d'animer les événements sans tableur et de surveiller l'économie dès le premier jour.

## Contexte

Socle de l'epic 41. Les stories suivantes (events, hints, primes, quêtes, boutique) ne font qu'ajouter des
sources et des puits au même registre. Les deux types de pelles sont prévus dès maintenant : les ajouter après
coup serait une migration pénible.

## Critères d'acceptation

### Registre

1. Un mouvement = membre, montant (entier, positif en gain, négatif en dépense, jamais nul), **type** (`gold` ou
   `event`), pour un `event` l'identifiant de l'événement, **motif** (`admin_credit`, `admin_debit`, et ceux des
   stories suivantes), libellé lisible, auteur (admin ou système), date, **clé d'unicité** facultative.
2. Une clé d'unicité déjà utilisée refuse le mouvement sans erreur (pas de double crédit si une action est
   rejouée).
3. Le solde d'un membre se calcule par type (et par événement pour les pelles d'event) ; **un solde ne peut
   jamais devenir négatif** : un débit qui le ferait passer sous zéro est refusé.
4. Un mouvement ne se modifie ni ne se supprime : une correction est un mouvement inverse.

### Portefeuille

5. Page **« Mon portefeuille »** dans l'espace membre : solde en or, soldes d'event en cours, historique paginé
   (date, libellé, montant, type).
6. Le solde en or s'affiche aussi dans le menu du compte, avec l'icône de pelle.

### Admin

7. Sur la fiche admin d'un membre (epic 36), action **« Créditer / débiter des pelles »** : montant, type
   (et événement pour une pelle d'event), motif libre obligatoire ; confirmation ; tracée dans le journal
   d'audit admin et dans le registre ; le membre reçoit une notification du site.
8. **Tableau de bord de circulation** (admin) : pelles en or en circulation, créées et détruites par semaine,
   par motif. C'est l'alerte d'inflation de l'epic.
9. Un admin ne peut pas se créditer lui-même (comme les autres actions ciblées de l'epic 36).

### Gates

10. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1-4) - Domaine : mouvement, types, motifs, règle de solde non négatif ; table et migration
      (index unique sur la clé d'unicité) ; repository ; tests.
- [ ] **Task 2** (AC 7, 9) - Commande admin créditer / débiter, audit, notification après commit ; tests.
- [ ] **Task 3** (AC 5, 6, 8) - Requêtes solde, historique, circulation ; endpoints ; tests.
- [ ] **Task 4** (AC 5-8) - Front : page portefeuille, solde dans le menu, action admin, tableau de bord ; tests.
- [ ] **Task 5** (AC 10) - Gates.

## Notes techniques

- Nouveau contexte `Wallet` (ou dans `Community`, à trancher à l'implémentation selon le validateur DDD).
- Calcul du solde par agrégat SQL sur le registre ; un solde matérialisé ne sera ajouté que si la mesure le
  justifie. Débit et contrôle du solde dans la même transaction, avec verrou sur le membre, pour qu'un double
  clic ne passe pas sous zéro.
- Décision 6 de l'epic (bonus de lancement) à trancher avant la mise en production, pas avant le développement.
