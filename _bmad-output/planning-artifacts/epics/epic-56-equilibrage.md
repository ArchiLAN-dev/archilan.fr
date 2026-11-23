# Epic 56: Équilibrage des parties, voir et corriger le monde qui bloque tout le monde

**Statut :** validé le 2026-11-21
**Date :** 2026-11-21
**Issue :** [#1173](https://github.com/ArchiLAN-dev/archilan.fr/issues/1173) (recherche et proposition en commentaire)
**Origine :** demande de Jean le 2026-11-21 : créer quelque chose qui permet d'équilibrer les parties et les events,
« car les games Archipelago sont souvent déséquilibrées par rapport aux autres joueurs, genre on avait un joueur à
16k de checks ».

## Constat (analyse et recherche du 2026-11-21)

- **Personne ne voit le déséquilibre avant le lancement.** Le joueur règle son YAML sans savoir ce que ses options
  pèsent, et l'admin valide des inscriptions une par une, jamais la partie entière.
- **Le site ne garde pas la taille d'un monde.** `checks_total` n'existe qu'en direct (bridge) et dans les hebdos
  (`weekly_entry.checks_total`) ; `session_slot` n'a que `checks_done`.
- **Le nombre de checks ne suffit pas.** 16 000 checks d'un jeu où un check prend une seconde ne pèsent pas comme 400
  checks d'Ocarina of Time. Il faut comparer une durée estimée.
- **Un monde géant détient les clés des autres.** Lors du remplissage, Archipelago mélange les emplacements de tous
  les mondes et pose chaque objet sur le premier emplacement accessible (`Fill.py`, `distribute_items_restrictive`),
  sans aucune pondération par monde. Un monde reçoit donc la progression des autres à peu près en proportion de sa
  part des emplacements. Avec un monde de 16 000 checks au milieu de huit mondes de 300, environ 87 % des clés de
  tous les joueurs se trouvent dans ce seul monde. C'est ce qui transforme un joueur lent en partie bloquée.
- **Rien d'équivalent ailleurs.** La communauté Archipelago s'en remet à des règles d'hôte écrites à la main (limiter
  les jeux, demander une durée, bannir des jeux). `progression_balancing` avance la progression d'un joueur en retard,
  mais ne touche ni à la taille d'un monde ni à la durée de la partie. Les trackers montrent qui est bloqué une fois
  la partie lancée, sans rien prévoir. HowLongToBeat n'a pas d'API officielle, et une durée de jeu complet ne vaut
  pas celle d'un monde randomisé : notre propre historique est la meilleure source de rythme.

## Ce qui existe déjà et qu'on réutilise

- Génération de test d'un slot : « Tester ma config » (9.42) et test admin d'un slot d'event (11.11,
  `StartEventSlotYamlTest`).
- Lecture d'une archive de génération dans le conteneur Archipelago de l'orchestrateur, isolé du réseau, avec
  l'unpickler restreint d'Archipelago (16.18, `SeedArchiveGatewayInterface::inspect`). La multidata n'est jamais
  dépicklée dans l'API.
- Lecture du parcours du spoiler (`SpoilerPlaythrough`, `SessionSpoilerArtifactReaderInterface`) et carte des
  dépendances entre slots (52.7, `DependencyMapQuery`).
- Historique de jeu : `session_slot.checks_done`, `goal_reached_at`, `last_check_at`, dates des sessions.
- Introspection des options d'un jeu (`GameIntrospection`) et curation des valeurs (9.51 à 9.53).
- Hub admin d'un event (3.17) et espace inscrit (épic 53).
- Pendant la partie : release, Passeur (épic 52), état BK.

## Cadrage (choix par défaut, go de Jean le 2026-11-21)

1. **Durée cible d'un event** : par défaut, la durée de l'event moins 20 % ; modifiable par l'admin. Une partie
   privée et un template hebdo peuvent avoir la leur.
2. **Au-delà du seuil : alerte seule.** Rien n'est bloqué automatiquement ; l'admin voit le slot signalé et décide.
3. **Visibilité** : l'estimation d'un slot est visible par son joueur et par les admins (et par le propriétaire d'une
   partie privée). Pas d'affichage public.

## Principes

- **Trois chiffres par slot** : taille (emplacements), durée estimée, part de la progression des autres hébergée par
  le monde. La part est le chiffre qui dit « ce monde va bloquer les autres ».
- **Une estimation dit d'où elle vient.** Rythme mesuré sur N parties, ou estimation admin, ou inconnu. Pas de chiffre
  inventé : un jeu sans rythme connu affiche la taille seule.
