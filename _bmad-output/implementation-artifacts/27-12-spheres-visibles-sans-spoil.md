# Story 27.12: Sphères visibles sans spoil, réglables par partie

**Status:** review
**Epic:** 27 - Configuration des sessions (profil par type + surcharge par partie)
**Date:** 2026-10-24

## Story

En tant qu'admin ou propriétaire d'une partie,
je veux choisir si les joueurs voient l'onglet Sphères de leur slot,
afin d'offrir une aide à la progression sans révéler quel objet se cache derrière chaque lieu.

En tant que joueur,
je veux voir mes sphères de progression (lieux par sphère, faits / accessibles / en attente) quand la partie le permet,
afin de savoir où chercher quand je suis bloqué.

## Contexte

Le bridge calcule déjà les sphères du slot (`spheres` dans la réponse de `/reachable/{slot}`, story 9.20 et
suivantes). L'API retire déjà l'objet de chaque lieu pour un non-admin (`stripItemRewards` dans
`PlayerStateController` et `WeeklyRunSlotStateController`, y compris dans `spheres[].locations`) : ce que reçoit
un joueur est donc déjà « sans spoil » au sens objet.

Aujourd'hui l'affichage est figé dans le front :

- partie privée / event (`personal-run-slot-detail-page.tsx`) : onglet Sphères visible seulement pour l'admin ou
  le propriétaire, et seulement après avoir cliqué « afficher les spoilers » ;
- hebdo (`weekly-run-slot-page.tsx`) : pas d'onglet Sphères du tout.

Les sphères, même sans objet, en disent plus que la liste « accessible maintenant » (elles montrent l'ordre
logique de la seed). D'où un réglage plutôt qu'un affichage systématique.

Modèle de config (epic 27, voir 41.3 pour un réglage de site du même genre que `pelleHints`) : profil par type
(privé, hebdo, event) réglé par l'admin, surcharge par partie (propriétaire pour une privée, admin pour un event,
admin par template pour un hebdo). Réglage de site, non envoyé au serveur Archipelago.

## Critères d'acceptation

### Réglage

1. Un réglage **Sphères visibles par les joueurs** (oui/non) rejoint `SessionServerConfig` comme `pelleHints`
   (clé `spheresVisible`), avec sa place dans `SessionConfig::toArray/fromArray`, `SessionConfigOverride` et la
   fusion profil ⊕ surcharge. Optionnel en lecture : un profil stocké avant cette story n'en porte pas et vaut `true` ;
   une surcharge sans la clé hérite du profil.
2. Défaut des trois profils : **activé** (décision Jean, 2026-10-24). L'admin ou le propriétaire peut le couper
   pour une partie.
3. Le réglage apparaît dans le formulaire admin des profils (`admin-session-config-page.tsx`, avec son aide dans
   `session-config-help.ts`) et dans le formulaire de surcharge (`session-config-override-form.tsx`), donc pour le
   propriétaire d'une privée, l'admin d'un event et l'admin d'un template hebdo.

### Ce que l'API renvoie

4. `GET .../slots/{n}/reachable` (session) et son équivalent hebdo : pour un non-admin, `spheres` n'est renvoyé
   que si le réglage effectif de la partie est activé, ou si l'appelant est le propriétaire de la partie privée
   (il peut l'activer lui-même, on ne lui retire pas ce qu'il voit déjà). Sinon la clé est retirée. Les objets
   restent retirés comme aujourd'hui. L'admin reçoit tout, comme aujourd'hui.
5. Même règle pour la réponse 202 « computing » (le `previous`).

### Affichage

6. Partie privée / event : l'onglet Sphères s'affiche dès que la réponse contient des sphères, sans passer par
   « afficher les spoilers ». Pour l'admin, le bouton spoilers continue de contrôler l'affichage des objets.
7. Hebdo : l'onglet Sphères apparaît sur la page du slot quand la réponse contient des sphères, avec le même
   composant `SphereLine` (objets absents).
8. `SphereLine` n'affiche pas de ligne « → objet » quand l'objet est absent (déjà le cas, à couvrir par un test).

### Tests

9. API : fusion et aller-retour du réglage (domaine), filtrage de `spheres` selon réglage / rôle sur les deux
   contrôleurs (fonctionnels), profil stocké sans la clé = `true`, surcharge sans la clé = hérite.
10. Front : onglet visible / masqué selon la présence de `spheres`, sur les deux pages ; formulaires.

## Hors périmètre

- Les topics Mercure de reachability (push temps réel) : vérifier pendant le dev s'ils portent `spheres` à
  destination d'un non-admin ; si oui, appliquer le même filtrage ou le signaler dans les notes de dev.
- Les overlays de stream.

## Tâches

- [x] Domaine : `spheresVisible` dans `SessionServerConfig`, `SessionConfig`, `SessionConfigOverride` + tests unitaires
- [x] Application : exposer le réglage effectif d'une session / d'une entrée hebdo aux contrôleurs reachable
- [x] Présentation : filtrage de `spheres` dans `PlayerStateController` et `WeeklyRunSlotStateController` + tests fonctionnels
- [x] Front admin : profil + surcharge + aide
- [x] Front joueur : onglet Sphères (privée / event sans toggle, hebdo nouveau) + tests
- [x] Vérifier les topics Mercure (hors périmètre, à noter)
- [x] `pnpm gates` vert ; cote API phpstan, cs-fixer, ddd et rector verts, phpunit fonctionnel laisse a la CI (Postgres local arrete)

## Dev Notes

- Suivre le chemin de `pelleHints` (story 41.3) pour toutes les couches de config.
- Pas de migration : profils et surcharges sont stockés en JSON.

## Dev Agent Record

- API : `spheresVisible` dans la config (defaut `true`, profil stocke sans la cle = `true`, surcharge sans la cle =
  herite). `SlotSpheresVisibilityQuery` (session : profil prive + surcharge du run, ou event + surcharge de la
  session ; le proprietaire d'une privee voit toujours) et `WeeklyRunSlotQuery::spheresVisible` (template).
  Les deux endpoints `reachable` retirent `spheres` pour un non-admin quand le reglage est coupe, et renvoient
  `spheresVisible` a cote de `data` (y compris en 202).
- Front : l'onglet Spheres suit `spheresVisible` de la reponse API, pas la presence de `spheres` dans une trame.
  `SphereLine` prend `hideItems` ; la page privee/event le lie au bouton spoilers, l'hebdo le force.
- Mercure : le push `runs/{id}/slots/{n}/reachable` relaie le calcul brut du bridge (`ReachablePushController`),
  objets et spheres compris, a tout abonne autorise. Ce n'est pas nouveau (les objets des checks y sont deja) ;
  le front masque objets et onglet selon la reponse API. Filtrer le topic par viewer demanderait un topic par role :
  a traiter dans une story dediee si besoin.
