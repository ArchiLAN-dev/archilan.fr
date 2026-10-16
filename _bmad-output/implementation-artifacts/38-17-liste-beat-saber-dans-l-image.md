# Story 38.17 - La liste de chansons de Beat Saber dans l'image

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-16
**Origine :** échange avec Jean le 2026-10-16 - Beat Saber échoue au test de génération sur
`FileNotFoundError: /Archipelago/data/ranked_maps.json`, et ne peut donc pas être ajouté à une partie.
**Dépôts :** `archipelago` (ArchiLAN-dev/archilan-archipelago#38), `archilan.fr` (libellé, cette story). Branche
principale empilée sur la 44.7.

## Constat

L'apworld Beat Saber (CurrentlyPending/BeatSaberAP 0.1.1, `8e0d2317…`) tire les chansons d'une playlist aléatoire
(modes `randomizedPP`, `randomizedAccuracy` - le défaut -, `randomizedPass`) dans `ranked_maps.json` : les cartes
classées BeatLeader depuis 2020. Il la télécharge à sa première génération (`api.beatleader.com/maps`, pages de 100)
puis l'écrit dans `<racine>/data`, racine qu'il déduit en remontant trois dossiers depuis son fichier ; sous `/tmp`,
cela donne `/Archipelago/data`, absent de notre image. Il ne la rafraîchit jamais ensuite. Les modes `preset*`
n'en ont pas besoin.

Ce n'est donc pas un fichier à fournir : c'est un cache que l'apworld fabrique au mauvais endroit, et qu'un
conteneur de génération scellé (sans réseau) ne peut pas télécharger.

## Décisions (Jean, 2026-10-16)

1. La liste est versionnée dans le dépôt de l'image et copiée à `/Archipelago/data/ranked_maps.json` : le build ne
   dépend pas de BeatLeader, un même commit donne la même image, et la liste ne change qu'avec une nouvelle image
   (comme le reste d'Archipelago, ce que le suivi exact sait déjà gérer).
2. Pas de téléchargement à chaque génération : réseau coupé par principe, régénération du suivi exact qui doit
   retrouver les mêmes chansons, lenteur et dépendance à BeatLeader.
3. Rafraîchissement : un script qui reconstruit la liste comme l'apworld (mêmes champs, même ordre - il tire avec
   `random.choice`) et une PR mensuelle qui résume ajouts et retraits. Un rebuild sans PR mergée ne change pas la liste.
4. Le libellé « Fichier manquant » de la 38.16 devient neutre : une erreur ne dit pas si le fichier est à fournir ou
   si l'apworld suppose un chemin.

## Critères d'acceptation

1. Image : liste copiée, garde de build (liste non vide), génération Beat Saber aléatoire réussie sans réseau.
2. Script de rafraîchissement + tests (champs, ordre, résumé) + PR mensuelle.
3. Libellé neutre de « Fichier manquant » (joueur et admin).

## Tâches

- [x] `archipelago` : liste, Dockerfile, garde, script, tests, workflow mensuel (#38).
- [x] `archilan.fr` : libellé neutre.
- [x] Vérification réelle.

## Dev Agent Record

### Completion Notes

- Liste : 1507 entrées (dont 100 empreintes en double, renvoyées telles quelles par BeatLeader et gardées comme le
  fait l'apworld), 806 Ko.
- Vérifié : image reconstruite (garde passée) ; génération avec le template par défaut de Beat Saber
  (`randomizedAccuracy`) dans un conteneur `--network none` → sortie 0, playlist et archive produites, aucun
  téléchargement. `pytest` du dépôt `archipelago` : 177 verts. Jest front vert.
- Isolation réseau vérifiée par l'API Docker : `NetworkDisabled: true` (tests) et `NetworkMode: none` (parties)
  coupent le réseau (échec DNS).

### Point ouvert

- L'incident Beat Saber du 15/10 (test tournant) montre un téléchargement BeatLeader réussi, donc un conteneur de test
  qui avait le réseau. Non reproduit aujourd'hui ; l'orchestrateur de l'époque a été recréé, ses logs sont perdus.
  À surveiller : un autre incident de ce genre désignerait le chemin en cause.

### Mise en service

- Autoriser GitHub Actions à créer des PR dans les réglages du dépôt `archilan-archipelago`.
- Après publication de l'image : relancer le test de génération de Beat Saber depuis sa fiche ; l'incident se ferme
  quand il repasse au vert.

### File List

- `archipelago` : `Dockerfile`, `data/beat_saber/ranked_maps.json`, `scripts/refresh_beat_saber_maps.py`,
  `tests/test_beat_saber_maps.py`, `.github/workflows/refresh-beat-saber-maps.yml`
- `archilan.fr` : `frontend/src/features/games/generation-failure.ts`

## Change Log

- 2026-10-16 : liste embarquée dans l'image, rafraîchissement mensuel, libellé neutre.
