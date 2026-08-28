# Story 30.39: Badge Live Twitch sur les cartes joueurs

**Status:** implémentée - PR vers `develop`
**Epic:** 30 - Community
**Date:** 2026-08-28
**Issue:** [#300](https://github.com/ArchiLAN-dev/archilan.fr/issues/300)

## Story

En tant que visiteur de la page communauté,
je veux voir quels membres sont en direct sur Twitch,
afin de les rejoindre pendant qu'ils jouent.

## Context

La page `/communaute` liste les membres avec leur niveau, leur XP et un indicateur « en jeu ». Rien
n'y dit qu'un membre diffuse en ce moment, alors que le site sait déjà le faire ailleurs : la vue des
streams d'une partie affiche les participants en direct, avec leur nombre de spectateurs.

### Presque tout existe déjà

| brique | où | ce qu'elle fait |
|---|---|---|
| `TwitchLinkResolver::resolveLogin()` | `Streaming/Domain/Service` | extrait un login Twitch des liens sociaux, pur, sans I/O |
| `TwitchApiClientInterface::fetchLiveLogins()` | `Streaming/Application/Port` | check live en lot, **découpé par 100** comme l'exige Helix |
| `ParticipantStreamsView::liveMap()` | `Streaming/Application/Query` | cache du check, 60 s, ramené à 15 s quand Twitch répond mal |
| `DbalParticipantTwitchLinksQuery` | `Streaming/Infrastructure/Dbal` | lit `social_links` en lot, par événement / run / weekly |
| `live-twitch-badge.tsx` | `features/streaming` | le visuel du badge |
| `CommunityDirectory::enrich()` | `Community/Application/Query` | construit les lignes, expose déjà `playing` |

Ce qui manque : une lecture des liens sociaux **par identifiants de membres**, et le branchement.

### Le tri décide de la charge, pas l'inverse

`browse()` récupère **tous** les membres listables, les trie, puis découpe la page :

```php
$sortedIds = self::SORT_RECENT === $sort ? $this->sortByActivity(...) : $this->sortByXp(...);
$pageIds = array_slice($sortedIds, $offset, $perPage);
```

Remonter les live en tête impose donc de connaître l'état de **tous** les membres, pas des 60 de la
page : trier après le découpage ne ferait flotter les live qu'à l'intérieur de leur page, et
quelqu'un pourrait apparaître deux fois ou disparaître entre deux pages.

C'est tenable : `fetchLiveLogins()` découpe déjà par 100, et le cache est clé par l'ensemble trié des
logins - un seul ensemble stable, donc une entrée de cache rafraîchie toutes les 60 s pour tout le
monde, quelle que soit la page consultée.

### Décisions de cadrage (Jean, 2026-08-28)

| Question | Décision |
|---|---|
| Tri | **Les live remontent en tête**, dans le tri courant. L'ordre bougera d'une minute à l'autre, c'est accepté : la page sert à trouver qui joue maintenant. |
| Visibilité | **Tout le monde**, dès qu'un membre diffuse. Être en direct est public par nature - la chaîne est ouverte à tous. |
| Budget d'appels | Non bloquant : un appel Helix par tranche de 100 membres, mis en cache 60 s et partagé par toutes les pages. |

**Ce que la décision de visibilité implique, et qui est assumé :** le badge renvoie vers la chaîne,
donc il révèle le lien entre un pseudo ArchiLAN et un compte Twitch - y compris à un visiteur à qui
le membre avait restreint l'audience de ses liens sociaux. La règle est plus simple à comprendre
qu'une visibilité en escalier, et un direct est de toute façon visible sur Twitch.

## Acceptance Criteria

### Affichage

1. Une carte joueur porte un badge **Live** quand le membre diffuse sur Twitch, repris du visuel
   existant (`live-twitch-badge.tsx`) plutôt que redessiné.
2. Le badge est un lien vers `https://twitch.tv/{login}`, ouvert dans un nouvel onglet.
3. Un membre sans lien Twitch, ou hors direct, garde exactement la carte d'aujourd'hui.
4. Le badge coexiste avec l'indicateur « en jeu » : les deux sont vrais en même temps quand un
   membre joue une partie ArchiLAN en la diffusant, et c'est précisément le cas intéressant.

### Tri

5. Les membres en direct remontent **en tête**, à l'intérieur du tri choisi (XP ou activité) : entre
   deux live, l'ordre du tri courant s'applique toujours.
6. Le tri porte sur l'ensemble des membres listables, **avant** la pagination. Un membre ne doit ni
   apparaître sur deux pages ni disparaître entre deux.
7. Le nombre total et la pagination restent justes.

### Charge

8. Le check live est **groupé** pour tous les membres concernés, jamais un appel par membre, et
   réutilise le cache existant plutôt que d'en ouvrir un second.
9. Une panne Twitch ne casse pas la page : la directory s'affiche sans badge, et l'état d'échec est
   mémorisé brièvement pour se rétablir vite - le comportement déjà en place pour les streams.
10. La logique de check live n'est pas dupliquée : elle est extraite là où les deux appelants
    (streams d'une partie, directory) la partagent.

### Gates

11. `composer gates` et `pnpm gates` verts. Tests : tri avec et sans live, pagination, membre sans
    lien Twitch, panne Twitch, et absence d'appel par membre.

## Tasks / Subtasks

- [x] **Task 1** (AC 8, 10). Extraire le check live en lot et son cache du `ParticipantStreamsView`
  vers un service partagé, sans changer le comportement des streams de partie.
- [x] **Task 2** (AC 8). Lecture des liens sociaux par identifiants de membres, en lot.
- [x] **Task 3** (AC 5-7). Tri des live en tête dans `browse()`, avant le découpage.
- [x] **Task 4** (AC 1-4). Badge sur la carte, avec son lien.
- [x] **Task 5** (AC 9, 11). Panne Twitch, tests et gates.

## Dev Notes

- **Ne pas ajouter un second cache.** `ParticipantStreamsView::liveMap()` porte déjà la bonne
  politique (60 s, 15 s en cas de panne) et la bonne clé (l'ensemble trié des logins). L'extraire
  tel quel, et le faire appeler par les deux, plutôt que d'en écrire un proche mais différent.
- **`fetchLiveLogins()` découpe déjà par 100.** Rien à ajouter côté client Twitch, même en passant
  tous les membres de l'association.
- **La lecture des liens manque, pas la résolution.** `ParticipantTwitchLinksQueryInterface` sait lire
  `social_links` par événement, run et weekly ; il lui faut une entrée par identifiants. La résolution
  du login reste `TwitchLinkResolver`, qui est pure.
- **`cards()` ne porte pas les liens sociaux**, et n'a pas à les porter : elle sert des surfaces
  publiques qui n'en ont pas besoin. Les liens se lisent à côté, pour les identifiants de la page.
- **Le tri par activité et le tri par XP sont deux chemins.** Le tri des live doit s'appliquer aux
  deux, sans les réécrire : trier d'abord comme aujourd'hui, puis remonter les live en conservant
  l'ordre relatif.
- **Ce que la story ne fait pas :** afficher le nombre de spectateurs sur la carte, filtrer la
  directory sur les seuls live, ni toucher à la vue des streams d'une partie au-delà de l'extraction.

## Écarts assumés

### Le badge vit à côté du lien de la carte, pas dedans

`MemberCard` est un `<Link>` qui enveloppe toute la carte. Un badge cliquable **dans** ce lien
donnerait deux ancres imbriquées, ce que le HTML interdit et que React rend quand même - avec un
comportement de clic indéfini selon le navigateur.

La carte est donc devenue un conteneur positionné qui porte deux enfants : le lien vers le profil,
inchangé, et le lien Twitch posé en absolu sur sa droite. La carte réserve la place correspondante
(`pr-20`) uniquement quand le membre diffuse, pour que rien ne bouge pour les autres. Un test garde
l'invariant : le lien Twitch se trouve **après** la fermeture du lien de la carte.

### `LiveMark` plutôt qu'un badge dupliqué

L'AC 1 demandait de reprendre le visuel existant. `live-twitch-badge.tsx` mêlait ce visuel au statut
de **la chaîne ArchiLAN** (`useTwitchStatus`, `externalLinks.twitch`) : il n'était pas réutilisable
tel quel pour un membre. Le point qui pulse et le mot Live sont extraits dans
`features/streaming/live-mark.tsx`, sans lien ni état ; les deux appelants décident vers quelle
chaîne pointer. Le badge d'en-tête est inchangé à l'écran.

### Le double Twitch doit survivre au redémarrage du noyau

Le test de pagination fait trois requêtes. Sans `disableReboot()`, le conteneur est reconstruit entre
deux requêtes et le double retombe sur le vrai client Twitch : la page 2 ne voyait plus personne en
direct, et le test semblait dire que le tri ne portait que sur la première page. Le comportement
testé était bon, le banc de test ne l'était pas.

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-08-28 | 0.1 | Rédaction de la story | Claude |
| 2026-08-28 | 1.0 | Implémentation : `LiveTwitchLogins` partagé, tri live-first avant pagination, badge sur la carte | Claude |
