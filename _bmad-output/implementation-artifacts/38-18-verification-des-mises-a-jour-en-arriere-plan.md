# Story 38.18: Vérification des mises à jour en arrière-plan

**Status:** review
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

- [x] **Task 1** (AC 3) - `CheckApworldUpdatesService::checkAll()` : `flush()` par lots dans la boucle, en plus de
  celui de fin ; garder l'ordre et la sortie sur limite GitHub. Vérifier que `GameRepositoryInterface` expose ce
  qu'il faut (sinon méthode dédiée, pas d'`EntityManager` en Application).
- [x] **Task 2** (AC 1, 6) - Message de vérification à la demande (ou `CheckApworldUpdatesMessage` avec un drapeau
  `submit`, la nuit à `true`, le bouton à `false`) ; le handler ne soumet que pour la nuit. Routage `async` dans
  `messenger.yaml`.
- [x] **Task 3** (AC 2, 4) - État de la passe : stockage simple (cache applicatif ou table d'une ligne) écrit par
  le handler au début, à la fin et en cas d'échec ; verrou Symfony Lock autour de la passe. Requête de lecture
  (`*Query` dans `Application/Query/`).
- [x] **Task 4** (AC 1, 2, 4) - Contrôleur : `POST` met en file (202 / refus si déjà en cours), `GET` de l'état ;
  un seul appel Application par action (AC-P4).
- [x] **Task 5** (AC 5) - Front `admin-catalogue-sync-api.ts` (type guard de l'état) et
  `admin-catalogue-sync-page.tsx` (flash, état en cours, bouton désactivé, rafraîchissement, bilan).
- [x] **Task 6** (AC 7, 8) - Tests et gates.

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

### Implementation Notes

- **État en base, pas en cache.** `api-web` et le worker sont deux conteneurs : un cache applicatif (système de
  fichiers) ne serait pas partagé. Table `apworld_update_check_run` (migration `Version20261019100000`), une ligne
  par passe : statut, déclenchement, horaires, total, avancement, bilan, erreur.
- **Une seule passe active.** La colonne `active_slot` vaut `active` tant que la passe est en file ou en cours,
  `NULL` ensuite, sous contrainte unique : l'insertion (`INSERT ... ON CONFLICT DO NOTHING`, pas un flush ORM qui
  fermerait l'EntityManager du worker) échoue proprement si une autre passe est active. Le `POST` répond alors `409`
  (`already_running`) et la page suit la passe en cours via le `GET`.
- **Verrou** : le verrou consultatif Postgres existant (`ExclusivePassLockInterface`, story 38.1), non bloquant, autour
  de toute la passe, nuit comprise. Pas de dépendance `symfony/lock` ici.
- **Passe abandonnée.** Une passe en file depuis plus de 30 min, ou en cours sans progrès depuis 1 h, a perdu son
  worker : le clic suivant la clôt en échec avant d'en lancer une nouvelle.
- **Nuit et clic.** La nuit, sous verrou, reprend la main sur un clic resté en file (il est clos « remplacée par la
  vérification de nuit ») : elle vérifie tout ce que le clic aurait vérifié, et soumet. Le message du clic, arrivé
  ensuite, trouve sa passe close et ne fait rien.
- **Échec** : enregistré (`failed` + message), loggé, sans retry Messenger ; ce qui a été enregistré par lots reste.
- `checkAll(?Closure $onProgress)` : `flush()` tous les 25 jeux (`BATCH_SIZE`) ; le rappel reçoit le total puis le
  bilan à chaque lot, ce qui alimente « 120 / 556 jeux » sur la page.
- Front : la requête d'état se rafraîchit toutes les 4 s tant que la passe est active (`staleTime` 2 s) ; à la fin,
  la liste des mises à jour est rechargée une fois.

### File List

- `api/src/CatalogSync/Domain/Entity/ApworldUpdateCheckRun.php`,
  `api/src/CatalogSync/Domain/Repository/ApworldUpdateCheckRunRepositoryInterface.php`,
  `api/src/CatalogSync/Infrastructure/Doctrine/DoctrineApworldUpdateCheckRunRepository.php` (nouveaux)
- `api/src/CatalogSync/Application/Command/QueueApworldUpdateCheck.php`, `ApworldUpdateCheckQueued.php`,
  `api/src/CatalogSync/Application/Query/ApworldUpdateCheckStatusQuery.php`, `ApworldUpdateCheckRunView.php` (nouveaux)
- `api/src/CatalogSync/Application/Command/CheckApworldUpdatesService.php`,
  `api/src/CatalogSync/Application/Handler/CheckApworldUpdatesHandler.php`,
  `api/src/CatalogSync/Application/Message/CheckApworldUpdatesMessage.php`,
  `api/src/CatalogSync/Presentation/Controller/AdminCatalogSyncController.php`
- `api/migrations/Version20261019100000.php` (nouveau), `api/config/packages/doctrine.yaml`, `api/config/services.yaml`
- `api/tests/Functional/AdminCatalogSyncTest.php`, `api/tests/Unit/CatalogSync/CheckApworldUpdatesHandlerTest.php`,
  `CheckApworldUpdatesServiceTest.php`, `ApworldUpdateCheckPassTest.php`, `InMemoryApworldUpdateCheckRunRepository.php`,
  `FreePassLock.php` (trois nouveaux)
- `frontend/src/features/admin/apworld-update-check.ts`, `apworld-update-check.test.ts` (nouveaux),
  `admin-catalogue-sync-api.ts`, `admin-catalogue-sync-page.tsx`

### Déploiement

- Migration `Version20261019100000` ; redémarrer le worker (le message change de forme : un message de nuit déjà en
  file sous l'ancienne forme se lit encore, ses arguments ayant des valeurs par défaut).
