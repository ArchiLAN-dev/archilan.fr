# Story 56.3 - Génération à blanc et rapport d'équilibre

**Status:** review
**Epic:** 56 - Équilibrage des parties (`_bmad-output/planning-artifacts/epics/epic-56-equilibrage.md`)
**Date:** 2026-11-21
**Issue:** #1176
**Dépend de:** 56.1, 56.2.

## Story

En tant qu'admin d'un event (ou propriétaire d'une partie privée),
je veux générer la partie entière sans la lancer et voir un rapport d'équilibre,
afin de corriger un monde trop grand avant le jour J.

## Décisions

1. **Génération à blanc** : même chemin que la génération d'une session (YAML enregistrés ou YAML par défaut des
   jeux, apworlds servis, fichiers requis), sans serveur ni session créée. Le résultat (mesures de 56.1, spoiler)
   est gardé avec sa date, et le dernier remplace le précédent.
2. **Rapport**, dans le hub admin de l'event (section « Équilibre », story 3.17) et sur la page d'une partie privée
   pour son propriétaire. Par slot : joueur, jeu, taille, durée estimée et sa provenance, écart à la médiane, part de
   progression des autres hébergée et part équitable.
3. **Alertes** (aucun blocage, cadrage de l'épic) :
   - durée estimée supérieure à 2 fois la médiane de la partie ;
   - durée estimée supérieure à la durée cible (56.5 ; tant qu'elle n'existe pas, durée de l'event moins 20 %) ;
   - part hébergée supérieure à 2 fois la part équitable, ou supérieure à 50 % de la progression des autres.
4. Le rapport dit sur quels YAML il porte. S'il ne correspond plus à l'état des inscriptions (YAML modifié ou
   inscription ajoutée après la génération), il s'affiche « à refaire ».
5. Le joueur voit la ligne de son propre slot dans son espace inscrit (épic 53), sans les autres joueurs.

## Critères d'acceptation

1. Un admin lance une génération à blanc d'un event et obtient le rapport, sans session créée ni serveur lancé.
2. Les alertes se déclenchent aux seuils décrits, et un slot sans rythme connu affiche sa taille et « durée
   inconnue ».
3. Une inscription ou un YAML modifié après la génération rend le rapport « à refaire ».
4. Le propriétaire d'une partie privée voit le même rapport sur sa partie.
5. Un joueur voit sa propre ligne, et pas celles des autres.

## Tests et gates

6. Fonctionnels : génération à blanc (passerelle simulée), seuils d'alerte, rapport périmé, droits de lecture. Front :
   tableau, alertes, état périmé, ligne du joueur.
7. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** - Orchestrateur : génération sans lancement (option du chemin existant). Aucun changement : `configure` puis `generate` ne lancent jamais de serveur, il suffit d'une session orchestrateur à part (`balance-{scope}-{id}`).
- [x] **Task 2** - API : commande `RunBalanceDryGeneration`, stockage du dernier résultat, requête du rapport.
- [x] **Task 3** - Front : section « Équilibre » du hub admin, rapport d'une partie privée, ligne dans l'espace
  inscrit.
- [x] **Task 4** - Tests et gates.

## Dev Notes

- Les tests de slot (11.11) génèrent un slot seul : ils ne disent rien de la part de progression, qui n'existe qu'à
  plusieurs. D'où la génération de la partie entière.
- Mémoire `apworld_required_files` : un jeu qui demande une ROM à la génération ne peut pas être généré à blanc sans
  elle ; le rapport le dit plutôt que d'échouer.

## Dev Agent Record

### Fichiers

- API :
  - `Sessions/Domain/Entity/BalanceReport` (table `balance_report`, migration `Version20261123100000`) : un rapport par
    event ou partie privée (`event-{id}`, `run-{id}`), le dernier remplace le précédent ; slots envoyés, empreinte des
    entrées, mesures, statut (`generating`, `done`, `failed`, `needs_files`).
  - `Application/Service/BalanceInputs` : les slots lus comme la vraie génération (YAML enregistré ou YAML par
    défaut, apworld courant) et leur empreinte (joueur, jeu, apworld, sha1 du YAML), qui rend le rapport « à
    refaire ».
  - `Application/Command/RunBalanceDryGeneration` : `configure` puis `generate` sur la session orchestrateur
    `balance-{scope}-{id}`, avec les réglages de génération du périmètre (plafond 56.6 compris). Pas de session API.
    Un jeu qui lit un fichier à la génération donne `needs_files` sans rien générer. Une génération en cours bloque
    une nouvelle demande pendant une heure au plus.
  - `Application/Command/RecordBalanceDryGeneration` + `MeasureBalanceReportJob` : le webhook (`session.generated`,
    `session.crashed`) garde la clé de sortie et met la mesure en file sur le worker, qui lit la multidata comme 56.1.
  - `Application/Query/BalanceReportQuery` : lignes, médiane, cible (durée de l'event moins 20 % jusqu'à 56.5,
    aucune pour une partie privée), alertes `duration_over_median`, `duration_over_target`, `hosted_over_fair`,
    `hosted_over_half` (part hébergée rapportée à la progression appartenant aux autres slots).
  - `Presentation/Controller/BalanceReportController` :
    - `GET|POST /api/v1/admin/events/{eventId}/balance` (admin) ;
    - `GET|POST /api/v1/runs/{runId}/balance` (propriétaire, co-organisateur 16.27, admin) ;
    - `GET /api/v1/events/{eventId}/my-balance` (inscrit : ses lignes seulement, sans l'erreur de génération qui peut
      nommer les autres).
- Front :
  - `features/personal-runs/balance-api.ts` et `balance-report.tsx` (`BalanceReportView` pur, `BalancePanel` qui
    relit toutes les 5 s pendant la génération) ;
  - page `/admin/evenements/{id}/equilibre`, onglet et entrée « Équilibre » du hub (section Jour J) ;
  - section « Équilibre de la partie » sur une partie privée en brouillon, pour qui la pilote ;
  - « Équilibre de mon monde » dans l'espace inscrit, une fois le rapport fait.

### Tests

- `tests/Functional/BalanceReportTest.php` : génération à blanc d'un event (slots envoyés, refus pendant la
  génération), webhook et mesure, parts et alerte d'hébergement, rapport périmé après un YAML, seuils de durée et
  slot sans rythme, plantage, ROM, lecture par un joueur et droits sur une partie privée.
- `frontend/src/features/personal-runs/balance-report.test.tsx` : lecture de la réponse, tableau, alertes, état
  périmé, durée inconnue, ROM.
