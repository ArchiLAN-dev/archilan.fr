# Story 16.16: Lien public de téléchargement des fichiers de sortie

**Status:** implémentée - PR vers `develop`
**Epic:** 16 - Personal runs (parties privées créées par un membre)
**Date:** 2026-08-24
**Stories liées :** [16.7](16-7-personal-run-patch-download.md) et
[16.9](16-9-participant-patch-download-from-minio.md) - le téléchargement des patchs, qu'elles ont
volontairement gardé authentifié et limité au slot de l'appelant

## Story

En tant que joueur d'une partie ArchiLAN,
je veux copier le lien de mon fichier de sortie et l'envoyer à quelqu'un,
afin qu'il puisse le télécharger sans avoir de compte ni faire partie de la partie.

## Context

Le panneau « Fichiers générés » propose aujourd'hui un `<button>`, pas un lien :

```ts
const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/patches/${encodeURIComponent(filename)}`);
const blob = await res.blob();
const objectUrl = URL.createObjectURL(blob);
```

Le fichier transite en `blob:` vers une ancre jetable. Un clic droit ne donne donc **rien** de
copiable, et l'URL sous-jacente est doublement gardée : cookie de session, puis vérification que le
fichier appartient bien à un slot de l'appelant (`belongsToOwnSlot`).

Trois surfaces portent le même motif, chacune avec son contrôleur :

| surface | route authentifiée |
|---|---|
| partie privée | `GET /api/v1/runs/{runId}/patches/{filename}` |
| événement | `GET /api/v1/registrations/{registrationId}/patches/{filename}` |
| run hebdo | `GET /api/v1/weekly-runs/{weeklyRunId}/entries/{entryId}/patches/{filename}` |

Le fichier n'est pas un objet MinIO isolé : il est extrait à la volée d'une archive zip
(`extractEntry($outputKey, $filename)`). Une URL présignée MinIO supposerait de re-découper toutes
les archives, ce que cette story ne fait pas.

### Ce que le lien public change, et ce qu'il ne change pas

Aujourd'hui un joueur ne peut télécharger que **son** patch. Un lien signé transfère ce droit à
quiconque le détient - c'est exactement l'objet de la demande. Le fichier ne donne pas accès au
serveur Archipelago : il faut toujours l'adresse et le mot de passe de connexion, qui restent
derrière l'authentification.

### Décisions de cadrage (Jean, 2026-08-24)

| Question | Décision |
|---|---|
| Durée de vie | **Permanente.** Le destinataire doit pouvoir re-télécharger des semaines plus tard sans redemander un lien ; les runs dorment souvent plus de dix jours. Contrepartie assumée : un lien qui fuite reste valable et rien ne permet de le couper. |
| Exposition | **Le bouton devient une ancre** portant l'URL signée. Le clic droit natif suffit, et la bidouille blob disparaît. Un seul chemin de téléchargement, pour le joueur comme pour son destinataire. |
| Portée | Les **trois** surfaces : parties privées, événements, runs hebdo. |
| Mécanisme | `Symfony\Component\HttpFoundation\UriSigner`, déjà enregistré (`uri_signer`). Pas de HMAC maison, pas de table. |

## Acceptance Criteria

### Signature

1. Les URL publiques sont signées par `UriSigner` (secret du framework). Aucun HMAC réécrit à la
   main : le composant fait déjà la comparaison à temps constant, et trois implémentations maison
   valaient trois occasions de se tromper.
2. La signature couvre **l'URL complète**, chemin et paramètres. Un lien vaut pour le fichier qu'il
   nomme et pour lui seul : modifier le nom de fichier, l'identifiant de run ou d'inscription
   invalide la signature.
3. Sans signature valide, la route publique répond `403`, quel que soit le fichier demandé et qu'il
   existe ou non. Aucune énumération possible.
4. Aucune expiration : la signature est émise sans `expiration`. C'est une décision de cadrage, pas
   un oubli, et le code doit le dire.

### Routes publiques

5. Une route publique par surface, miroir de la route authentifiée, sans appel à
   `requireAuthenticatedUser`. L'authentification est appliquée par contrôleur dans ce projet
   (`security.yaml` n'a aucun `access_control`), donc l'omettre suffit et rien d'autre n'est à
   déclarer.
6. La route publique ne rejoue **pas** la garde « ce fichier appartient à ton slot » : elle n'a pas
   d'appelant identifié, et c'est la signature qui porte l'autorisation. Elle continue en revanche
   de répondre `404` pour un fichier absent de l'archive.
7. Les routes authentifiées existantes ne changent pas de comportement. Une partie de l'écosystème
   peut encore les appeler, et elles restent la voie normale pour le joueur connecté.

### Interface

8. Le téléchargement se fait par un `<a href>` portant l'URL signée, avec l'attribut `download`. Le
   clic droit du navigateur propose « Copier l'adresse du lien » sans code de notre part.
9. La bidouille blob (`URL.createObjectURL` + ancre jetable) disparaît des trois surfaces.
10. La liste des fichiers proposés ne change pas : chacun ne voit toujours que les fichiers de ses
    propres slots. Seuls des liens vers des fichiers déjà téléchargeables par l'appelant sont
    fabriqués.
11. Le nom du fichier reste lisible dans l'URL, pour qu'un lien collé dans Discord dise ce qu'il
    est.

### Tests

12. Unitaires : une URL signée est acceptée, une URL dont le nom de fichier a été modifié est
    refusée, une URL sans signature est refusée.
13. Fonctionnels, sur les trois surfaces : téléchargement public avec une signature valide sans
    cookie de session, `403` sans signature, `403` avec une signature d'un autre fichier, `404`
    pour un fichier absent de l'archive.
14. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-4). Support de signature partagé au-dessus de `UriSigner`, avec ses tests
  unitaires.
- [x] **Task 2** (AC 5-7). Les trois routes publiques, et l'URL signée ajoutée aux charges utiles
  des trois endpoints de liste.
- [x] **Task 3** (AC 8-11). Ancres à la place des boutons, suppression du chemin blob.
- [x] **Task 4** (AC 12-13). Tests unitaires et fonctionnels.
- [x] **Task 5** (AC 14). Gates des deux côtés.

## Dev Notes

- **`UriSigner` est déjà là**, exposé comme `uri_signer` et aliasé sur
  `Symfony\Component\HttpFoundation\UriSigner` : autowirable tel quel. `sign()` pour émettre,
  `checkRequest()` pour vérifier.
- **Où fabriquer l'URL.** Le plus simple est de la calculer dans les endpoints de **liste** de
  patchs, qui savent déjà quels fichiers appartiennent à l'appelant, et de la renvoyer à côté du nom
  de fichier. Le front n'a alors rien à signer ni à deviner.
- **Ne pas dupliquer `belongsToOwnSlot`.** Cette garde protège la route authentifiée et sert à
  décider quels liens émettre ; la route publique ne s'en sert pas.
- **Le chemin d'extraction ne bouge pas.** `extractEntry($outputKey, $filename)` reste la seule
  façon de sortir un fichier de l'archive, et le `basename()` sur le nom renvoyé reste nécessaire.
- **Ce que la story accepte sciemment :** un lien qui fuite est définitif. Aucun bouton ne le
  révoque, et le seul recours serait une rotation du secret applicatif, qui invaliderait tous les
  liens de tout le monde. À réévaluer si un incident se présente ; ce n'est pas un oubli.
- **Ce que la story ne fait pas :** re-découper les archives en objets MinIO individuels, exposer un
  lien vers le spoiler, ou permettre à un tiers de lister les fichiers d'une partie.

## Écarts assumés

### Une seule route publique, pas trois (AC 5)

L'AC demandait une route publique par surface, miroir de chaque route authentifiée. À
l'implémentation, chacune aurait dû résoudre l'emplacement du fichier **sans appelant**, ce que
trois nouvelles requêtes sans garde auraient permis - trois occasions d'ouvrir plus que prévu, pour
un résultat identique.

L'emplacement voyage donc dans la **query signée** (`?archive=…` pour l'archive durable,
`?workspace=…` pour le dossier de session), et une seule route les sert. La signature couvre la
query, donc l'emplacement n'est pas plus falsifiable que le nom de fichier, et aucune requête
sans garde n'a été ajoutée. Les émetteurs restent les endpoints de liste, qui savaient déjà quel
fichier appartient à qui.

### La branche héritée des runs hebdo n'a pas de lien public (AC 5, AC 8)

`WeeklyEntryPatchQuery` a un cas « local » d'avant l'orchestrateur dont le dossier de sortie n'est
pas celui d'une session (`dirname($seedPath)`, avant lancement). Rien n'y est signable, donc `url`
y vaut `null` et le front retombe sur la route authentifiée. Le cas lancé de cette même branche,
lui, porte bien un identifiant de session et reçoit son lien.

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-08-24 | 0.1 | Rédaction de la story | Claude |
| 2026-08-24 | 1.0 | Implémentation, une route publique au lieu de trois | Claude |
