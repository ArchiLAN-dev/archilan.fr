# Story 16.21: Archiver une partie

**Status:** review
**Epic:** 16 - Personal Runs - Private User-Created Archipelago Games
**Date:** 2026-10-05

## Story

En tant que joueur ou admin d'ArchiLAN,
je veux archiver une partie personnelle dont je n'ai plus l'usage,
afin qu'elle ne m'encombre plus dans mes listes, sans rien perdre de ce qu'elle a produit.

## Contexte

Une fiche réelle (36.9) montre 21 runs pour un seul membre, dont des brouillons de test et des runs terminées
depuis longtemps. Rien ne permet de les ranger : une run ne disparaît que supprimée, et la suppression n'existe
que pour un brouillon. Décisions de Jean (2026-10-05) :

- **Chacun pour soi** : un participant (propriétaire ou invité) archive la run dans SA liste ; les autres la voient
  toujours. Un admin peut archiver pour un membre, depuis sa fiche.
- **Hors partie en cours** : brouillon, terminée ou annulée. Une run qui tient un serveur (`starting`, `active`,
  `stopping`, `idle`, `restarting`) doit d'abord être arrêtée.
- **Réversible** : une run archivée se retrouve sous « Archivées » et se désarchive. Rien n'est supprimé (récaps,
  historique, statistiques, succès intacts).

Le propriétaire n'a pas toujours de ligne `run_participant` (elle naît quand il choisit ses jeux) : l'archivage
vit dans une table à part, une ligne par (run, membre), plutôt que sur le participant.

**Collision découverte en cours de route** : un « Archiver » existait déjà sur la page d'une run (propriétaire,
brouillon ou démarrage). Il **annule** la run pour tous (statut `cancelled`, section « Annulées ») et
« Désarchiver » la rétablit - un autre concept. Décision de Jean : il devient « Annuler la partie » / « Rétablir »
(routes `/archive` et `/unarchive` inchangées), et « Archiver » ne désigne plus que le rangement personnel.

## Critères d'acceptation

1. Table `personal_run_archive` (run, membre, date d'archivage), clé (run, membre). Migration réversible.
2. Archiver / désarchiver pour soi : `POST` / `DELETE /api/v1/runs/{runId}/personal-archive`, pour le propriétaire
   ou un participant de la run. Refus explicites : run introuvable (404), membre étranger à la run (403), partie en
   cours (409 `run_live`, « Arrête la partie avant de l'archiver »). Archiver deux fois ou désarchiver une run non
   archivée ne change rien (204).
3. Archiver / désarchiver pour un membre, par un admin : `POST` / `DELETE /api/v1/admin/users/{userId}/runs/{runId}/archive`,
   mêmes règles, tracé dans le journal d'activité du membre (« Partie archivée / désarchivée pour le membre »).
4. `listMine` marque chaque run `archived: true|false` pour l'appelant ; la lecture admin de la fiche aussi.
5. « Mes parties » : les runs archivées quittent leurs sections et se regroupent sous « Archivées (N) », repliée par
   défaut, chacune avec « Désarchiver ». Une run archivable (brouillon, terminée, annulée) porte « Archiver ».
6. Fiche admin (« Runs et parties », 36.9) : les runs archivées quittent les autres filtres (« Tout » compris) pour un
   filtre « Archivées » ; chaque run archivable porte « Archiver », chaque run archivée « Désarchiver ». Une partie
   sans run (événement, hebdo) ne s'archive pas.
7. Page d'une run : l'ancien « Archiver » devient « Annuler la partie » (modale « Annuler la partie ? », bouton de
   retour « Garder la partie »), « Désarchiver » devient « Rétablir », « Cette partie est archivée » devient
   « Cette partie est annulée ».
8. Page d'une run, onglet « Vue d'ensemble » : le propriétaire ou un participant y archive / désarchive la run pour
   lui (encadré dédié, distinct de « Annuler la partie ») ; `GET /api/v1/runs/{runId}` porte `archived` pour
   l'appelant.
9. Tests et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Entité `RunArchive`, `RunArchiveRepositoryInterface` + Doctrine, migration.
- [x] **Task 2** (AC 2-4) - Commande `PersonalRunArchive` (échecs typés epic 35), endpoints membre et admin
  (`AdminUserActions::setRunArchived`, audit `run_archive` / `run_unarchive`), `archived` dans `listMine` et la
  lecture admin ; tests unitaires (`listMine`) et fonctionnels (`PersonalRunArchiveTest`).
- [x] **Task 3** (AC 5) - « Mes parties » : section Archivées, bouton sur la carte.
- [x] **Task 4** (AC 6) - Fiche admin : filtre Archivées, bouton, libellés du journal.
- [x] **Task 5** (AC 7) - Renommage de l'annulation sur la page d'une run.
- [x] **Task 6** (AC 8) - Archivage depuis la page d'une run.
- [x] **Task 7** (AC 9) - Gates et vérification dans l'app.

## Notes techniques

- Table à part plutôt qu'un drapeau sur `run_participant` : le propriétaire n'a de ligne participant qu'une fois
  ses jeux choisis ; en créer une pour l'archivage le compterait comme joueur.
- Désarchiver ne vérifie pas la participation : on ne fait que retirer sa propre ligne.
- La migration a été jouée sur la base de dev de Jean pendant le développement (le serveur sert le tree principal).
- Fiche admin : sur sa propre fiche, un admin ne voit plus « Arrêter » ni « Archiver » (l'API lui refuse son propre
  compte ; c'était déjà le cas pour « Arrêter », qui s'affichait quand même).
- Vérifié dans l'app sur « Mes parties » : 12 runs archivables (5 brouillons, 7 terminées, aucune en pause) ;
  « 2048 » archivée puis désarchivée, revenue dans Brouillons.
- Autres listes de runs vérifiées : `/runs` et `/compte/parties` montent le même composant, et aucune autre route
  ne liste les runs d'un membre (`findByOwnerId` / `findJoinedByUserId` ne servent qu'à `listMine`). L'historique
  public et les récaps passent par d'autres lectures et restent intacts, comme voulu.
- Relecture : une run supprimée (`hardDelete`) emporte ses archives ; une run qu'on démarre (`PersonalRunLifecycle::start`)
  sort des archives de tous - sinon elle tournerait dans un « Archivées » replié, invisible de ses participants.
  `RunArchiveRepositoryInterface::deleteByRunId`, deux tests fonctionnels.
