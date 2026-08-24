# Story 16.15: Refonte de l'état « en veille » d'une run privée

**Status:** implémentée - PR vers `develop`
**Epic:** 16 - Personal runs (parties privées créées par un membre)
**Date:** 2026-08-24
**Story liée :** [16.14](16-14-relance-d-un-run-idle-par-tout-participant.md) - a ouvert la reprise
aux participants, sans toucher à sa présentation

## Story

En tant que participant ou propriétaire d'une run privée en veille,
je veux comprendre d'un coup d'œil dans quel état est ma partie et ce que la reprise implique,
afin de la relancer sans hésiter, et sans risquer d'effacer ma progression par mégarde.

## Context

Une run privée s'endort toute seule : le watchdog d'inactivité arrête le conteneur et la run passe
en `STATUS_IDLE` (epic 17). La reprise est manuelle et volontaire, c'est la décision d'architecture
de 17.6/17.7/17.8. La story 16.14 a ouvert cette reprise à tout participant, mais n'a fait que
sortir la carte existante du bloc propriétaire : sa présentation n'a jamais été revue.

Elle a deux variantes, distinguées par `run.pausedWithoutSave` :

| variante | conséquence | libellé actuel |
|---|---|---|
| sauvegarde disponible | recharge la dernière sauvegarde | « Reprendre manuellement » |
| `pausedWithoutSave` | **repart de zéro, toute la progression est perdue** | « Relancer depuis le début » |

### Ce qui ne va pas, constaté en production le 2026-08-24

**1. L'action destructrice est déguisée en action normale.** Les deux variantes portent le même
violet primaire, la même taille, le même ton neutre. Et `handleRestart` part sans aucune
confirmation :

```ts
async function handleRestart(sessionId: string) {
  setActioning(true);
  const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/restart`, { method: "POST" });
