# Epic 53: Mon event, l'espace de l'inscrit

**Statut :** proposé le 2026-11-11
**Date :** 2026-11-11
**Origine :** échange avec Jean le 2026-11-11, en préparant la diffusion locale des joueurs (epic 47) : un inscrit
perdu ne sait pas où trouver ce qui le concerne (explications, pelles, code Minimapperz, blind test...). Jean veut
« un point d'entrée pour les inscrits qui sont perdus » et a validé le périmètre complet (« go la totale »).

## Constat

Tout ce dont un inscrit a besoin existe déjà, mais à six endroits différents (cartographie du 2026-11-11) :

| Info | Où |
|---|---|
| Bouton blind test, code Minimapperz, sondages en attente | Page publique de l'événement (`app/(public)/evenements/[eventSlug]/page.tsx` l. 217-221), cartes visibles du seul inscrit |
| Consentement de diffusion, présence | `/evenements/[slug]/inscription/[id]/recap` |
| Jeux, YAML, slots | Sous-pages de l'inscription (`jeux`, `slots/[slotId]`) |
| Connexion à la partie, Passeur | `/evenements/[slug]/inscription/[id]/session` (`SessionConnectionGate`, `PasseurNoticeCard`) |
| Solde de pelles | `/compte/pelles` |
| Aide | `/aide/archipelago`, seule page d'aide ; rien sur les pelles, le Passeur, le blind test, Minimapperz |

Il n'existe ni texte d'infos pratiques propre à un événement (seul `description`, public), ni page qui dise à
l'inscrit ce qu'il lui reste à faire, ni rappel avant l'événement.

## Cadrage

- **Une page par événement, réservée à ses inscrits** : `/evenements/[eventSlug]/mon-event`. La page publique reste
  la vitrine (référencement, inscription) ; elle renvoie vers l'espace par un bandeau.
- **Pensée pour le téléphone** : on la consulte sur place, depuis le QR code posé sur les tables.
- **Ce qu'il me reste à faire** : une checklist calculée par l'API, chaque ligne mène à l'écran concerné.
- **Infos pratiques par événement**, écrites par l'orga (markdown), **visibles des seuls inscrits** : elles peuvent
  contenir le wifi, un code de porte ou le téléphone du staff.
- **Les outils de l'event** en cartes, affichées seulement quand la fonction est active pour l'événement.
- **Des guides communs** à tous les événements (pelles, Passeur, blind test, Minimapperz...), **modifiables dans
  l'admin** sur le modèle du guide Archipelago (`ArchipelagoGuide`, `/admin/archipelago-guide`), avec un texte de
  départ fourni.
- **La page change avec le moment** : avant, pendant, après l'événement.
- **On la trouve** : bandeau sur la page publique, menu du compte, mail de confirmation, mail de veille, QR code
  imprimable.

## Principes

- **Inscrit** = inscription active (`Registration::STATUS_RESERVED`) sur l'événement, confirmée ou non. Une
  inscription non confirmée (`submittedAt` nul) voit l'espace : sa première ligne de checklist est justement de
  confirmer.
- **Réutiliser les composants existants** : les cartes de l'espace sont les composants actuels
  (`BlindTestJoinButton`, `MinimapperzCodeCard`, `PresenceCard`, `BroadcastConsentCard`, `EventSurveyBanner`,
  `PasseurNoticeCard`...), qui lisent déjà leurs propres endpoints et se masquent quand rien n'est actif. Seule la
  checklist a besoin d'une lecture agrégée nouvelle.
- **Une seule lecture agrégée** côté API pour la checklist, dans `Registrations`, qui interroge les autres contextes
  par des interfaces de lecture déclarées dans `Registrations/Application/Query/` et implémentées par le contexte
  concerné, comme `PendingRegistrationSurveyQueryInterface` (story 51.5, implémentée par `Surveys`).
- **Moment** calculé par une fonction pure, partagée par l'API et le front : `before` (avant `startsAt`),
  `during` (statut `in-progress`, ou entre `startsAt` et `endsAt`), `after` (statut `completed`, ou après `endsAt`).
