# Epic 47 - Multistream des participants

**Date :** 2026-10-31
**Origine :** demande de Jean le 2026-10-31 : un moyen de proposer un multipov, ou la liste des streams en ligne sur
un événement, pour que la régie diffuse tout le monde pendant les pauses.

## Constat

- L'API sait déjà qui streame sur un événement : `GET /api/v1/events/{id}/participant-streams` (story 7.7) lit la
  chaîne Twitch des inscrits confirmés dans leurs liens sociaux, vérifie qui est en live en un appel Helix mis en
  cache, et renvoie la liste avec les lives en tête. Un événement terminé ne renvoie rien.
- Le site l'affiche déjà : sur la page de l'événement et sur `/streams/event/<id>`, une liste de cartes avec un seul
  lecteur partagé (un stream à la fois, derrière le consentement cookies de la story 7.5).
- Il manque deux choses : un écran que la régie met dans OBS pour montrer tout le monde à la fois, et une vue
  publique où plusieurs lives tournent côte à côte.

## Principes

1. **Chaîne renseignée = accord, refus possible.** Rediffuser un stream sur la chaîne ArchiLAN, c'est diffuser le
   contenu de quelqu'un d'autre. Un joueur qui a mis sa chaîne Twitch dans son profil est diffusable par défaut,
   mais peut refuser, inscription par inscription (décision de Jean le 2026-10-31). La vue
   publique, elle, montre le lecteur Twitch du joueur lui-même, comme la liste actuelle : pas de consentement en plus.
2. **On réutilise la lecture existante** (participant-streams) : pas de second appel à Twitch, pas de nouveau cache.
3. **L'écran régie suit le modèle de `/o/partenaires`** (épic 45) : une page sans habillage du site, pensée pour une
   source navigateur OBS, réglée par l'URL.
4. **Plafond de lecteurs.** Au-delà de 6 à 9 lecteurs Twitch simultanés, OBS et le navigateur chauffent : la
   mosaïque est plafonnée, le reste passe en rotation.

## Stories

### 47.1 - Le joueur accepte que la régie diffuse son stream

- Une case par inscription, cochée par défaut : « La régie ArchiLAN peut diffuser mon stream Twitch pendant
  l'événement ». Le joueur la décoche lui-même s'il refuse, tant que l'événement n'est pas terminé.
- La lecture participant-streams porte l'information ; une lecture dédiée à la régie renvoie les lives, sauf
  ceux qui ont refusé.
- L'admin voit qui a refusé sur la liste des inscriptions de l'événement.

### 47.2 - L'écran régie : tous les lives en mosaïque

- `/o/streams?event=<id>` : mosaïque automatique des lives (hors refus) (1, 2, 4, 6 ou 9 cases selon le nombre),
  pseudo sur chaque case, lecteurs muets, liste relue toutes les minutes.
- Option `?mode=rotation&duree=<s>` : un stream en grand, les autres en vignettes, qui tourne à intervalle régulier.
- Au-delà du plafond, les cases tournent par groupes.
- Plafond `?max=` (4 par défaut, jusqu'à 9). Rien à l'écran quand personne n'est en live (la régie garde sa scène).

### 47.3 - La mosaïque publique

- Sur `/streams/event/<id>`, un mode « Mosaïque » à côté de la liste : jusqu'à 4 lives côte à côte, un clic sur un
  stream le passe en grand avec le son.
- Même consentement cookies que la liste actuelle (story 7.5), pas de mosaïque sur téléphone.

## Dépendances

- 47.2 dépend de 47.1 (la lecture régie). 47.3 est indépendante.

## Hors périmètre (pour plus tard)

- Les streams des parties privées et des hebdos (la lecture existe pour eux, l'écran régie se limite aux événements).
- Le son géré par l'écran régie : c'est OBS qui gère le son de la scène.
- Un lien vers un service de multistream tiers (type multistre.am) : écarté au profit de nos propres écrans.
