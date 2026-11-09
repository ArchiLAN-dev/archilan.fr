# Story 33.30: Vider les tables de test sans TRUNCATE

**Status:** ready-for-dev
**Epic:** 33 - Nettoyage et standards
**Date:** 2026-11-09
**Origine :** incident local du 2026-11-09. Après un arrêt brutal de Docker Desktop, Postgres a mis plus de 30 minutes
à redémarrer, et la purge des bases de test a ensuite bloqué sur des checkpoints de 18 et 28 minutes.

## Story

En tant que développeur,
je veux que la suite fonctionnelle vide ses tables sans créer de fichiers,
afin que les gates locaux ne produisent plus des millions de fichiers qui rendent Postgres lent à redémarrer, à
checkpointer et à supprimer une base.

## Contexte

- `FunctionalTestCase::setUp` vide toutes les tables avant chaque test avec
  `TRUNCATE TABLE ... RESTART IDENTITY CASCADE` (story 33.25).
- Pour Postgres, un `TRUNCATE` donne un nouveau fichier (relfilenode) à chaque table, à son index et à sa table TOAST.
  L'ancien fichier reste jusqu'au checkpoint suivant, et `RESTART IDENTITY` fait de même pour chaque séquence.
  Avec des centaines de tables, 3611 tests et 8 workers, une passe de `composer gates:fast` produit **plus d'un million
  de fichiers**. Mesures du 2026-11-09 :
  - un checkpoint a synchronisé 1 401 628 fichiers en 17 minutes, le suivant 2 477 138 fichiers en 27 minutes ;
  - le répertoire de données comptait environ 5 millions de fichiers pour 99 bases, dont environ 480 000 par base
    `archilan_test_stophook*` (20 Mo chacune).
- `docker-compose.yml` est désormais en `recovery_init_sync_method=syncfs` (PR #1026). Ça raccourcit le redémarrage
  après crash, mais pas les checkpoints ordinaires ni les `DROP DATABASE`.

## Critères d'acceptation

1. `FunctionalTestCase::setUp` vide les tables sans `TRUNCATE` : `DELETE` sur chaque table, contraintes de clé
   étrangère neutralisées le temps du nettoyage (`SET session_replication_role = replica`, puis retour à `origin`),
   ou un autre moyen équivalent qui ne crée pas de nouveau relfilenode.
2. Les séquences repartent à leur valeur initiale sans nouveau fichier (`setval(..., false)` plutôt que
   `ALTER SEQUENCE ... RESTART`). Un test qui dépend d'un identifiant généré se comporte comme avant.
3. Toute la suite reste verte, en série (`composer test`) et sur ParaTest (`composer test:parallel`), sans nouvelle
   notice ni dépréciation.
4. **Mesure, avant et après**, notée dans la story, sur une base neuve :
   - le nombre de fichiers sous `base/` de la base de test après une passe complète ;
   - la durée de la suite série et de la suite ParaTest.
   Cible : au moins 10 fois moins de fichiers, sans ralentir la suite de plus de 10 %.
5. Le commentaire de `FunctionalTestCase` et la section « Testing standards » de `api/CLAUDE.md` (AC-T8) décrivent le
   nouveau nettoyage. Le test `StandardsDocsMatchToolingTest` reste vert.

## Tâches

- [ ] Mesurer l'état actuel (fichiers, durées).
- [ ] Remplacer le `TRUNCATE` par le nettoyage sans fichier, séquences comprises.
- [ ] Vérifier la suite en série et sur ParaTest.
- [ ] Mesurer après, noter les chiffres.
- [ ] Mettre à jour le commentaire et `api/CLAUDE.md` (AC-T8).
- [ ] Gates verts.

## Notes

- Si `DELETE` laisse trop de lignes mortes sur une table très sollicitée, autovacuum s'en charge. Ne pas ajouter de
  `VACUUM` par test, ce serait plus lent que le `TRUNCATE`.
- La CI n'a pas ce problème (base neuve à chaque run), mais elle gagnera peut-être du temps aussi : à mesurer.

## Dev Agent Record

_(à remplir)_
