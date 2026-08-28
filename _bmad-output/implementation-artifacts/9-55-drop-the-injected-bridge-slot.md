# Story 9.55: Supprimer le slot observateur `Bridge` injecte dans chaque partie generee

**Status:** draft
**Epic:** 9 - Archipelago session management
**Date:** 2026-08-28
**Leve un heritage de :** [9.12](9-12-bridge-py-realtime-observer-service.md) (conception d'origine
du bridge) et de l'epic 26 (commit initial de l'orchestrateur).
**Rendu possible par :** [16.18](16-18-import-d-une-seed-generee-ailleurs.md), qui a fait sauter le
verrou sans retirer le slot.

## Story

En tant qu'**equipe qui maintient la plateforme**,
je veux **que le bridge s'attache au premier slot reel au lieu d'un slot fantome injecte**,
afin de **supprimer un joueur qui n'en est pas un, et le cas particulier qu'il traine partout**.

## Context

Chaque partie que nous generons contient un slot de plus que de joueurs. L'orchestrateur ecrit un
`yamls/_bridge_observer.yaml` dans l'archive de generation :

```go
// Inject the Bridge observer slot so the bridge WS client can connect to the AP server.
// The Archipelago game type is a TextOnly spectator slot; it needs explicit game options
// so that Generate.roll_settings does not raise "No game options found".
bridgeYaml := []byte("name: Bridge\ngame: Archipelago\n...")
```

Le nom du fichier trie avant tout nom de joueur, donc **ce slot est le numero 1** et les vrais
joueurs commencent a 2.

### Ce n'etait pas la conception d'origine

La story 9.12, premiere specification du bridge, disait le contraire :

> « Bridge.py connects to `ws://localhost:38281` as a TextOnly Archipelago client (slot type
> `TextOnly`, **no game slot**, no password required for observer). »

La bifurcation n'est documentee nulle part. Elle apparait directement dans le commit initial de
l'orchestrateur, et le commentaire ci-dessus la trahit : le « so that `roll_settings` does not raise »
est la trace d'une correction empirique, pas d'une decision pesee. Quelqu'un a constate que la
connexion ne passait pas, a injecte un slot, puis a du lui donner des options de jeu explicites pour
que la generation l'accepte.

### Le verrou a saute en 16.18, le slot est reste

Une seed importee ne peut pas recevoir de slot injecte : elle est generee ailleurs. La story 16.18 a
donc du faire fonctionner le bridge **sur le slot d'un vrai joueur**, et c'est ce qui tourne
aujourd'hui en production pour ces parties. Deux mecanismes en sont sortis :

- `bridge/core/config.py` lit `SLOT_NAMES`, que l'orchestrateur remplit desormais avec le vrai
  roster ; `ap_client.py` s'y connecte via `slot_names[0]`, avec `"Bridge"` en simple repli.
- La presence se calcule sur `Join`/`Part` en excluant les tags `TextOnly`, `Tracker`, `HintGame`.
  Le commentaire du code est explicite : ce filtrage est « what keeps it from marking the slot it
  attaches to as occupied - which is what happens on an imported seed ».

Autrement dit : **le chemin que cette story veut generaliser est deja ecrit, teste et en
production.** Le slot injecte ne subsiste que sur les seeds que nous generons, par inertie.

## Ce qui depend du slot injecte

Recensement fait le 2026-08-28. Il est court.

| Endroit | Ce qu'il fait |
|---------|---------------|
| `orchestrateur/internal/service/service.go` (~l.509) | ecrit `_bridge_observer.yaml` dans l'archive |
| `bridge/core/ap_client.py` (~l.688) | `slot_names[0]`, repli `"Bridge"` / `"Archipelago"` |
| `frontend/.../overlay/overlay-api.ts` (`isRealPlayerSlot`) | exclut le slot par **trois** criteres : `slot_type`, jeu `Archipelago`, nom `bridge` |

**L'API ne filtre rien** : elle adresse les slots par index et resout les proprietaires **par nom de
slot** (`slotOwnersQuery`). Le slot fantome ne remonte donc pas dans les recaps, le classement ni les
succes - il n'a jamais eu de proprietaire.

## Acceptance Criteria

**AC1 - Plus d'injection.** L'orchestrateur cesse d'ecrire `_bridge_observer.yaml`. Une partie
generee contient exactement autant de slots que de joueurs.

**AC2 - Le bridge s'attache au premier slot reel.** Il utilise `SLOT_NAMES`, comme il le fait deja
pour une seed importee. Le repli litteral `"Bridge"` disparait : un roster vide est une erreur de
configuration qui doit se voir, pas se contourner en silence.

**AC3 - La presence reste juste.** Le slot auquel le bridge s'attache n'est pas compte comme joue
tant qu'aucun client de jeu ne le tient. C'est deja le comportement (filtrage par tags) ; l'AC
demande de le **prouver sur une partie generee**, pas seulement importee.

