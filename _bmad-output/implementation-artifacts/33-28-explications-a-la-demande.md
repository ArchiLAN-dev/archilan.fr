# Story 33.28: Les explications techniques à la demande

**Status:** review
**Epic:** 33 - Nettoyage
**Date:** 2026-10-07

## Story

En tant que membre,
je veux des pages qui me disent quoi faire, et le « pourquoi » technique seulement si je le demande,
afin de lire l'essentiel sans traverser des paragraphes qui ne me concernent pas.

## Contexte

Remarque de Jean (2026-10-07) : beaucoup d'explications de choix techniques s'affichent en permanence. Un
inventaire des pages publiques et membres en relève environ 62 textes distincts (13 de plus côté admin). Les pages
d'une partie (connexion, seed importée, suivi des slots, overlay) et les récaps en concentrent la plupart.

## Règle de tri

Chaque texte tombe dans l'une de trois familles :

1. **Derrière un bouton « i »** : le « pourquoi » technique, qui ne change rien à ce que l'on fait.
2. **Visible, mais raccourci** : ce qui conditionne une action, à garder sous les yeux au moment d'agir : une limite
   avant un envoi de fichier, une conséquence avant une action définitive, ou un état (« serveur en pause »).
   Une phrase courte reste visible, le détail passe derrière le « i ».
3. **Inchangé** : une consigne courte déjà au bon niveau (« Les joueurs avec le même nom forment un groupe »).

## Critères d'acceptation

1. **Un composant unique** `InfoHint` : une icône « i » (bouton) qui ouvre l'explication dans un petit panneau
   ancré au bouton, au-dessus de la page (popover) : rien ne se déplace en dessous (retour de Jean : un dépliement
   cassait l'affichage des blocs serrés).
   - Accessible : vrai `<button>`, `aria-expanded`, contenu lié par `aria-controls`, utilisable au clavier et au
     doigt (cible de 44 px).
   - Fermé par défaut, état non mémorisé.
   - Pas d'infobulle au survol seul : sur téléphone, le survol n'existe pas.
2. **Les dix pires cas** passent par `InfoHint` ou sont raccourcis (voir le tableau).
3. **Les doublons** sont fusionnés en un seul texte partagé : valeurs masquées pour le stream, serveur en pause,
   calcul du suivi en cours, seed importée, « partie déjà générée ».
4. **Les accents manquants** des textes de paiement sont corrigés au passage (inscription, boutique, formulaire
   HelloAsso indisponible).
5. **Rien de ce qui conditionne une action ne disparaît** : limites de fichiers, conséquences des suppressions, états
   du serveur restent visibles en une phrase.
6. Gates verts ; tests du composant (fermé par défaut, déplié au clic, attributs ARIA).

## Les dix pires cas (proposition)

| Page | Texte actuel | Proposition |
|---|---|---|
| Connexion (partie, événement) | Paragraphe « le port fait partie de l'adresse… 38281… version non chiffrée » | Visible : « Copie l'adresse avec son port. » ; le détail derrière « i » |
| Suivi d'un slot (seed importée) | Deux paragraphes sur la reconstruction impossible | Visible : « Checks faisables indisponibles sur une seed importée. » ; le pourquoi derrière « i » |
| Page de la partie (seed importée) | Même explication | Même texte partagé |
| Suivi d'un slot, run hebdo | « Calcul de la réatteignabilité… peut prendre une minute » | « Calcul en cours, la page se met à jour toute seule. » |
| Bannière serveur arrêté | Sauvegarde ou non, ce qui est conservé | Visible : l'état et l'action ; ce qui est conservé derrière « i » |
| Brouillon de partie | Test individuel contre génération complète | Visible : « N configs ont échoué au test : vérifie-les avant de lancer. » ; la nuance derrière « i » |
| Fiche d'un jeu | apworld et client à la version de la session | Visible : « Utilise la version indiquée. » ; le pourquoi derrière « i » |
| Overlay (onglet streaming) | Sources OBS, calcul par joueur, filtres | Une phrase d'usage ; le reste derrière « i » |
| Récap (timeline, flux d'objets) | Légendes écrites en paragraphes | Légende courte visible ; la lecture détaillée derrière « i » |
| Compte, profil | RGPD « revue manuelle », formats d'avatar, « un cadre ne s'enregistre qu'avec Enregistrer » | Formats et taille visibles (avant l'envoi) ; le reste derrière « i » |

## Décisions (2026-10-08)

Jean : icône « i » ; les dix pires cas d'abord ; pages admin hors périmètre.

## Tasks / Subtasks

- [x] **Task 1** - `components/ui/info-hint.tsx` (`InfoHint`, `InfoHintView` pour les tests) et ses tests.
- [x] **Task 2** - Les dix pires cas ; doublons fusionnés dans `components/run-notes.tsx` (valeurs masquées,
  serveur en pause, partie déjà générée) et `features/reachability/reachability-notes.tsx` (seed importée, calcul
  du suivi).
- [ ] **Task 3** - Le reste de l'inventaire (environ 50 textes), par lots : story à part.
- [x] **Task 4** - Accents des textes de paiement (inscription, boutique, billetterie indisponible) ; gates.

## Inventaire

Relevé le 2026-10-07 : environ 62 textes sur les pages publiques et membres, 13 sur l'admin. Les plus touchés :
`components/connection-fields.tsx`, `features/personal-runs/*` (seed importée, bannière d'inactivité, overlay,
brouillon), `features/weekly-runs/*`, `features/games/game-detail.tsx`, `features/recap/*`, `features/auth/*`,
`features/community/community-profile-customization-form.tsx`, `features/payments/*`.

## Dev Agent Record

- `InfoHint` : phrase visible dans un `<p>`, bouton `type="button"` de 44 px (marges négatives pour ne pas
  agrandir la ligne) ; explication dans un Popover Radix (portail, gardé à l'écran, fermeture par la croix, un clic
  à côté ou Échap, focus géré). Libellé par défaut « Pourquoi ? », « Comment lire le graphique » sur les légendes
  du récap. Un premier jet dépliait l'explication sous le texte : abandonné, il poussait le contenu.
- Cas traités : connexion (partie et événement), seed importée (page de la partie et suivi du slot), calcul du
  suivi (slot privé et hebdo), bannière de veille, brouillon (test de génération), fiche d'un jeu (versions),
  overlay OBS, récap (timeline, flux d'objets, qualité des envois), compte (RGPD, portabilité), profil (description
  de la photo raccourcie).
- Tests mis à jour : `connection-fields.test.tsx`, `idle-banner.test.tsx`, `imported-seed-panel.test.tsx` (le
  pourquoi n'est plus dans la page tant que le panneau est fermé).

