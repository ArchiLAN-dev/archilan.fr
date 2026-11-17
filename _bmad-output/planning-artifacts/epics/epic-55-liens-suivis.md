# Epic 55: Liens suivis, mesurer qui arrive chez nous et qui part chez nos partenaires

**Statut :** validé le 2026-11-17
**Date :** 2026-11-17
**Origine :** demande de Jean le 2026-11-17 : « proposer des liens trackés pour obtenir les stats de visites sur nos
liens, pour ceux qui arrivent chez nous et inversement, ceux qui vont voir nos partenaires depuis notre site. Une
feature commune pour les deux est suffisante. »

## Constat (analyse du 2026-11-17)

- Le site n'a **aucune mesure d'audience** : pas d'outil tiers (Plausible, Umami, Matomo), pas de paramètre `utm_`
  lu, pas de compteur.
- Les cartes partenaires (`features/content/partner-card.tsx`, page `/partenaires`, fiche d'un event, accueil)
  pointent directement vers `partner.url` : on ne sait pas combien de visiteurs on envoie à un partenaire, alors que
  c'est l'argument qu'on peut leur rendre (epic 45, Minimapperz en epic 49).
- On partage nos liens sur Discord, les réseaux, des affiches (QR codes de l'epic 53) sans savoir lesquels amènent du
  monde.

## Cadrage (choix de Jean, 2026-11-17)

1. **Compteur maison** : l'API compte elle-même, sans cookie ni service tiers. Pas de bandeau de consentement à
   ajouter.
2. **Lien court `archilan.fr/l/{code}`** : le passage est compté puis le visiteur est redirigé vers la cible. Court,
   lisible, utilisable en QR code ; la cible peut changer sans changer le lien déjà diffusé.
3. **Compteurs par jour** : nombre de passages par lien et par jour, avec le site d'origine (referrer, domaine
   seulement). **Aucune IP, aucun identifiant, aucune donnée personnelle** stockés.
4. **Sortants automatiques et manuels** : chaque partenaire a son lien suivi, utilisé par toutes ses cartes ; un
   admin peut aussi créer un lien vers n'importe quelle URL externe.

## Principes

- **Un seul objet pour les deux sens.** Un lien suivi a un code, un libellé et une cible. Une cible interne (chemin du
  site, `/evenements/archilan-4`) fait un lien entrant, une cible externe (`https://...`) un lien sortant. Le sens se
  déduit de la cible, il n'est pas stocké à part.
- **La redirection ne doit jamais casser.** Si l'API ne répond pas, `/l/{code}` redirige vers l'accueil ; le
  comptage est perdu, pas le visiteur. Un code inconnu ou désactivé mène à l'accueil.
- **Pas d'open redirect.** Seul un admin écrit une cible ; la redirection ne lit jamais une URL dans la requête.
- **Les robots ne comptent pas.** Discord, X, WhatsApp, Telegram, Slack et les moteurs de recherche chargent le lien
  pour en faire un aperçu : une requête `HEAD` ou un user-agent de robot (`Discordbot`, `Twitterbot`,
  `facebookexternalhit`, `Slackbot`, `TelegramBot`, `WhatsApp`, `Googlebot`, `bingbot`, motif `bot|crawler|spider|preview`)
  est redirigée sans être comptée.
- **Compter des passages, pas des personnes.** Sans IP ni cookie, deux clics de la même personne font deux passages.
  C'est assumé et écrit dans l'écran de stats.
- Le referrer n'est souvent pas transmis (applis Discord, mobile, `strict-origin-when-cross-origin`) : il s'affiche
  « direct ». C'est le code du lien qui dit d'où vient le visiteur, le referrer n'est qu'un complément.

## Stories

### 55.1 - Liens suivis et redirection

Détail : `implementation-artifacts/55-1-liens-suivis-et-redirection.md`.

Contexte API (nouveau ou `Content`, à trancher dans la story), entité `TrackedLink` (code unique, libellé, cible
interne ou externe, actif), compteur `tracked_link_daily` (lien, jour, domaine d'origine, nombre ; incrément en
upsert). Route Next `app/l/[code]/route.ts` : appel serveur à l'API qui compte et renvoie la cible, puis `302`.
Robots et `HEAD` exclus, repli sans API. Écran admin `/admin/liens` : créer, modifier la cible, désactiver, copier le
lien complet.

### 55.2 - Statistiques des liens

Détail : `implementation-artifacts/55-2-statistiques-des-liens.md`.

Sur `/admin/liens` : total, 7 et 30 derniers jours par lien, tri par sens (entrants, sortants). Détail d'un lien :
courbe par jour sur 90 jours, origines principales. Mention « passages, pas visiteurs uniques ».

### 55.3 - Liens des partenaires

Détail : `implementation-artifacts/55-3-liens-des-partenaires.md`.

Chaque partenaire reçoit son lien suivi (créé avec le partenaire, rattrapage des partenaires existants par
migration), mis à jour quand son URL change. Toutes les cartes partenaires (accueil, `/partenaires`, fiche d'event)
passent par `/l/{code}`. Nombre de visites envoyées sur la fiche admin du partenaire (`/admin/partenaires`).

## Ordre et dépendances

```
55.1 → 55.2
55.1 → 55.3
```

## Hors périmètre (suites possibles)

- QR code d'un lien suivi, à imprimer (le QR de l'epic 53 sait déjà en faire).
- Donner ses stats à un partenaire (page publique à jeton, ou l'API organisateur de l'epic 49).
- Conversion : savoir si un visiteur arrivé par un lien s'est ensuite inscrit à un event (demanderait un cookie, donc
  un consentement).
- Mesure d'audience de tout le site.

## Points tranchés (Jean, 2026-11-17)

1. **Format du code** : saisi par l'admin (`discord-nov`), minuscules, chiffres et tirets, 3 à 40 caractères, unique.
2. **Durée de conservation** des compteurs : tout garder (aucune donnée personnelle).
