# Story 33.34: Déploiement continu du site

**Status:** ready-for-dev
**Epic:** 33 - Nettoyage et standards
**Date:** 2026-11-21
**Origine :** release hebdomadaire mise en place le 2026-11-20. Jean veut aller jusqu'au déploiement automatique.
**Dépend de :** 33.33 (une release GitHub par version).

## Story

En tant qu'administrateur d'ArchiLAN,
je veux que le serveur déploie de lui-même une nouvelle version du site, sauf quand c'est risqué,
afin qu'une release arrive en production sans intervention, et que je sois prévenu de ce qui s'est passé.

## Contexte

- Le déploiement est manuel aujourd'hui (`docs/deploiement-production.md`) : `ARCHILAN_VERSION` dans le `.env` de la
  machine, puis `docker compose pull` et `up -d` ; `api-migrations` joue les migrations et s'arrête.
- **Tiré par le serveur, pas poussé par GitHub** : le dépôt est public, on ne donne pas à GitHub une clé SSH de la
  machine de production. Un script sur la machine regarde les releases GitHub, publiques, et déploie.
- Plusieurs releases demandent une étape à la main (0.73.0 : orchestrateur d'abord ; 0.73.1 : `BRIDGE_IMAGE`) : le
  script ne doit jamais les déployer seul.
- Périmètre : le site seul (`api-web`, `api-worker`, `api-migrations`, `frontend`). L'orchestrateur, le bridge et
  l'image Archipelago touchent aux parties en cours et restent manuels.
- Personne de l'équipe de dev n'a accès à la machine : le script est livré dans le dépôt, l'opérateur l'installe.

## Critères d'acceptation

1. **Marqueur dans le CHANGELOG** : une release qui demande une action à la main porte, dans sa section
   `### Déploiement`, une ligne qui commence par `**Déploiement manuel**`. `docs/processus-de-release.md` et la
   routine du lundi l'écrivent quand il le faut (nouvelle variable, ordre avec un service, commande de rattrapage).
2. **Script `deploy/archilan-deploy.sh`**, sans dépendance au-delà de `bash`, `curl`, `jq` et `docker` :
   - lit la dernière release GitHub (`/repos/ArchiLAN-dev/archilan.fr/releases/latest`) et la compare à
     `ARCHILAN_VERSION` du `.env` ; rien à faire si elle est déjà en place ;
   - **refuse** et prévient : si la section de la release porte le marqueur du critère 1 ; si un event a commencé il
     y a moins de 3 jours ou commence dans les 2 jours (`/api/v1/events`, `startsAt`, `endsAt`) ; si la nouvelle
     version change de majeure ;
   - sinon : sauvegarde de la base (`pg_dump` compressé, gardé 14 jours), `ARCHILAN_VERSION` mis à jour,
     `docker compose pull` puis `up -d` des services du site ;
   - **vérifie** pendant 5 minutes au plus : `api-migrations` sorti en 0, l'API et la page d'accueil répondent 200,
     la version servie est la nouvelle ;
   - **en cas d'échec**, remet l'ancienne `ARCHILAN_VERSION` et relance ; la sauvegarde reste pour restaurer la base
     si une migration est passée.
   - un verrou empêche deux exécutions en même temps ; un mode `--dry-run` dit ce qu'il ferait.
3. **Version servie** : une route ou un en-tête donne la version de l'API, et la page d'accueil celle du front, pour
   la vérification du critère 2 (à créer si rien n'existe).
4. **Alertes** : chaque passage qui fait quelque chose (déployé, refusé, échec et retour en arrière) envoie un message
   sur un webhook Discord (`DEPLOY_WEBHOOK_URL`) avec la version et la raison ; rien quand il n'y a rien à faire.
5. **Installation** : `deploy/README.md` (ou `docs/deploiement-continu.md`) avec le timer systemd (toutes les 15
   minutes, par exemple, de 9 h à 22 h), les droits nécessaires, l'emplacement des sauvegardes, et comment couper le
   déploiement automatique (`DEPLOY_PAUSED=1` dans le `.env`).
6. Tests du script : au minimum `shellcheck` en CI, et un essai `--dry-run` décrit pour l'opérateur.
7. Premier passage réel accompagné par l'opérateur.

## Tâches

- [ ] Marqueur et doc (critère 1).
- [ ] Version servie (critère 3).
- [ ] Script et alertes (critères 2 et 4).
- [ ] Doc d'installation (critère 5) et `shellcheck` (critère 6).
- [ ] Premier passage avec l'opérateur (critère 7).

## Décision ouverte

- Canal des alertes : webhook Discord proposé par défaut ; à confirmer par Jean (sinon mail).
