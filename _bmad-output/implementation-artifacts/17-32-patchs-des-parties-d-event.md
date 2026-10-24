# Story 17.32: Fichiers de génération des parties d'event, comme en privé et en hebdo

**Status:** review
**Epic:** 17 - Sessions et suivi des slots
**Date:** 2026-10-24

## Story

En tant que joueur inscrit à un event,
je veux télécharger les fichiers générés pour mes slots (patchs) dès que la partie est générée,
afin de préparer ma ROM comme en partie privée ou hebdo.

## Contexte

- Les parties privées (16.9) et hebdo lisent les patchs dans l'archive durable de la génération sur MinIO
  (`Session.generatedOutputKey`, repli `{sessionId}/output/archive.zip`). Le privé filtre sur les slots du joueur
  avec `PersonalRunPatchController::belongsToOwnSlot` (préfixe à une frontière `_`, nom le plus long parmi tous les
  slots de la session, `.archipelago` et `*_spoiler*` exclus).
- Les events passent par `PlayerPatchController` (`/api/v1/registrations/{id}/patches`), resté sur l'ancien dossier
  local `{workspace}/{sessionId}/output` et sur un nom de fichier strictement égal au nom du slot. Les sorties ne
  vivent plus là et les patchs AP s'appellent `AP_{seed}_P{n}_{slot}.{ext}` : la liste revient vide, la section
  « Fichiers de patch » ne s'affiche jamais.
- Le front n'affiche en plus la section qu'en `running`, `stopped` ou `finished`.

## Critères d'acceptation

1. La liste et le téléchargement des patchs d'une inscription lisent l'archive durable de la session (comme 16.9),
   avec le même filtrage que le privé : uniquement les slots joués par le membre (propres et co-joués, 16.17),
   jamais `.archipelago` ni `*_spoiler*`, attribution au nom le plus long parmi tous les slots de la session.
2. Accès inchangé : inscription du membre connecté, réservée et soumise. Sinon 404 (liste et téléchargement).
3. Disponibles dès que la session est générée, quel que soit son état ensuite : clé de sortie enregistrée, ou à
   défaut le repli déterministe pour une session au-delà de la génération. Avant la génération : liste vide,
   téléchargement 404.
4. Chaque fichier listé porte son lien public signé `forArchive` (16.16).
5. Un fichier d'un autre slot renvoie 403 au téléchargement.
6. Front : la section « Fichiers de patch » de la page de connexion s'affiche pour toute session générée
   (`generated` et au-delà, hors échec), et se masque toujours si la liste est vide.
7. L'attribution d'un fichier à un slot est partagée entre privé et event (une seule règle), le comportement privé
   ne change pas.

## Tâches

- [x] Déplacer la règle d'attribution dans `App\Sessions\Application\Support\SlotPatchFile::belongsTo`,
      `PersonalRunPatchController::belongsToOwnSlot` y délègue (AC 7).
- [x] `App\Sessions\Application\Query\RegistrationPatchQuery::forRegistration(registrationId, userId)` :
      `{outputKey, slotNames, allSlotNames}` ou null (AC 1-3).
- [x] Réécrire `PlayerPatchController` sur l'archive (lecteur `SessionOutputArtifactReaderInterface`) (AC 1-5).
- [x] Front : condition d'affichage de `PatchFilesSection` (AC 6).
- [x] Tests fonctionnels de la route event (AC 1-5) ; la règle partagée reste couverte par
      `PersonalRunPatchFilterTest` (11 cas) via la délégation.

## Dev Agent Record

- `RegistrationPatchQuery` reprend les gardes de `PlayerSessionConnection::getConnection` (propriétaire, réservée,
  soumise) et la dernière session de l'inscription.
- Statuts post-génération pour le repli de clé : `generated`, `launching`, `running`, `idle`, `restarting`,
  `stopped`, `crashed`, `finished`. Une session `failed` n'a une archive que si sa clé a été enregistrée.
- Le paramètre `workspaceDir` n'est plus injecté dans `PlayerPatchController` (la route publique garde sa branche
  `workspace` pour les anciens liens).
