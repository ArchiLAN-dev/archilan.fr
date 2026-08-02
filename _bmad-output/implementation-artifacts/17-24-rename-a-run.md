# Story 17.24 - Renommer une partie

## Status

Done

## Story

**En tant que** propriétaire d'une partie,
**je veux** changer son titre après coup,
**afin de** ne pas rester coincé avec un nom générique ou une faute de frappe.

## Contexte

Le titre d'une partie était fixé à la création et **jamais modifiable** : aucune méthode de domaine,
aucun endpoint. Le seul `rename()` du contexte concernait les modèles YAML.

La story 17.23 a rendu le problème visible : une partie créée depuis la page d'un jeu est nommée
automatiquement « Partie {jeu} ». Son AC5 affirmait qu'elle « reste renommable ensuite comme
n'importe quelle autre » - c'était faux, aucune partie ne l'était.

## Acceptance Criteria

**AC1 - Le propriétaire peut renommer sa partie** depuis son en-tête, sans quitter la page.

**AC2 - Mêmes règles qu'à la création** : titre requis, 80 caractères maximum. Une partie ne peut
pas être renommée en quelque chose que le formulaire de création aurait refusé.

**AC3 - Propriétaire uniquement.** Un participant non propriétaire reçoit 403, une partie inconnue
404. Le crayon n'est pas affiché à qui ne peut pas renommer.

**AC4 - Autorisé à tout statut**, y compris terminal. Un titre est une étiquette, pas de la
configuration : la règle de lecture seule que porte `isTerminal()` couvre les invitations et les
overrides de configuration, c'est-à-dire ce qui changerait ce que la partie *a été*.

**AC5 - Quality gates.** `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] Task 1: `Run::rename()` - méthode métier nommée, pas de setter public (AC-D5, AC4).
- [x] Task 2: `PersonalRunDrafts::rename()` avec les règles de titre de la création (AC2, AC3).
- [x] Task 3: `PUT /api/v1/runs/{runId}/title` (AC1, AC3).
- [x] Task 4: composant `RunTitle` - édition en place, Entrée valide, Échap annule (AC1, AC3).
- [x] Task 5: tests - renommage, règles de titre, non-propriétaire, partie inconnue (AC2, AC3).
- [x] Task 6: gates (AC5).

## Dev Notes

**Pourquoi `PUT /runs/{runId}/title` et pas `PATCH /runs/{runId}`.** Le contexte expose déjà ses
modifications ciblées en sous-ressources (`/recap-visibility`, `/participants/me/games`,
`/slots/{id}/yaml`). Un `PATCH` sur la ressource entière aurait introduit une seconde convention
pour un seul champ.

**Pourquoi renommer reste permis sur une partie terminée.** `isTerminal()` documente un
verrouillage « lecture seule pour le propriétaire », mais ce qu'il protège réellement, ce sont les
invitations et les overrides de configuration - des choses qui réécriraient l'histoire de la partie.
Renommer « Partie Luigi's Mansion » en quelque chose de mémorable trois mois plus tard ne change
rien à ce qui s'est joué, et c'est précisément quand on relit ses vieilles parties qu'on en a envie.

**Validation dupliquée avec la création, volontairement.** Les deux chemins appliquent la même règle
(requis, 80 caractères) écrite deux fois dans le même service. Extraire un validateur pour deux
`if` aurait coûté plus en indirection qu'en sécurité ; si une troisième règle apparaît, il faudra
extraire.
