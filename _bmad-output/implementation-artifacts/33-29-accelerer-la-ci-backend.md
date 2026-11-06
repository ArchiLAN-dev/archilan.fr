# Story 33.29: Accélérer la CI backend

**Status:** ready-for-dev
**Epic:** 33 - Nettoyage et standards
**Date:** 2026-11-06

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

- [ ] Tâche 1 : séparer l'analyse statique et les tests (AC 3, 5)
- [ ] Tâche 2 : ParaTest et bases de test par worker (AC 1)
- [ ] Tâche 3 : couverture hors PR (AC 2)
- [ ] Tâche 4 : caches des outils (AC 4)
- [ ] Tâche 5 : mesure et PR de contrôle (AC 6, 7)

## Notes

- ParaTest est plus strict que le mode série (story 33.26). Une exécution série reste possible avec `--serial`.
- Sur la couverture : une baisse sous le plancher ne se verra qu'après le merge, sur `develop`. C'est le coût accepté.
- Le Postgres de service de la CI doit accepter 8 bases et les connexions des workers.