**AC4 - Aucun privilege permanent sur le joueur #1.** La connexion permanente n'agit jamais sur les
indices. Les indices payants continuent de passer par la connexion temporaire « en tant que slot N »
(stories 9.30/9.32), y compris pour le slot auquel le bridge est attache - sinon ce joueur serait le
seul a avoir un client habilite en permanence sur ses indices.

**AC5 - Le filtre d'overlay se simplifie sans se casser.** `isRealPlayerSlot` perd ses criteres
devenus sans objet. `slot_type` reste : les slots de **groupe** (item links) existent independamment
du bridge et doivent toujours etre exclus. Le critere par nom `bridge` disparait, celui par jeu
`Archipelago` aussi - il exclurait a tort un joueur qui joue reellement le world `Archipelago`.

**AC6 - Les parties en cours ne cassent pas.** Une session lancee avant le deploiement garde son
slot injecte et continue de fonctionner : le bridge lit le roster, il ne suppose rien. Verifie
explicitement, parce que le deploiement se fait sans arreter les parties en cours.

**AC7 - La numerotation change, et c'est assume.** Les nouvelles parties numerotent leurs joueurs a
partir de 1 au lieu de 2. Recenser ce qui persiste un index de slot (feed, recaps, indices,
reachability) et verifier qu'aucun ne compare des index entre parties.

**AC8 - Gates.** Gates de l'orchestrateur, du bridge (`ruff`, `pytest`, `mypy`) et du frontend.

## Tasks / Subtasks

- [ ] **Task 1** (AC7). Recenser les persistances d'index de slot avant toute modification. Si l'une
      compare des index entre parties, la story s'arrete et devient un prealable.
- [ ] **Task 2** (AC1). Orchestrateur : retirer l'injection, et le test qui l'attend.
- [ ] **Task 3** (AC2, AC4). Bridge : retirer le repli litteral, echouer clairement sur roster vide,
      verifier que la connexion permanente ne touche pas aux indices.
- [ ] **Task 4** (AC5). Frontend : simplifier `isRealPlayerSlot` en gardant l'exclusion des slots de
      groupe.
- [ ] **Task 5** (AC3, AC6). Verification sur un banc : une partie generee neuve, et une partie
      lancee avant le changement.
- [ ] **Task 6** (AC8). Gates verts sur les trois depots.

## Dev Notes

- **Le gain est un cas particulier en moins, pas une performance.** Un slot `Archipelago` ne coute
  rien au pool d'objets. Ce qu'on supprime, c'est « le joueur #1 n'est pas un joueur » - une regle
  qu'il faut se rappeler a chaque endroit qui compte des joueurs, et qu'on oublie tot ou tard.
- **Ne pas confondre avec la connexion sans slot.** La story 9.12 imaginait un client sans slot du
  tout ; Archipelago valide le `name` du paquet `Connect` contre le roster et refuse un nom inconnu
  (`ConnectionRefused: ["InvalidSlot"]`, cf. `docs/archipelago-web-clients.md`). Le `TextOnly` est un
  tag de comportement, pas un mode d'authentification. Cette story ne ressuscite pas cette idee :
  elle generalise le partage d'un slot reel, qui lui fonctionne.
- **Le repli `"Bridge"` est un piege a garder en tete pendant la migration.** Tant qu'il existe, un
  `SLOT_NAMES` vide passe inapercu et le bridge se connecte a un slot qui n'existe plus. C'est
  pourquoi l'AC2 demande sa suppression plutot que sa conservation « au cas ou ».

### Project Structure Notes

- `orchestrateur/internal/service/service.go` (injection), `internal/docker/client.go` (`SlotNames`).
- `bridge/core/ap_client.py` (connexion, presence), `bridge/core/config.py` (`SLOT_NAMES`).
- `frontend/src/features/overlay/overlay-api.ts` (`isRealPlayerSlot`).

### References

- [Source: _bmad-output/implementation-artifacts/9-12-bridge-py-realtime-observer-service.md (la conception d'origine, sans slot)]
- [Source: _bmad-output/implementation-artifacts/16-18-import-d-une-seed-generee-ailleurs.md (le verrou leve, section « Le vrai chantier etait la presence »)]
- [Source: _bmad-output/implementation-artifacts/9-30-paid-hint-via-connect-as-slot.md (la connexion temporaire en tant que slot)]
- [Source: docs/archipelago-web-clients.md (`ConnectionRefused: ["InvalidSlot"]`)]

## Change Log

| Date       | Change |
|------------|--------|
| 2026-08-28 | Creee. Le slot observateur injecte est un heritage : la conception d'origine (9.12) n'en prevoyait pas, la bifurcation n'est documentee que par un commentaire de code, et 16.18 a leve le verrou sans retirer le slot. Status: draft. |
