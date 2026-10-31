# Story 38.30 - Tester un méta-jeu avec un partenaire

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-31
**Origine :** Tombola, mise à jour 0.4 rejetée (`Arenes214/APWorld-Tombola`, hash `45e829a94dc2`), incident
« Remplissage impossible » du 2026-10-30 : `FillError: No more spots to place 90 items`. Remonté par Jean le
2026-10-31.
**Dépôts :** `orchestrateur` (Go), `archilan-orchestrateur-client` (PHP, nouvelle version), `archilan.fr` (api,
frontend). Aucun changement dans l'image `archipelago`.

## Constat

- Tombola est un méta-jeu : ses 90 numéros (la progression) ne peuvent pas être placés dans un monde Tombola (« Progression
  Items from AP Tombola slots will never be placed in an AP Tombola slot »). En solo, aucun emplacement n'est valide.
- Notre test de génération est toujours solo : l'orchestrateur écrit un seul `yamls/preflight.yaml` (le template du
  hash) et lance `generate_multiworld.py` (`orchestrateur/internal/docker/client.go`, `PreflightGenerate`). La 0.3 en
  ligne échoue pareil ; toute version de Tombola sera rejetée.
- Reproduit le 2026-10-31 : la 0.4 seule échoue ; avec A Short Hike (monde intégré à la release, sans ROM, options
  par défaut), 3 seeds sur 3 sont générées.

## Décisions

- Un réglage admin par jeu, **« Tester avec un partenaire »**, désactivé par défaut. Activé, chaque test de génération
  de ce jeu (version en ligne et nouvelles versions) ajoute un second joueur : **A Short Hike**, options par défaut.
  Un partenaire fixe suffit au besoin constaté ; pas de choix du partenaire tant qu'un jeu ne le réclame pas.
- Le partenaire est un monde intégré à la release : rien à transmettre de plus que son YAML, aucune ROM.
- Le réglage vit sur `Game` (api). L'API le transmet à l'orchestrateur à chaque test qu'elle déclenche, **y compris au
  téléversement** d'un apworld, puisque le premier test d'un candidat part de l'orchestrateur à l'upload (story 38.28).
- L'orchestrateur ne stocke rien : il écrit un second fichier `yamls/partner.yaml` quand on le lui demande.
- Le verdict dit qu'il a été obtenu avec un partenaire, comme il dit déjà « avec la ROM » (44.6) : « Test de génération
  réussi avec un partenaire (A Short Hike) ».
- **Hors périmètre :** les jeux dont le template ne peut pas générer par conception (Minecraft Modded, `checks` vides ;
  Friday Night Funkin', template vide). Ils demandent un YAML d'exemple stocké sur la fiche, autre story.

## Critères d'acceptation

1. `Game` porte `testWithPartner` (booléen, défaut faux, migration réversible) ; l'admin le bascule depuis l'onglet
   APWorld, avec une phrase qui explique le cas (méta-jeux comme Tombola).
2. Orchestrateur : `POST /apworlds/{hash}/preflight` et l'upload d'un apworld acceptent `withPartner` (booléen,
   absent = faux). Vrai : le conteneur de test reçoit aussi `yamls/partner.yaml` (`name: Partner`, `game: A Short
   Hike`, options par défaut). Faux : comportement inchangé, un seul YAML.
3. Le verdict enregistré porte `withPartner` ; l'API l'expose et l'admin affiche « réussi avec un partenaire ».
4. L'API passe le réglage du jeu à tous les tests qu'elle lance : relance de la version en ligne, relance d'un candidat
   (38.28), import GitHub et téléversement d'un fichier, mise à jour automatique (sweep), backfill et réconciliation
   des incidents.
5. Client PHP : `runPreflight` et l'upload acceptent `withPartner` ; nouvelle version publiée (bump de `version` dans
   `composer.json` avant le tag), `composer.lock` de l'api mis à jour.
6. Tombola en local : réglage activé, la 0.4 passe son test et la mise à jour est appliquée ; réglage désactivé, elle
   est rejetée comme avant.
7. Tests : Go (`PreflightGenerate` avec et sans partenaire, handler), PHP unitaires et fonctionnels, jest ; gates verts
   des trois dépôts.

## Tâches

- [x] Orchestrateur : champ `withPartner` (preflight + upload), second YAML dans le tar, `withPartner` dans les
      métadonnées du verdict, tests.
- [ ] Client PHP : paramètre `withPartner`, tests, release. (code et tests faits, tag v1.17.0 en attente de l'accord de Jean)
- [x] API : `Game::testWithPartner` + migration, endpoint admin de bascule, propagation aux appelants de
      `runApworldPreflight` et de l'upload, verdict `withPartner`, tests.
- [x] Front : bascule dans l'onglet APWorld, libellé du verdict, tests.
- [x] Vérification Tombola en local (au niveau du conteneur, voir Dev Agent Record).

## Mise en prod

1. Déployer l'orchestrateur avant l'API (un champ inconnu côté orchestrateur serait ignoré, mais le test resterait
   solo).
2. Tombola : activer « Tester avec un partenaire », relancer le test du candidat 0.4, puis résoudre l'incident.

## Dev Agent Record

- Orchestrateur (`feature/story-38-30-test-avec-un-partenaire`) : `preflightTar` ajoute `yamls/partner.yaml`
  quand le partenaire est demandé ; `withPartner` sur `POST /apworlds/{hash}/preflight` (JSON) et sur l'upload
  (champ de formulaire `withPartner=1`) ; le verdict porte `withPartner` (remis à faux en pending et sur skipped).
- Client (`feature/story-38-30-partenaire`, version 1.17.0 dans `composer.json`, pas encore taggée) :
  `upload(..., bool $withPartner)`, `runPreflight(..., bool $withPartner)`, `ApworldPreflight::$withPartner`.
- API : `Game::testWithPartner` (migration `Version20261031120000`), `PATCH
  /api/v1/admin/games/{gameId}/apworld-test-partner` `{withPartner: bool}`, `testWithPartner` dans la fiche admin,
  `withPartner` dans le verdict. Le réglage suit tous les tests : relance de la version en ligne et après
  modification ou régénération du template, relance d'un candidat, upload (import GitHub, fichier, mise à jour
  automatique via `SubmitApworldCandidate`), sweep, backfill (via `ServedApworldsQueryInterface`) et
  réconciliation. Changer le réglage ne relance pas le test : l'admin le relance.
- Front : case « Tester avec un partenaire (A Short Hike) » dans la carte « Version en ligne », libellé « Test de
  génération réussi avec un partenaire (A Short Hike) ».
- Vérification Tombola 0.4 : même arborescence que le conteneur de test (`worlds/`, `yamls/preflight.yaml`,
  `yamls/partner.yaml` avec exactement `PartnerYAML`), image `archipelago:latest` : 2 générations sur 2 réussies ;
  sans le partenaire, `FillError: No more spots to place 90 items` comme en prod. Le parcours complet
  (orchestrateur local + API) reste à faire après le tag du client.
