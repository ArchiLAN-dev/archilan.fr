# Story 9.53: Ré-introspecter un apworld sans le ré-uploader

**Status:** implementee - 3 couches livrees, `v1.9.0` du client publiee
**Epic:** 9 - Multiworld generation pipeline & apworld introspection
**Date:** 2026-08-28
**Vient de :** [9.51](9-51-dict-sub-option-values-from-schema.md) - livree en `v0.18.0`, et invisible
en jeu tant que les apworlds ne repassent pas par l'introspection.

## Story

En tant qu'**admin qui vient de deployer une nouvelle version de l'introspection**,
je veux **relancer l'introspection sur les apworlds deja en stockage avec une commande**,
afin de **ne pas re-televerser a la main un fichier que le serveur possede deja**.

## Context

L'introspection d'un apworld ne tourne **qu'une fois** : dans le `go func()` de
`Service.UploadApworld`, qui ecrit un sidecar JSON a cote de l'archive. Rien ne le regenere ensuite.
`RegenerateApworldTemplate` (story 9.46) reconstruit le gabarit YAML, pas le sidecar, et
`app:games:backfill-option-types` ne fait que **relire** ce sidecar depuis le runner.

Constat fait en livrant la 9.51 : le champ `keys` n'existe que pour les apworlds televerses **apres**
le deploiement de la nouvelle image archipelago. Pour tous les autres, la seule manoeuvre disponible
etait de retrouver le `.apworld` d'origine et de le re-televerser depuis l'admin - alors que le
serveur l'a deja en stockage, a l'octet pres, indexe par son hash.

### Le fil etait tire, il n'etait pas branche

`RegenerateApworldTemplate` fait exactement le geste voulu, pour l'autre artefact :

```go
data, err := s.storage.DownloadApworld(ctx, hash)
template, err := s.docker.GenerateTemplate(ctx, data, hash)
s.storage.UploadApworldTemplate(ctx, hash, template)
```

`Client.IntrospectOptions(ctx, apworldData, hash)` et `Storage.UploadApworldOptionTypes(ctx, hash,
json)` existaient deja et prenaient exactement ces arguments.

## Acceptance Criteria

**AC1 - Re-introspection depuis le stockage.** L'orchestrateur expose une operation qui, pour un
hash d'apworld deja stocke, relance l'introspection sur l'archive en stockage et remplace le sidecar
de types. Aucun octet d'apworld ne transite depuis l'appelant.

**AC2 - Un echec n'efface rien.** Si l'introspection echoue, le sidecar existant reste en place et
l'erreur remonte. Un sidecar perime vaut mieux qu'un sidecar absent : sans lui l'editeur perd aussi
les bornes de range (9.25), les types (9.33) et les locations (4.14), pas seulement le `keys` de la
9.51. Meme discipline que `RegenerateApworldTemplate`.

**AC3 - Une commande, pas deux.** `app:games:backfill-option-types` prend une option
`--reintrospect` : avec, elle relance l'introspection **puis** relit les types ; sans, elle garde
exactement son comportement actuel.

**AC4 - Non par defaut, et ciblable.** Chaque re-introspection lance un conteneur qui charge tout
Archipelago. `--reintrospect` est donc explicite, et `--game=<slug>` limite le balayage a un jeu. La
commande compte les succes et les echecs.

**AC5 - Un echec ne fait pas tomber le lot.** Un apworld qui echoue est compte et le balayage
continue. La commande ne sort en erreur que si **aucun** apworld n'a pu etre introspecte.

**AC6 - Gates.** `composer gates` cote api, gates propres de l'orchestrateur et du client.

## Tasks / Subtasks

- [x] **Task 1** (AC1, AC2). Orchestrateur : `Service.ReintrospectApworldOptions()` + route
      `POST /apworlds/{hash}/introspect` + handler (503 stockage absent, 422 introspection ratee).
- [x] **Task 2** (AC1). Client : `ApworldsClient::reintrospect()` + tests, **tag `v1.9.0`**.
- [x] **Task 3** (AC1, AC2). API : `RunnerGatewayInterface::reintrospectApworld(): bool`, qui
      journalise et renvoie false plutot que de laisser filer l'exception.
- [x] **Task 4** (AC3, AC4, AC5). API : options `--reintrospect` et `--game`, rapport dedie.
- [x] **Task 5** (AC6). Gates verts sur les trois depots.

## Dev Notes

- **Pas de `go func()` ici.** A l'upload, l'introspection part en tache de fond parce qu'un admin
  attend une reponse HTTP. L'appelant est ici une commande CLI : elle doit **attendre**, sinon elle
  ne peut ni compter les echecs ni relire des types a jour juste apres.
- **L'ordre dans la commande est tout le sujet** : re-introspecter *puis* `fetchOptionTypes`.
  L'inverse relit le sidecar d'avant et ne change rien - c'est exactement le piege que l'option
  existe pour fermer. C'est ce que verifie le test sur l'ordre des appels.
- **Le sidecar porte aussi les locations** : le regenerer les rafraichit au passage.

### Project Structure Notes

- `orchestrateur/internal/service/apworld_template.go`, `internal/api/router.go` + `handlers.go` + `types.go`.
- `orchestrateur-client/src/Apworlds/ApworldsClient.php`.
- `api/src/Sessions/Application/Port/RunnerGatewayInterface.php`,
  `api/src/Sessions/Infrastructure/Http/RunnerGateway.php`,
  `api/src/Sessions/Infrastructure/Double/NullRunnerGateway.php`.
- `api/src/GameSelection/Application/Command/BackfillGameOptionTypes.php` +
  `OptionTypesBackfillReport.php`, `api/src/GameSelection/Presentation/Command/BackfillGameOptionTypesCommand.php`.

### References

- [Source: _bmad-output/implementation-artifacts/9-51-dict-sub-option-values-from-schema.md (« Ce qui reste »)]
- [Source: _bmad-output/implementation-artifacts/9-46-regenerate-template-from-apworld.md (le geste equivalent pour le gabarit)]

## Dev Agent Record

### Ecart assume : un rapport dedie plutot qu'un rapport partage

`GameBackfillReport` est partage par les backfills plateformes et steam-app-id. Y ajouter les deux
compteurs de re-introspection leur aurait donne une paire de zeros qui ne veut rien dire. D'ou
`OptionTypesBackfillReport`, propre a ce balayage - le seul qui puisse re-introspecter.

### Ce que la commande ne fait pas

Elle ne re-genere pas le gabarit YAML. C'est `regenerateApworldTemplate` (9.46), et les deux gestes
sont independants : un gabarit peut etre bon avec une introspection perimee, et l'inverse.

## Ce qui reste

**Rien cote code.** Reste le deploiement : les images orchestrateur et api doivent partir avant que
la commande serve a quelque chose, l'endpoint n'existant pas dans les versions precedentes.

## Change Log

| Date       | Change |
|------------|--------|
| 2026-08-28 | Creee et implementee a la livraison de la 9.51, dont l'exploitation a montre qu'un re-upload manuel etait le seul chemin pour rafraichir l'introspection. |