```

Un clic sur « Relancer depuis le début » et la partie repart de zéro, sans retour possible. C'est
le point le plus grave de cette story ; les cinq suivants sont de la présentation.

**2. La hiérarchie est inversée.** « Supprimer la partie » est rendu en pleine largeur juste sous la
carte de reprise, donc visuellement plus lourd que la seule action utile dans cet état.

**3. Boîte dans une boîte.** L'explication est un `<p>` bordé à l'intérieur d'une `<section>` bordée,
en `text-muted-foreground` : elle se lit comme une note désactivée alors que c'est le message
principal de la page.

**4. Trois vocabulaires pour une même idée.** Le badge dit « En pause », le texte « mise en pause »,
le bouton « Reprendre **manuellement** ». Ce « manuellement » est du vocabulaire d'architecture qui
a fui dans l'interface : il décrit une décision technique (pas de réveil à la connexion) dont le
joueur n'a que faire.

**5. Le format de durée ne tient pas la distance.** `InactivityBadge` affiche « Inactif depuis 243h
3min ». Au-delà de quelques heures, il faut des jours.

**6. Le bloc est enterré.** Il est rendu après l'onglet « Vue d'ensemble » et après « Mes jeux »,
au milieu de la page, alors qu'il porte le seul geste qui compte dans cet état.

### Décisions de cadrage (Jean, 2026-08-24)

| Question | Décision |
|---|---|
| Position | **Bandeau en tête**, sous le titre et **avant** la barre d'onglets, comme état de la page et non comme carte parmi d'autres. |
| Suppression | Descend dans l'onglet **Réglages**, qui est déjà réservé au propriétaire. |
| Variante sans sauvegarde | Traitement d'avertissement distinct (orange) **et** confirmation obligatoire. |
| Périmètre | Présentation uniquement. Aucune modification d'API, aucun changement d'autorisation. |

## Acceptance Criteria

### Sécurité de l'action destructrice

1. **« Relancer depuis le début » demande une confirmation explicite** avant d'appeler
   `POST /sessions/{sessionId}/restart`. La boîte de dialogue nomme la conséquence sans détour :
   la progression de **tous** les participants est perdue et la partie repart de zéro, avec la même
   configuration et les mêmes slots.
2. La variante avec sauvegarde, elle, **reste en un clic**. Elle ne détruit rien ; lui imposer une
   confirmation entraînerait le joueur à valider machinalement, et affaiblirait celle du cas 1.
3. Les deux variantes sont visuellement distinctes au premier regard : la variante sans sauvegarde
   emprunte le registre d'avertissement (`--color-warning`), pas le violet primaire.

### Position et hiérarchie

4. Le bandeau est rendu **sous le titre et avant la barre d'onglets**, et reste visible quel que
   soit l'onglet actif. Le seul geste utile d'une run en veille ne dépend pas de l'onglet où l'on se
   trouve.
5. Le bouton « Supprimer la partie » quitte le bloc `IDLE` de la vue d'ensemble pour l'onglet
   **Réglages**. Il reste réservé au propriétaire et conserve sa `DeleteDialog`.
6. Plus de boîte dans une boîte : le bandeau est un seul conteneur. L'explication passe en texte
   courant lisible, pas en `text-muted-foreground` encadré.

### Contenu et vocabulaire

7. Un seul vocabulaire pour l'état, aligné sur le badge de statut existant (`PersonalRunStatusBadge`
   rend « En pause » pour `idle`). Le bandeau, le badge et le bouton disent la même chose.
8. Le mot « manuellement » disparaît de l'interface. Le bouton dit **« Reprendre »**.
9. La durée d'inactivité est lisible au-delà de la journée : minutes, puis heures, puis jours. Elle
   est intégrée au bandeau plutôt que posée sous le titre, pour que la durée et sa conséquence se
   lisent ensemble.
10. Le bandeau dit ce qui s'est passé **et** ce qui va se passer : le serveur s'est arrêté faute
    d'activité, et la reprise recharge la dernière sauvegarde (ou repart de zéro).

### Portée inchangée

11. La carte de reprise reste affichée pour tout participant dont `run.canStart` est vrai, sans
    changement d'autorisation ni de contrat d'API. Les acquis de 16.14 ne bougent pas.
12. Aucun autre état (`draft`, `active`, `starting`, `restarting`, `completed`, `cancelled`) ne
    change d'apparence. À vérifier écran par écran, pas à déduire du code.
13. L'état `restarting` continue d'afficher son indicateur de progression et son garde-fou
    « Bloqué ? Forcer la résolution » (story 17.14).

### Gates

14. `pnpm gates` vert. Tests : rendu du bandeau dans les deux variantes, confirmation exigée sur la
    variante destructrice et pas sur l'autre, formatage de la durée aux trois paliers.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3). `RestartConfirmDialog` sur le chemin `pausedWithoutSave`, sur le modèle
  des `ArchiveDialog` / `DeleteDialog` déjà présents dans la page. Registre d'avertissement sur la
  variante sans sauvegarde.
- [x] **Task 2** (AC 4-6). Extraction du bandeau en composant dédié, remonté avant la barre
  d'onglets. Suppression déplacée vers l'onglet Réglages.
- [x] **Task 3** (AC 7-10). Reprise des libellés et de `InactivityBadge` (paliers de durée,
  intégration au bandeau).
- [x] **Task 4** (AC 11-13). Revue des autres états, écran par écran.
- [x] **Task 5** (AC 14). Tests et gates.

## Dev Notes

- **Tout est dans `frontend/src/features/personal-runs/personal-run-detail-page.tsx`.** Le bloc de
  reprise est la `<section>` gardée par `activeTab === "overview" && run.status === "idle" &&
  run.canStart`, le bouton de suppression est dans le bloc `IDLE` du panneau propriétaire plus bas,
  et `InactivityBadge` est défini dans le même fichier.
- **Ne pas confondre `canStart` et `isOwner`.** 16.14 a créé `canStart` précisément pour ne pas
  élargir `isOwner`, dont dépendent une dizaine d'éléments (onglet Réglages, override de
  configuration, lien d'invitation, renommage, overlay, spoiler). Le bandeau se garde sur
  `canStart`, la suppression sur `isOwner`.
- **`pausedWithoutSave` vient de l'API**, il n'est pas déduit côté client. Il distingue une mise en
  veille propre (sauvegarde écrite) d'un arrêt sans sauvegarde exploitable.
- **La confirmation ne doit pas passer par `window.confirm`.** La page a déjà deux dialogues maison
  cohérents avec le reste du design ; en ajouter un troisième du même moule coûte moins qu'une
  boîte native qui jure.
- **Le bandeau sort de la garde `activeTab === "overview"`.** C'est ce qui le rend visible depuis
  Progression ou Participants, mais cela veut dire que sa mise en page doit tenir au-dessus de la
  barre d'onglets, pas seulement dans la colonne de la vue d'ensemble.
- Aucune API à toucher. `POST /sessions/{sessionId}/restart` et le drapeau `canStart` restent tels
  quels.

## Écart assumé à l'AC 3

L'AC demandait le registre d'avertissement (`--color-warning`) pour la variante destructrice. Or
`--color-warning` et `--color-accent-warm` valent **la même couleur** (`#e89420`, `globals.css`
lignes 123-124), et l'état en veille utilise déjà accent-warm : les deux variantes seraient donc
restées indiscernables, ce que l'AC cherchait précisément à éviter.

La variante sans sauvegarde prend donc le registre `--color-danger`, celui que le reste de la page
emploie déjà pour les actions irréversibles. L'intention de l'AC est tenue, sa lettre non.

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-08-24 | 0.1 | Rédaction de la story après constat en production | Claude |
| 2026-08-24 | 1.0 | Implémentation, vérifiée visuellement sur les deux variantes | Claude |
