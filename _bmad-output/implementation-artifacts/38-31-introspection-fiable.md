# Story 38.31 - Une introspection fiable, et un world sans lieu qu'on peut mettre en ligne

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-31
**Origine :** deux candidats impossibles à forcer le 2026-10-31, tous deux avec un 503 « Le runner est indisponible »
immédiat, alors que le runner répondait :
- Chrono Trigger Rando Dalton Imperial SNES v0.0.4 (`ctrdi`) ;
- Minecraft Modded v3.0.10.

**Dépôts :** `archipelago` (script d'introspection), `orchestrateur` (stockage et lecture de l'introspection),
`archilan.fr` (api).

## Constat

Forcer (et promouvoir) un candidat lit d'abord son introspection : les types d'options et les noms de lieux, que
l'orchestrateur relit dans `<hash>.types.json` (MinIO) sans relancer de conteneur. Une liste de lieux vide fait
répondre `RunnerUnavailable`, donc 503 « runner indisponible ».

1. **Chrono Trigger** : l'apworld écrit `C compression module not found.  Falling back to python.` sur la sortie
   standard à l'import. `introspect_options.py` imprime son JSON sur la même sortie, et l'orchestrateur stocke
   tout tel quel. `json.Unmarshal` échoue à la lecture, et les lieux reviennent vides (200 `{"locations":[]}`).
   Réimporter réécrit le même fichier.
2. **Minecraft Modded** : l'introspection est propre, mais le world crée ses lieux et ses objets à partir de
   l'option `checks` du joueur. Il n'en déclare aucun statiquement (0 lieu, 0 objet, 18 options). L'API refuse
   toute version sans lieu, forçage compris : le jeu ne peut plus jamais être mis à jour.
3. L'orchestrateur renvoie la même liste vide pour « introspection absente ou illisible » et pour « world sans lieu »,
   et l'API ne peut pas les distinguer.

## Décisions

- Archipelago : pendant le chargement des worlds et l'introspection, la sortie standard est redirigée vers la sortie
  d'erreur. Seul le JSON final sort sur la vraie sortie standard.
- Orchestrateur, en défense (anciennes images, apworlds qui écrivent hors de la redirection) : avant de stocker la
  sortie de l'introspection, il garde la dernière ligne qui est un objet JSON valide. Une sortie sans JSON valide
  est une erreur d'introspection, pas un fichier stocké.
- Orchestrateur : `GET /apworlds/{hash}/locations` renvoie 404 quand l'introspection n'existe pas et 500 quand elle
  est illisible. Une liste vide en 200 veut dire « aucun lieu déclaré ».
- API : `fetchLocationNames` renvoie `null` quand le runner ne répond pas ou que l'introspection manque. La
  promotion accepte une liste vide et n'exige que des options. Les autres appelants gardent leur comportement
  (`null` vaut liste vide).
- Le message du 503 dit les deux causes possibles et quoi faire (relancer l'introspection).
- Hors périmètre : un YAML de test dédié par jeu (Minecraft Modded échoue toujours au test solo, il faudra le
  forcer). Story à part si Jean la veut.

## Critères d'acceptation

1. `introspect_options.py` : ce qu'un apworld écrit sur la sortie standard, à l'import ou pendant l'introspection,
   part sur la sortie d'erreur. La sortie standard ne contient que le JSON. Testé avec un faux world bavard.
2. Orchestrateur : la sortie stockée est le dernier objet JSON valide de la sortie standard. Une sortie sans JSON
   valide fait échouer l'introspection, et rien n'est stocké. Testé (préfixe, sortie propre, sortie sans JSON).
3. Orchestrateur : `/locations` répond 404 sans introspection, 500 si elle est illisible, 200 avec la liste
   (éventuellement vide) sinon. Testé.
4. API : forcer ou promouvoir un candidat dont l'introspection a répondu sans aucun lieu met la version en ligne. Si
   l'introspection ne répond pas ou est illisible, le 503 reste, avec un message qui le dit. Tests unitaires et
   fonctionnels.
5. Chrono Trigger v0.0.4 et Minecraft Modded v3.0.10 en local : l'introspection stocke un JSON propre (365 lieux
   pour Chrono Trigger), et le forçage passe pour les deux.
6. Gates verts dans les trois dépôts.

## Tâches

- [x] Archipelago : redirection de la sortie standard dans `introspect_options.py`, test.
- [x] Orchestrateur : extraction du JSON avant stockage, statuts de `/locations`, tests, swagger.
- [x] API : `fetchLocationNames` nullable, promotion sans lieu, message du 503, tests.
- [x] Vérification locale Chrono Trigger et Minecraft Modded.

## Mise en prod

1. Image archipelago, puis orchestrateur, puis site.
2. Chrono Trigger : relancer l'introspection du candidat v0.0.4 (ou le réimporter), puis forcer.
3. Minecraft Modded : forcer le candidat (le test solo échoue toujours, par conception).

## Dev Agent Record

- Archipelago : branche `fix/story-38-31-introspection-sortie-propre` (07cfe50), `sys.stdout` redirige vers stderr avant tout import de world, le JSON seul part sur la vraie sortie ; 240 tests verts.
- Orchestrateur : branche `fix/story-38-31-introspection-fiable` (e6b29f2), `introspectionJSON` garde la derniere ligne JSON valide, `GET /apworlds/{hash}/locations` repond 404 / 500 / 503 / 200 ; tests docker, service et handler ; swagger regenere ; `go test ./...` vert.
- API : `RunnerGatewayInterface::fetchLocationNames` renvoie null quand l'orchestrateur ne repond pas ; `PromoteApworldCandidate::introspect` accepte une liste vide ; message du 503 de forcage ; tests de triage ; `composer gates` vert (3385 tests).
- Verification locale (orchestrateur reconstruit, image archipelago corrigee) : Chrono Trigger v0.0.4 = 365 lieux, Minecraft Modded v3.0.10 = 200 avec 0 lieu, hash inconnu = 404.
