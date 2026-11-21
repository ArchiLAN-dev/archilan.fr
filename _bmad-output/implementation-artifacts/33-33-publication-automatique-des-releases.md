# Story 33.33: Publication automatique des releases

**Status:** ready-for-dev
**Epic:** 33 - Nettoyage et standards
**Date:** 2026-11-21
**Origine :** release hebdomadaire mise en place le 2026-11-20 (`docs/processus-de-release.md`). Jean veut que son
feu vert se résume au merge de la PR de release.
**Dépend de :** la routine du lundi qui ouvre la PR `release/vX.Y.Z` vers `main`.

## Story

En tant qu'administrateur d'ArchiLAN,
je veux qu'une release soit publiée dès que je merge sa PR,
afin de ne plus dérouler à la main le tag, la release GitHub et le retour sur `develop`.

## Contexte

- Aujourd'hui, après le merge d'une PR `release/*` ou `hotfix/*` vers `main`, il faut à la main :
  1. un tag annoté `vX.Y.Z` sur le commit de merge, poussé ;
  2. `gh release create` avec la section du CHANGELOG ;
  3. une PR de la branche vers `develop`, mergée une fois verte, puis la suppression de la branche ;
  4. la vérification des runs `docker-publish.yml` (tag et `main`).
- Ces étapes ne demandent aucune décision : la version est déjà dans `frontend/package.json` et la section dans
  `CHANGELOG.md`.
- `docker-publish.yml` réagit déjà au push d'un tag `v*.*.*`. Un tag poussé par une Action avec le `GITHUB_TOKEN` ne
  déclenche pas d'autre workflow : il faut pousser le tag avec un jeton qui le permet (GitHub App ou PAT fin, en
  secret), ou appeler la publication des images depuis le même workflow (`workflow_call`).

## Critères d'acceptation

1. **Déclenchement** : un nouveau workflow `release.yml` se lance quand une PR de `release/*` ou `hotfix/*` est
   mergée dans `main`, et seulement dans ce cas (une PR fermée sans merge ne fait rien).
2. **Version** : lue dans `frontend/package.json` du commit de merge. Le workflow échoue avec un message clair si le
   tag `vX.Y.Z` existe déjà ou si `CHANGELOG.md` n'a pas de section `## [X.Y.Z]`.
3. **Tag et release** : tag annoté `vX.Y.Z` sur le commit de merge, release GitHub `vX.Y.Z` dont les notes sont la
   section du CHANGELOG.
4. **Images** : la publication des trois images en `X.Y.Z` et `X.Y` part bien (le piège du `GITHUB_TOKEN` est
   traité), et le workflow attend son résultat.
5. **Retour sur `develop`** : une PR de la branche vers `develop` est ouverte, en merge automatique quand la CI est
   verte ; la branche est supprimée après. En cas de conflit, la PR reste ouverte et le résumé le dit.
6. **Résumé** : le résumé du run (`GITHUB_STEP_SUMMARY`) donne la version, les liens (release, PR de retour) et
   l'état des images ; un échec des images fait échouer le workflow, donc une notification GitHub.
7. `docs/processus-de-release.md` est mis à jour : le feu vert est le merge, la section « Publier » décrit ce que
   fait le workflow et quoi faire s'il échoue.
8. Essai réel sur la release du 2026-11-24, ou sur une release de patch avant si une se présente.

## Tâches

- [ ] `.github/workflows/release.yml` (critères 1 à 6).
- [ ] Jeton ou `workflow_call` pour les images (critère 4) ; secret documenté si besoin.
- [ ] Doc (critère 7).
- [ ] Essai réel (critère 8).

## Hors périmètre

- Le déploiement en production : story 33.34.
- Les services (orchestrateur, bridge, archipelago), tagués à la main quand ils ont des commits non publiés.
