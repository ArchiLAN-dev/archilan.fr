# Story 38.18: Vérification des mises à jour en arrière-plan

**Status:** ready-for-dev
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-18
**Origine :** signalement de Jean le 2026-10-18 - le bouton « Vérifier les mises à jour » de la page catalogue
semble planté sur une ligne et ne traite jamais les suivantes.

## Story

En tant qu'admin d'ArchiLAN,
je veux que le bouton « Vérifier les mises à jour » vérifie vraiment tous les apworlds suivis,
afin de voir les nouvelles versions sans attendre la passe de nuit, et sans qu'un arrêt en cours de route fasse
recommencer la même vérification au clic suivant.

## Contexte

`POST /api/v1/admin/catalog-sync/check-updates` (`AdminCatalogSyncController::checkUpdates`) appelle
`CheckApworldUpdatesService::checkAll()` **dans la requête HTTP**. Le service interroge l'API GitHub des releases
pour chaque jeu suivi sur GitHub (556 en base locale le 2026-10-18), au moins une requête par jeu : plusieurs
minutes au total.

Trois défauts se cumulent :

1. **Délai de 30 s.** `api-web` tourne sous FrankenPHP (`api/Dockerfile.web`) sans réglage de
   `max_execution_time` : PHP coupe la requête à 30 s. En ZTS sous Linux (FrankenPHP), ce délai court en temps réel,
   attente réseau comprise.
2. **Un seul `flush()`, en fin de boucle** (`CheckApworldUpdatesService.php`). Une requête coupée perd tout : les
   versions relevées et la date de dernière vérification (`markApworldChecked`) de chaque jeu déjà traité.
3. **File « moins récemment vérifié d'abord »** (story 38.5). Rien n'ayant été enregistré, le clic suivant repart
   des mêmes jeux, dans le même ordre, et s'arrête au même endroit : d'où l'impression d'un blocage sur une ligne.

La passe de nuit (`CheckApworldUpdatesHandler`, messenger `async`, 04:00) appelle le même service depuis le worker,
qui n'a pas cette limite ; elle souffre seulement du défaut 2 si elle s'interrompt (redémarrage du worker,
exception non HTTP).

Règle à conserver (docblock de `CheckApworldUpdatesHandler`) : **seule la passe de nuit soumet** des candidats ;
le bouton ne fait que vérifier. La soumission à la demande reste `app:check-apworld-updates --submit` (story 38.11).

## Critères d'acceptation

1. **Le bouton met la vérification en file.** `POST /api/v1/admin/catalog-sync/check-updates` envoie un message
   asynchrone au worker et répond `202` immédiatement, sans attendre la vérification. Ce message **vérifie sans
   soumettre** (aucun appel à `SubmitAvailableApworldUpdates`).
2. **Pas de double passe.** Si une vérification est déjà en file ou en cours (bouton ou nuit), le `POST` n'en
   ajoute pas une seconde et le dit (`409`, ou `202` avec `alreadyRunning: true`, au choix de l'implémentation,
   cohérent avec le front). Deux passes ne tournent jamais en même temps dans le worker (verrou), la nuit comprise.
3. **Avancement enregistré en continu.** `checkAll()` enregistre en base au fil de la boucle (par lots, par exemple
   tous les 25 jeux) et non plus seulement à la fin. Une passe interrompue garde le travail déjà fait, et la
   suivante reprend aux jeux non atteints.
4. **État de la dernière passe consultable.** Un `GET` admin (ou un champ du statut de synchro existant) donne
   l'état de la dernière vérification : `queued`, `running` ou `done` (`failed` si elle a planté), son
   déclenchement (bouton ou nuit), son début, sa fin, et son bilan : jeux vérifiés, en échec, limite GitHub
   atteinte, mises à jour disponibles.
5. **Front.** Au clic, la page indique que la vérification est lancée en arrière-plan, affiche « en cours » tant
   qu'elle tourne (rafraîchissement périodique, `staleTime` explicite), puis le bilan et la liste des mises à jour
   à jour. Le bouton est désactivé pendant une passe en cours. Recharger la page pendant la passe retrouve l'état
   « en cours ».
6. **Passe de nuit inchangée** dans son résultat : elle vérifie puis soumet, dans le plafond
   `APWORLD_AUTO_UPDATE_BATCH_SIZE`, et met à jour le même état (AC 4).
7. **Tests** : fonctionnel sur le `POST` (202, message en file, rien de vérifié dans la requête, seconde demande
   refusée pendant une passe) ; unitaire sur `checkAll()` (enregistrement par lots, reprise après interruption,
   pas de soumission depuis le message du bouton) ; front sur les états lancé, en cours, terminé, échec.
8. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Task 1** (AC 3) - `CheckApworldUpdatesService::checkAll()` : `flush()` par lots dans la boucle, en plus de
  celui de fin ; garder l'ordre et la sortie sur limite GitHub. Vérifier que `GameRepositoryInterface` expose ce
  qu'il faut (sinon méthode dédiée, pas d'`EntityManager` en Application).
- [ ] **Task 2** (AC 1, 6) - Message de vérification à la demande (ou `CheckApworldUpdatesMessage` avec un drapeau
  `submit`, la nuit à `true`, le bouton à `false`) ; le handler ne soumet que pour la nuit. Routage `async` dans
  `messenger.yaml`.
- [ ] **Task 3** (AC 2, 4) - État de la passe : stockage simple (cache applicatif ou table d'une ligne) écrit par
  le handler au début, à la fin et en cas d'échec ; verrou Symfony Lock autour de la passe. Requête de lecture
  (`*Query` dans `Application/Query/`).
- [ ] **Task 4** (AC 1, 2, 4) - Contrôleur : `POST` met en file (202 / refus si déjà en cours), `GET` de l'état ;
  un seul appel Application par action (AC-P4).
- [ ] **Task 5** (AC 5) - Front `admin-catalogue-sync-api.ts` (type guard de l'état) et
  `admin-catalogue-sync-page.tsx` (flash, état en cours, bouton désactivé, rafraîchissement, bilan).
- [ ] **Task 6** (AC 7, 8) - Tests et gates.

## Dev Notes

- Ne pas monter `max_execution_time` d'`api-web` comme correctif : une requête de plusieurs minutes reste fragile
  (proxy, onglet fermé, double clic) et occupe un thread FrankenPHP.
- `markApworldChecked` est posé avant l'appel GitHub, exprès (story 38.5 : un dépôt toujours en échec ne doit pas
  bloquer la tête de file) ; l'enregistrement par lots doit garder ce comportement.
- La commande `app:check-apworld-updates` (CLI, sans limite de temps) reste synchrone ; elle profite seule de
  l'AC 3.
- Pour confirmer le diagnostic en prod avant livraison : `Maximum execution time of 30 seconds exceeded` dans les
  logs d'`api-web` après un clic, et le `POST` en 500 au bout d'environ 30 s.

## Dev Agent Record

_(à remplir à l'implémentation)_
