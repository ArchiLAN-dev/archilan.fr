# Story 33.29: Accélérer la CI backend

**Status:** review
**Epic:** 33 - Nettoyage et standards
**Date:** 2026-11-06
**Issue:** #1205

## Story

En tant que mainteneur,
je veux une validation backend plus courte sur les PR,
afin de ne plus attendre 10 à 14 minutes à chaque PR et à chaque release.

## Contexte

Le job `Backend checks` enchaîne tout en série : audit, PHPStan, PHP CS Fixer, architecture, puis PHPUnit avec couverture
sur un seul process. Il a pris 13m51 sur la #1017 et 10m19 sur la #1018. En local, `scripts/gates.sh` fait les mêmes
contrôles en 2 minutes environ avec ParaTest (story 33.26).

## Critères d'acceptation

1. **ParaTest** : PHPUnit tourne sur ParaTest en CI, chaque worker sur sa propre base de test.
2. **Couverture** : les PR ne mesurent plus la couverture. Les pushes sur `develop` et `main` la mesurent et gardent le
   plancher de 65 %, ainsi que l'artefact `coverage-report`.
3. **Analyse statique** : audit, PHPStan, PHP CS Fixer, architecture et Rector tournent dans un job parallèle aux tests.
4. **Caches** : les caches de PHPStan, PHP CS Fixer et Rector sont conservés entre deux runs, avec une clé liée à
   `composer.lock` et aux fichiers de configuration.
5. **Check requis** : le check `Backend` reste le seul requis. Il échoue si l'un des jobs échoue, et il passe quand le
   filtre de chemins saute le backend. La protection de branche ne change pas.
6. **Mesure** : la story note le temps avant et après sur trois PR qui touchent `api/`. Cible : moins de 5 minutes.
7. Un test volontairement cassé, puis une erreur PHPStan volontaire, font bien échouer `Backend` sur une PR de contrôle.

## Tâches

- [x] Tâche 1 : séparer l'analyse statique et les tests (AC 3, 5)
- [x] Tâche 2 : ParaTest et bases de test par worker (AC 1)
- [x] Tâche 3 : couverture hors PR (AC 2)
- [x] Tâche 4 : caches des outils (AC 4)
- [x] Tâche 5 : mesure et PR de contrôle (AC 6, 7)

## Notes

- ParaTest est plus strict que le mode série (story 33.26). Une exécution série reste possible avec `--serial`.
- Sur la couverture : une baisse sous le plancher ne se verra qu'après le merge, sur `develop`. C'est le coût accepté.
- Le Postgres de service de la CI doit accepter 8 bases et les connexions des workers.

## Dev Agent Record

### Implémentation

- `backend.yml` : deux jobs après le filtre de chemins.
  - `Backend static analysis` : validate, audit, PHPStan, CS Fixer, architecture, Rector.
  - `Backend tests` : Postgres de service et `composer test:parallel` (8 workers). Chaque worker a sa base
    `archilan_test1..8`, créée par `BuildSchemaOnceSubscriber` (`doctrine:database:create --if-not-exists`,
    l'utilisateur du service est superutilisateur).
- Installation commune dans l'action composite `.github/actions/api-setup`, que le filtre de chemins surveille.
- Couverture : pcov n'est activé que hors PR. Sur un push vers `develop` ou `main`, ParaTest produit
  `coverage.xml` et `coverage-html`, puis le plancher de 65 % et l'artefact `coverage-report` s'appliquent.
- Caches : `tmpDir` de PHPStan, `withCache` de Rector et `setCacheFile` de CS Fixer pointent sous
  `api/var/tools-cache`. Ce dossier est conservé par `actions/cache` avec une clé par commit et une
  restauration par préfixe (`composer.lock` + les trois configurations). En local, cache chaud : 47 s puis 11 s.
- `Backend` (seul check requis) échoue si `changes`, `static` ou `tests` échoue ou est annulé, et passe quand
  les deux jobs sont sautés.
- Docs : `CLAUDE.md`, `api/CLAUDE.md` et `scripts/gates.sh` disent que la CI tourne sur ParaTest. La commande
  série reste la référence quand on doute d'un test sensible à l'ordre.

### Mesure (AC 6)

Avant, durée du job `Backend checks` : #1195 9m26, #1201 9m55, #1202 9m14.

Après, durées des deux jobs parallèles (le plus lent fixe la durée) :

| Run | Analyse statique | Tests |
|---|---|---|
| #1206 (cache des outils froid) | 1m53 | 2m41 |
| #1207, test cassé | 1m58 | 2m41 (échec voulu) |
| #1207, erreur PHPStan (cache chaud) | 0m20 (échec voulu) | 1m31 |

Le workflow complet prend environ 3 minutes contre 9m14 à 9m55 avant. La cible des 5 minutes est tenue.

### Contrôle (AC 7)

PR de contrôle #1207, en brouillon et fermée sans merge :
- un test qui échoue volontairement : `Backend tests` en échec, `Backend` en échec ;
- puis une erreur PHPStan volontaire (`return.type`) avec un test vert : `Backend static analysis` en échec,
  `Backend` en échec.

La couverture sur `develop` (ParaTest avec pcov, plancher de 65 %) se vérifie sur le premier push après le merge.
