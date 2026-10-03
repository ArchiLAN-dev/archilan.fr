# Story 41.2: Distribution de pelles pendant un event

**Status:** in-progress
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-03

## Story

En tant qu'admin,
je veux distribuer des pelles d'event aux inscrits d'un événement, à tous ou à une sélection,
afin d'animer l'ArchiLAN (happenings, défis) sans tableur ni crédit membre par membre.

En tant que membre,
je veux qu'à la fin de l'événement une part de mes pelles d'event non dépensées devienne des pelles en or,
afin que ma participation compte encore après l'événement.

## Contexte

La 41.1 a posé le registre et ses deux types de pelles ; rien ne distribue encore de pelles d'event. Cette story
en fait la source (distribution) et le puits (expiration). Les dépenser viendra avec les hints (41.3).

**Décision 3 de l'epic (Jean, 2026-10-03)** : à la fin d'un événement, une part des pelles d'event restantes
devient des pelles en or, le reste est détruit.

## Critères d'acceptation

### Distribution

1. Page admin **« Pelles »** d'un événement (`/admin/evenements/{id}/pelles`, lien « Pelles » dans la liste des
   événements) : les inscrits actifs (inscription non annulée) avec leur solde de pelles de cet événement, le
   total distribué, le total encore en circulation et, une fois l'événement terminé, ce qui a été converti et détruit.
2. Formulaire de distribution : montant par membre (1 à 1 000), libellé obligatoire (visible dans l'historique du
   membre), destinataires = **tous les inscrits actifs** ou **une sélection** cochée dans la liste. La confirmation
   annonce le nombre de membres et le total distribué.
3. Chaque destinataire reçoit un mouvement `event` (motif `event_distribution`, auteur = l'admin) par
   `RecordPelleMovement`, avec une clé d'unicité tirée d'un identifiant de requête envoyé par le formulaire : un
   double envoi ne crédite pas deux fois.
4. Un membre banni ou supprimé est sauté (pas d'erreur pour les autres) ; la réponse donne le nombre de membres
   crédités, sautés et déjà crédités.
5. Une sélection qui contient un compte non inscrit est refusée (422). Un événement terminé (`ends_at` passé) ne
   reçoit plus de distribution (422).
6. Chaque membre crédité reçoit une notification du site (après la transaction).

### Fin de l'événement

7. Une tâche planifiée (toutes les heures) traite les événements terminés : pour chaque membre qui garde un solde
   de pelles de l'événement, **10 %** de ce solde (arrondi vers le bas) devient des pelles en or (motif
   `event_conversion`), puis tout le solde de l'événement est détruit (motif `event_expired`). Les deux mouvements
   ont une clé d'unicité par événement et par membre : la tâche peut repasser sans rien doubler, et une
   conversion faite avant une panne n'empêche pas la destruction au passage suivant.
8. Un membre banni ne reçoit pas la conversion en or, mais ses pelles d'event sont détruites.

### Affichage

9. Les nouveaux motifs ont un libellé dans l'historique du membre et dans le tableau par motif des statistiques.

### Qualité

10. Gates : `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** (AC 3-6) - Commande de distribution, requête des inscrits, endpoint ; tests fonctionnels.
- [ ] **Task 2** (AC 7, 8) - Commande d'expiration, message planifié et son handler ; tests.
- [ ] **Task 3** (AC 1) - Requête et endpoint de la page Pelles d'un événement ; tests.
- [ ] **Task 4** (AC 1, 2, 9) - Front : page, formulaire, lien, libellés ; tests.
- [ ] **Task 5** (AC 10) - Gates.

## Notes techniques

- Tout passe par `RecordPelleMovement` (règle de la 41.1). La distribution appelle la commande sans `byAdmin` :
  c'est elle qui refuse un compte banni, et la distribution saute ce compte.
- La destruction en fin d'événement est un acte du système sur un compte banni : elle passe `byAdmin` (la règle
  « banni = admin seulement » protège contre les gains, pas contre une expiration).
- Taux de conversion : une constante (10 %) dans la commande d'expiration ; à rendre réglable seulement si
  l'équipe le demande.
- Pas d'audit `AdminUserActionAudit` par destinataire : chaque mouvement porte déjà son auteur, et une
  distribution à 40 inscrits noierait le journal de chaque fiche.
