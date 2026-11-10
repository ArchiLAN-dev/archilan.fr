# Story 33.30: Vider les tables de test sans TRUNCATE

**Status:** review
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

- [x] Mesurer l'état actuel (fichiers, durées).
- [x] Remplacer le `TRUNCATE` par le nettoyage sans fichier, séquences comprises.
- [x] Vérifier la suite en série et sur ParaTest.
- [x] Mesurer après, noter les chiffres.
- [x] Mettre à jour le commentaire et `api/CLAUDE.md` (AC-T8).
- [x] Gates verts.

## Notes

- Si `DELETE` laisse trop de lignes mortes sur une table très sollicitée, autovacuum s'en charge. Ne pas ajouter de
  `VACUUM` par test, ce serait plus lent que le `TRUNCATE`.
- La CI n'a pas ce problème (base neuve à chaque run), mais elle gagnera peut-être du temps aussi : à mesurer.

## Dev Agent Record

### Implémentation

`FunctionalTestCase::setUp` envoie en un seul aller-retour :
- `SET session_replication_role = replica` (les clés étrangères sont des triggers, que `replica` saute) ;
- un `DELETE FROM` par table ;
- `SET session_replication_role = DEFAULT`.
Puis il remet à leur valeur de départ les séquences déjà utilisées, avec `setval(..., start_value, false)`. Le rôle
superutilisateur nécessaire à `session_replication_role` est déjà celui de la base de test en local et en CI
(`POSTGRES_USER`). `api/CLAUDE.md` (AC-T8) décrit le nouveau nettoyage et interdit d'y remettre `TRUNCATE`.

### Mesures (2026-11-09, base neuve à chaque passe, cache Symfony préchauffé)

| Passe | Durée | Fichiers sous `base/` après la passe | Checkpoint suivant |
|---|---|---|---|
| ParaTest 8 workers, TRUNCATE | 66 s | 778 015 | 509 s |
| ParaTest 8 workers, DELETE | 44 s | 5 725 | 18 s |
| Série, TRUNCATE | 407 s | 773 024 | plus de 40 min (encore en cours à la rédaction) |
| Série, DELETE | 244 s | 759 | 8 s |

Résultat :
- environ 136 fois moins de fichiers en ParaTest et 1000 fois moins en série ;
- les suites vont aussi plus vite, 33 % en ParaTest et 40 % en série, au lieu du ralentissement que l'AC tolérait.

Dans les quatre passes, les 3611 tests sont verts, sans notice ni dépréciation.

### Notes

- Une première passe ParaTest à froid a échoué sur le cache Symfony construit par 8 workers à la fois (« Accès
  refusé » sur les renommages du conteneur) : c'est l'environnement, pas la story. Préchauffer avec
  `php bin/console cache:warmup --env=test` avant de mesurer.