- **Le joueur garde la main.** On prévient, on propose un réglage ; on ne réécrit pas son YAML sans son accord.
- **Jamais de pickle dans l'API.** Tout ce qui lit une multidata passe par le conteneur isolé de l'orchestrateur,
  comme 16.18.
- **On mesure le prévu et le réel** pour que l'estimation s'améliore à chaque partie.

## Stories

### 56.1 - Taille et part de progression d'un slot à la génération

Issue : #1174. Détail : `implementation-artifacts/56-1-taille-et-part-de-progression.md`.
À chaque génération (session, test de slot), l'orchestrateur lit la multidata et renvoie par slot le nombre
d'emplacements, le nombre d'objets de progression du slot, et le nombre d'objets de progression des autres hébergés
dans ce monde. L'API les garde sur `session_slot` (session) et sur le résultat du test.

### 56.2 - Rythme de référence par jeu et par joueur

Issue : #1175. Détail : `implementation-artifacts/56-2-rythme-par-jeu-et-par-joueur.md`.
Médiane des checks par heure d'un jeu sur les slots ayant atteint le goal ; facteur propre au joueur, ramené vers 1
tant que son historique est mince. Saisie admin du rythme d'un jeu en repli. Durée estimée d'un slot = emplacements ÷
rythme, avec sa provenance.

### 56.3 - Génération à blanc et rapport d'équilibre

Issue : #1176. Détail : `implementation-artifacts/56-3-generation-a-blanc-et-rapport.md`.
Un admin génère la partie entière d'un event sans la lancer (même chemin que la génération, sans serveur). Rapport
dans le hub admin, et sur une partie privée pour son propriétaire : par slot, taille, durée estimée, écart à la
médiane, part de progression des autres ; alertes au-delà de 2 fois la médiane, de la durée cible, ou d'une part
supérieure à 2 fois la part équitable.

### 56.4 - Simulateur de partie

Issue : #1177. Détail : `implementation-artifacts/56-4-simulateur-de-partie.md`.
À partir du spoiler d'une génération à blanc et des rythmes, on déroule la partie : heure de fin estimée par slot,
temps estimé en BK, et qui attend après qui. Affiché dans le rapport d'équilibre.

### 56.5 - Durée cible, avertissement au YAML et préréglages par jeu

Issue : #1178. Détail : `implementation-artifacts/56-5-duree-cible-et-avertissement.md`.
Durée cible par event (défaut : durée de l'event moins 20 %). À l'enregistrement de son YAML, le joueur peut tester
son slot et voit « environ N checks, environ X h ; la cible est Y h ». Préréglages court, moyen et long par jeu,
maintenus par les admins.

### 56.6 - Plafond de part de progression dans l'image archipelago

Issue : #1179. Détail : `implementation-artifacts/56-6-plafond-de-part-de-progression.md`.
Option de génération : un monde n'héberge pas plus d'un multiple de sa part équitable de la progression des autres.
Au-delà, une partie de ses emplacements est marquée `EXCLUDED` avant le remplissage. Commence par un essai sur le banc
de génération ; activable par event.

### 56.7 - Réglage automatique des options de taille

Issue : #1180. Détail : `implementation-artifacts/56-7-reglage-automatique.md`.
Pour une option numérique qui change la taille d'un monde, recherche par dichotomie, avec des générations de test,
de la valeur qui ramène le slot vers la durée cible. Proposée au joueur, jamais imposée.

### 56.8 - Prévision en direct et calibration

Issue : #1181. Détail : `implementation-artifacts/56-8-prevision-et-calibration.md`.
Pendant la partie, heure de fin estimée par slot à partir du rythme observé, et suggestion de release ou de Passeur
quand un slot retient des objets. Après la partie, le prévu et le réel sont gardés, et l'écart s'affiche dans le
rapport.

## Ordre et dépendances

```
56.1 → 56.2 → 56.3 → 56.4
56.1 → 56.3
56.2 → 56.5 → 56.7
56.1 → 56.6 (essai sur banc d'abord)
56.2 → 56.8, 56.4 → 56.8 (calibration)
```

Les stories 56.1 à 56.3 apportent l'essentiel : le slot géant et le blocage qu'il va causer sont visibles avant le
lancement. 56.6 est la correction la plus efficace contre le blocage.

## Hors périmètre (suites possibles)

- Refus automatique d'une inscription au-delà d'un seuil (le cadrage dit alerte seule).
- Données externes de durée (HowLongToBeat) : pas d'API officielle, et une durée de jeu complet ne vaut pas une
  durée de monde randomisé.
- Affichage public des estimations sur la fiche d'un event.
- Mode classé (#273), qui pourrait réutiliser les rythmes par joueur.
