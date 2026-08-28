# Story 16.19: Réglages d'une partie privée pour un administrateur

**Status:** implémentée - PR vers `develop`
**Epic:** 16 - Personal runs (parties privées créées par un membre)
**Date:** 2026-08-28
**Stories liées :** l'accès admin en lecture aux parties privées (2026-08-22), qui a ouvert la page
sans ouvrir ce qu'on y fait

## Story

En tant qu'administrateur d'ArchiLAN,
je veux disposer des réglages d'une partie privée dont je ne suis pas propriétaire,
afin de dépanner un membre sans avoir à lui demander de faire les gestes à ma place.

## Context

Un administrateur peut déjà **ouvrir** une partie privée qui n'est pas la sienne : le backoffice les
liste dans la fiche d'un membre, et une story de correction lui a donné la lecture de la page. Elle a
aussi posé une limite explicite, qui tient toujours : le lien d'invitation, le mot de passe de
session et le journal de génération lui restent fermés.

Mais l'onglet **Réglages** n'existe que pour le propriétaire, et ses trois blocs sont gardés
`isOwnedBy` côté serveur. Un administrateur qui voit une partie mal configurée ne peut donc que
demander au membre de la corriger lui-même - y compris quand le membre est justement celui qui
n'y arrive pas.

### Trois blocs, trois portées

| bloc | ce qu'il fait | garde actuelle |
|---|---|---|
| Configuration avancée | override des options du serveur Archipelago | `PersonalRunConfigOverride::guard()` → `isOwnedBy` |
| Seed importée | import ou remplacement de l'archive, assignation des slots | `RunSlotCoPlayers` / `PersonalRunSeedImport` → `isOwnedBy` |
| Supprimer la partie | suppression définitive d'une partie en veille | propriétaire |

Afficher l'onglet sans toucher au serveur donnerait à l'administrateur trois panneaux dont chaque
bouton répond `403`. L'autorisation doit bouger avec l'interface, ou pas du tout.

### Ce que l'administration trace déjà

La story 36.6 a créé `admin_user_action_audit` pour exactement ce motif : deux gestes d'admin sur le
compte d'un membre n'avaient aucune trace, alors que le reste de l'épic 36 existe pour rendre le
passé lisible. La table est lue par `DbalAdminUserActivityQuery` et remonte dans la fiche du membre.

Modifier ou supprimer la partie de quelqu'un appartient à la même famille : sans trace, le
propriétaire constate le changement sans savoir qui l'a fait.

### Décisions de cadrage (Jean, 2026-08-28)

| Question | Décision |
|---|---|
| Portée | **Les trois blocs.** L'administrateur peut déjà arrêter une partie et lire son spoiler ; la configuration, la seed et la suppression ne sont pas d'une autre nature. |
| Traçabilité | **Tracée comme une action admin sur un compte**, dans `admin_user_action_audit`. Ce qui reste sans trace finit par n'avoir jamais eu lieu. |
| Ce qui reste fermé | Inchangé : lien d'invitation, mot de passe de session, journal de génération. L'administrateur dépanne la partie, il ne se substitue pas au propriétaire auprès des autres joueurs. |

## Acceptance Criteria

### Autorisation

1. Un administrateur lit et modifie l'**override de configuration** d'une partie privée dont il n'est
   ni propriétaire ni participant.
2. Il peut **importer ou remplacer la seed** d'une partie en brouillon, et **assigner les slots** de
   l'archive aux participants, dans les mêmes conditions que le propriétaire.
3. Il peut **supprimer** une partie en veille, avec la même confirmation que le propriétaire.
4. Les états qui bloquent le propriétaire bloquent aussi l'administrateur : une partie lancée ne
   change pas de seed, une partie qui n'est pas en veille ne se supprime pas. Le rôle ouvre une
   porte, il ne lève pas les règles du domaine.
5. **Rien d'autre ne s'ouvre.** Le lien d'invitation, le mot de passe de session et le journal de
   génération restent réservés au propriétaire, comme la story d'accès en lecture l'a décidé. À
   vérifier sur la charge utile, pas seulement à l'écran.
6. Un membre qui n'est ni propriétaire, ni administrateur, ni participant ne gagne rien.

### Traçabilité

7. Chaque action d'administrateur sur la partie d'un autre membre est enregistrée dans
   `admin_user_action_audit`, avec pour cible le **propriétaire de la partie** - c'est là qu'on la
   cherchera, dans sa fiche.