- **Aucune donnée sensible dans le HTML public** : les infos pratiques passent par un endpoint réservé aux inscrits,
  jamais par le payload public de l'événement ni par l'ISR.
- Le SSR est anonyme (cookie `__Host-` lié à l'API, cf. story 32.5) : la page est un squelette serveur avec un
  rendu client authentifié (`apiFetch`).

## Stories

### 53.1 - Socle de l'espace inscrit

Route `/evenements/[eventSlug]/mon-event`, garde d'accès (visiteur → connexion, non inscrit → page de
l'événement), en-tête (dates, lieu, statut de l'inscription), fonction pure du moment, sections vides prêtes.
Détail : `implementation-artifacts/53-1-socle-espace-inscrit.md`.

### 53.2 - Ce qu'il me reste à faire

`GET /api/v1/registrations/{id}/checklist` : confirmation, jeux, YAML, questions d'inscription, sondages en
attente, présence, Discord, paiement. Rendu en liste cochée avec un lien par ligne.
Détail : `implementation-artifacts/53-2-checklist-inscrit.md`.

### 53.3 - Les outils de l'event

Cartes : ma partie Archipelago, blind test, Minimapperz, pelles, Passeur, diffusion, présence. Les cartes blind test
et Minimapperz quittent la page publique au profit du bandeau.
Détail : `implementation-artifacts/53-3-outils-de-l-event.md`.

### 53.4 - Infos pratiques de l'événement

Champ markdown `practicalInfo` sur l'événement, édité dans l'admin, lu par un endpoint réservé aux inscrits.
Détail : `implementation-artifacts/53-4-infos-pratiques.md`.

### 53.5 - Guides d'aide modifiables

Contexte `Content`, entité `HelpGuide`, admin `/admin/aide`, pages `/aide` et `/aide/[slug]`, guides de départ
(pelles, Passeur, blind test, Minimapperz, première partie, diffusion), liens depuis les cartes.
Détail : `implementation-artifacts/53-5-guides-d-aide.md`.

### 53.6 - Avant, pendant, après

Ordre et repli des sections selon le moment ; après l'événement : résultats, récap, sondage d'après.
Détail : `implementation-artifacts/53-6-avant-pendant-apres.md`.

### 53.7 - Trouver l'espace

Bandeau sur la page publique, entrée « Mon event » dans le menu du compte, lien dans le mail de confirmation, QR code
imprimable dans le hub admin (« Jour J »).
Détail : `implementation-artifacts/53-7-trouver-l-espace.md`.

### 53.8 - Le mail de la veille

Mail planifié la veille de l'événement aux inscrits confirmés : dates, lieu, ce qu'il reste à faire, lien vers
l'espace.
Détail : `implementation-artifacts/53-8-mail-de-la-veille.md`.

## Dépendances

- 53.1 avant tout le reste.
- 53.2, 53.3, 53.4 et 53.5 indépendantes entre elles une fois 53.1 faite.
- 53.6 après 53.2 et 53.3 (elle ordonne leurs sections).
- 53.7 après 53.3 (le bandeau remplace les cartes retirées de la page publique).
- 53.8 après 53.2 (le mail reprend la checklist).
- Suggestion d'ordre : 53.1, 53.4, 53.2, 53.3, 53.7, 53.5, 53.6, 53.8. Les quatre premières plus 53.7 suffisent
  pour un premier event.

## Hors périmètre (pour plus tard)

- Carte « Diffuser pour la régie » (clé de publication, URL MediaMTX, état reçu ou non) : à ajouter à l'espace par
  l'epic 47 quand la diffusion locale sera cadrée.
- Plan de salle et numéro de place.
- Notification push « l'event commence ».
- Guides épinglés par événement (l'espace liste tous les guides publiés, et chaque carte pointe vers le sien).
- FAQ structurée (questions / réponses en base) : les infos pratiques markdown suffisent.
