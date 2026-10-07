# Story 41.1: Registre des pelles et portefeuille

**Status:** review
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
2. **Idempotence** : enregistrer un mouvement avec une clé d'unicité déjà utilisée ne crée rien et rend le mouvement
   existant (pas de double crédit si une action est rejouée). Garanti par un index unique, pas seulement par une
   lecture préalable.
3. Le solde d'un membre se calcule par type (et par événement pour les pelles d'event) ; **un solde ne peut
   jamais devenir négatif** : un débit qui le ferait passer sous zéro est refusé. Le contrôle et l'écriture se
   font dans la même transaction, avec un verrou sur le membre : deux débits simultanés ne passent pas tous les
   deux.
4. Un mouvement ne se modifie ni ne se supprime : une correction est un mouvement inverse.
4 bis. **Compte supprimé** : ses mouvements restent, rattachés au membre anonymisé (`DeleteAccount`).
   **Compte banni** : aucun mouvement ne peut être enregistré pour lui (gain comme dépense), sauf par un admin.

### Portefeuille

5. Page **« Mon portefeuille »** dans l'espace membre : solde en or, soldes d'event en cours, historique paginé
   (25 par page ; date, libellé, montant, type). Le solde est **privé** : visible du membre et des admins
   seulement, absent du profil public.
6. Le solde en or s'affiche aussi dans le menu du compte, avec l'icône de pelle.

### Admin

7. Sur la fiche admin d'un membre (epic 36), action **« Créditer / débiter des pelles »** : montant entre 1 et
   10 000 (garde-fou contre une faute de frappe), type (et événement pour une pelle d'event), motif libre
   obligatoire ; la confirmation affiche le solde avant et après ; tracée dans le journal d'audit admin et dans
   le registre ; le membre reçoit une notification du site (après la transaction). Un débit qui passerait sous
   zéro est refusé avec le solde disponible.
8. **Tableau de bord de circulation** (admin) : pelles en or en circulation, créées et détruites par semaine,
   par motif. C'est l'alerte d'inflation de l'epic.
9. Un admin ne peut pas se créditer ni se débiter lui-même (même règle que les actions ciblées de l'epic 36,
   `AdminUserActions`).

### Gates

10. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-4) - Domaine : mouvement, types, motifs, règle de solde non négatif ; table et migration
      (index unique sur la clé d'unicité) ; repository ; tests.
- [x] **Task 2** (AC 7, 9) - Commande admin créditer / débiter, audit, notification après commit ; tests.
- [x] **Task 3** (AC 5, 6, 8) - Requêtes solde, historique, circulation ; endpoints ; tests.
- [x] **Task 4** (AC 5-8) - Front : page portefeuille, solde dans le menu, action admin, tableau de bord ; tests.
- [x] **Task 5** (AC 10) - Gates.

## Notes techniques

- **Nouveau contexte `Wallet`** : il doit être ajouté à `DddArchitectureValidator::CONTEXTS`, sinon le validateur
  le rejette. Il dépend d'`Identity` (membres, bannissement), d'`Events` (pelles d'event) et de `Community`
  (notifications), jamais l'inverse ; les contextes qui créditeront plus tard (Sessions, primes, quêtes) passeront
  par une commande de `Wallet`.
- Les pelles d'event existent dès cette story dans le modèle et dans l'action admin, mais rien ne les dépense ni ne
  les fait expirer avant la 41.2 : c'est voulu, pour ne pas migrer le registre ensuite.
- Calcul du solde par agrégat SQL sur le registre ; un solde matérialisé ne sera ajouté que si la mesure le
  justifie. Débit et contrôle du solde dans la même transaction, avec verrou sur le membre, pour qu'un double
  clic ne passe pas sous zéro.
- Décision 6 de l'epic (bonus de lancement) à trancher avant la mise en production, pas avant le développement.

## Dev Agent Record

- **Contexte `Wallet`** : `PelleMovement` (entité append-only, clé unique optionnelle), `PelleKind` (gold/event),
  `PelleReason` (admin_credit/admin_debit) ; table `pelle_movement` (migration Version20261003100000).
- **`RecordPelleMovement`** : seul point d'écriture du registre. Verrou `SELECT ... FOR UPDATE` sur la ligne du
  membre, solde relu sous verrou, refus `insufficient_pelles` (422, `details.available`), clé déjà vue = mouvement
  existant renvoyé. Membre supprimé = 404, banni = 403 sauf `byAdmin`. Une fermeture optionnelle écrit dans la
  même transaction (audit admin).
- **`AdjustMemberPelles`** : bornes 1..10000, motif obligatoire (200 car.), refus sur soi (403), audit
  `pelles_credit` / `pelles_debit` dans la transaction, notification `pelles_adjusted` après commit.
- **Endpoints** : `GET /api/v1/me/wallet?page=`, `GET|POST /api/v1/admin/users/{id}/pelles`,
  `GET /api/v1/admin/pelles/circulation` (or seulement, 12 semaines, par motif).
- **Front** : `/compte/portefeuille`, solde or dans le menu du compte, section « Pelles » de la fiche admin
  (confirmation avant/après), `/admin/pelles`, notification, et le journal admin affiche enfin les actions
  admin (`admin_action_received/performed`, jusque-là « Entrée inconnue »).
- Compte supprimé : l'utilisateur est anonymisé, pas supprimé, donc ses lignes restent (test dédié).
