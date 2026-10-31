# Epic 46: Notes admin

**Statut :** proposé le 2026-10-30
**Date :** 2026-10-30
**Origine :** brainstorming avec Jean le 2026-10-30
(`_bmad-output/brainstorming/brainstorming-session-2026-10-30-2145.md`).

## Constat

Les admins (les bénévoles de l'association) remarquent des choses sur les inscriptions (paiement réglé en espèces,
autorisation parentale attendue, arrivée tardive, place à garder) et n'ont nulle part où les écrire : l'information
se perd dans Discord, en message privé ou dans les têtes. Le jour J, l'accueil n'a pas ces informations sous les
yeux.

Il existe déjà des messages admin sur une inscription (`RegistrationAdminMessage`, `SendMessageToRegistrant`), mais
ils sont **envoyés au membre** : ce n'est pas un outil interne.

## Cadrage de Jean

- **Un carnet de notes, pas une messagerie** : on remarque quelque chose, on l'écrit pour ne pas perdre
  l'information. Les réponses servent à raconter comment la note évolue (« vérifié », « réglé »).
- **On écrit avant, on lit pendant, on fait le tri après** : les notes s'écrivent en amont de l'événement, se lisent
  le jour J à l'accueil (on n'y écrit presque pas), et ce qui doit durer est reporté à la main sur le compte de
  l'utilisateur après l'événement.
- **Une note doit attirer l'œil** de l'admin à l'accueil.
- **Admins seulement**, en lecture comme en écriture. Le membre concerné ne voit jamais une note.
- **Zéro automatisme** : une note n'est jamais copiée, déplacée ni purgée par le système.
- **Générique, mais sans usine à gaz** : la crainte principale de Jean est un moteur « n'importe quelle entité » si
  abstrait que l'écran devient pénible. On commence par les inscriptions aux événements.

## Modèle

- Une **note** : texte brut, auteur, date, rattachée à une **cible** (type + identifiant).
- Des **suivis** : texte, auteur, date, en chronologie sous la note. Régler ou rouvrir une note est un suivi
  (texte facultatif, par exemple « réglé : paiement reçu dans HelloAsso »).
- Deux états : **ouverte** et **réglée**. « Réglée » est la seule façon de ranger une note : pas d'archive à part,
  **pas de suppression** pour l'instant. Une note réglée peut être **rouverte**.
- La note principale est **modifiable par tout admin**, l'auteur d'origine est conservé et **chaque modification
  est historisée** (qui, quand, texte précédent). Les suivis ne sont pas versionnés : on corrige un suivi par un
  autre suivi.

## Principes

- **Générique dans les données, spécifique dans l'écran** : en base, une note porte un type de cible et un
  identifiant ; l'écran d'une inscription a son propre panneau, simple. Le composant de chronologie sera réutilisé
  pour la cible suivante, sans « gestionnaire universel ».
- **Liste fermée de types de cible** (énumération, `registration` seulement pour cette epic). Ajouter une cible =
  une valeur et un contrôle d'existence, pas un plugin.
- **La cible est vérifiée à l'écriture** : une note ne peut pas viser une inscription qui n'existe pas (404).
- **Contexte DDD dédié**, qui ne connaît aucune entité métier : il vérifie une cible par un port, implémenté côté
  inscriptions.
- **Signal en lot** : la liste des inscriptions récupère le nombre de notes ouvertes de toutes ses lignes en une
  requête, pas une par ligne.
- **Jamais vers le membre** : aucune route, aucun export, aucun email, aucune vue membre ne renvoie une note.

## Stories

### 46.1 - Socle des notes (API)

En tant qu'admin, je veux écrire, compléter, modifier, régler et rouvrir une note sur une inscription, afin de ne
plus perdre ce que l'on remarque.

- Nouveau contexte (`Notes`) : entités note, suivi et révision de note, énumération des types de cible
  (`registration`), port de vérification de cible implémenté dans `Registrations`.
- Commandes : créer une note, modifier son texte (révision historisée, auteur d'origine conservé), ajouter un suivi,
  régler, rouvrir (un changement d'état est un suivi au texte facultatif ; régler une note réglée ou rouvrir une
  note ouverte est refusé).
- Requêtes : les notes d'une cible avec leurs suivis (ouvertes d'abord), l'historique d'une note, et le nombre de
  notes ouvertes pour une liste de cibles (prête pour 46.3).
- Routes admin uniquement, sous `/api/v1/admin/notes/...`. Bornes de longueur sur les textes.
- Détail : `implementation-artifacts/46-1-socle-notes.md`.

### 46.2 - Notes sur une inscription (admin)

En tant qu'admin, je veux voir et écrire les notes d'une inscription depuis sa fiche admin
(`/admin/evenements/[eventId]/inscriptions/[registrationId]`), afin de tout avoir au même endroit.

- Panneau « Notes » : chronologie (note, suivis, changements d'état), notes réglées repliées et grisées, saisie
  d'une note, d'un suivi, boutons Régler / Rouvrir avec texte facultatif, modification de la note avec accès à
  l'historique des versions.
- Composant de chronologie indépendant de l'inscription (réutilisable pour une prochaine cible).

### 46.3 - La note saute aux yeux sur la liste des inscriptions

En tant qu'admin à l'accueil, je veux voir immédiatement quelles inscriptions ont une note ouverte et la lire sans
cliquer, afin de ne rien rater pendant les arrivées.

- Liste admin des inscriptions d'un événement (`/admin/evenements/[eventId]/inscriptions`) : pastille avec le
  nombre de notes ouvertes, bien visible ; aucune pastille pour une inscription sans note ouverte.
- La dernière note ouverte se lit sans clic (survol ou dépliage de la ligne), lien vers la fiche pour le reste.
- Compteur calculé en lot pour toute la liste. Les inscriptions annulées suivent l'affichage actuel de la liste,
  sans traitement particulier de leurs notes.

## Dépendances

- 46.1 avant 46.2 et 46.3.
- 46.2 et 46.3 sont indépendantes.

## Hors périmètre (pour plus tard)

- **Notes sur le compte utilisateur** : nouvelle valeur de l'énumération. À trancher à ce moment : le partage avec
  le forum staff Discord de l'epic 39 (modération), et la suppression définitive pour le RGPD (droit d'accès,
  effacement).
- Mentions, notifications, non-lus, pièces jointes, mise en forme (markdown).
- Report automatique d'une note d'inscription vers le compte, purge automatique : écartés par Jean (tri manuel).