8. Un propriétaire qui agit sur sa propre partie n'écrit rien : la trace existe pour distinguer
   l'intervention extérieure, pas pour journaliser l'usage normal.
9. Les actions tracées sont nommément distinguées (configuration, seed, assignation, suppression) :
   « un admin a touché à cette partie » n'aide personne à comprendre quoi.

### Interface

10. L'onglet **Réglages** apparaît pour un administrateur comme pour le propriétaire.
11. L'administrateur voit qu'il agit sur la partie de quelqu'un d'autre. Une interface identique à
    celle du propriétaire invite à oublier de qui est la partie qu'on modifie.

### Gates

12. `composer gates` et `pnpm gates` verts. Tests : les trois actions par un administrateur, leur
    refus pour un tiers, les états du domaine qui bloquent aussi l'administrateur, l'écriture de la
    trace et son absence pour le propriétaire.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-4, 6). Élargir les trois gardes d'autorisation au rôle administrateur, sans
  toucher aux règles d'état du domaine.
- [x] **Task 2** (AC 7-9). Trace d'audit sur les quatre actions, ciblée sur le propriétaire.
- [x] **Task 3** (AC 5). Revue de la charge utile : ce qui était fermé le reste.
- [x] **Task 4** (AC 10-11). Onglet visible, et mention explicite qu'il s'agit de la partie d'un autre.
- [x] **Task 5** (AC 12). Tests et gates.

## Dev Notes

- **Le drapeau existe déjà.** `PersonalRunDrafts::get(string $runId, string $callerId, bool $isAdmin)`
  reçoit `$isAdmin` depuis le contrôleur, qui le lit sur `ROLE_ADMIN`. C'est un usage de rôle pour de
  l'affichage et de l'administration, explicitement permis par `AC-M3` de `api/CLAUDE.md` -
  `ROLE_MEMBER` serait interdit ici, `ROLE_ADMIN` ne l'est pas.
- **Quatre gardes à élargir, pas une.** `PersonalRunConfigOverride::guard()`,
  `PersonalRunSeedImport::import()` et `::assign()`, `RunSlotCoPlayers::replace()`, et la suppression
  dans `PersonalRunLifecycle`. Les recenser avant d'écrire : en oublier une donne un onglet à moitié
  ouvert, ce qui est pire qu'un onglet fermé.
- **Ne pas élargir `isStartAllowedFor`.** Le démarrage et l'arrêt ont leurs propres règles
  (story 16.14 : un participant peut reprendre une partie en veille) ; cette story ne les touche pas.
- **Réutiliser la table d'audit plutôt qu'en créer une.** `AdminUserActionAudit::record()` prend une
  cible, un admin, une action et un instant. La cible est un `user_id` : passer le propriétaire de la
  partie fait remonter la trace dans sa fiche, là où la story 36.6 l'a déjà rendue lisible. Ajouter
  les constantes d'action à l'entité.
- **Ce que la story ne fait pas :** ouvrir le lien d'invitation ou le mot de passe de session à
  l'administrateur, lui donner les réglages d'une partie d'événement, ni créer une surface
  d'administration dédiée aux parties privées.

## Écarts assumés

### Le roster de co-joueurs n'est pas ouvert à l'administrateur

Les Dev Notes citaient `RunSlotCoPlayers::replace()` parmi les gardes à élargir. À l'implémentation,
elle n'a rien à faire ici : ce panneau vit sur la fiche d'un participant, pas dans l'onglet Réglages,
et aucun critère d'acceptation ne le mentionne. L'ouvrir aurait été du périmètre pris en passant.

L'assignation des slots **d'une archive importée** (`PersonalRunSeedImport::assign()`), elle, est bien
dans Réglages et bien ouverte : c'est ce que l'AC 2 demandait.

### Un test dont le nom contredisait son assertion

`testAdminCannotDeleteARunThatIsNotIdle` affirmait un refus et vérifiait un `204` : une partie en
brouillon **est** supprimable, pour le propriétaire comme pour l'administrateur. Renommé, et doublé
d'un cas qui vérifie vraiment la règle d'état (`testAdminCannotDeleteAnActiveRun`, `422`).

Un test dont le nom ment est pire qu'une absence de test : il rassure sur ce qu'il ne couvre pas.

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-08-28 | 0.1 | Rédaction de la story | Claude |
| 2026-08-28 | 1.0 | Implémentation ; roster de co-joueurs laissé hors périmètre | Claude |
