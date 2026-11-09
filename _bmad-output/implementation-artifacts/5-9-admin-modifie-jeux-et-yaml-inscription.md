# Story 5.9: L'admin modifie les jeux et le YAML d'une inscription

**Status:** review
**Epic:** 5 - Administration des inscriptions
**Date:** 2026-11-09
**Origine :** demande de Jean le 2026-11-09 : « j'aimerais que les admins puissent modifier les inscriptions des
joueurs (joueurs/yaml editor) ».

## Story

En tant qu'admin d'un événement,
je veux modifier les jeux d'une inscription et le YAML de chacun de ses slots depuis le détail admin,
afin de corriger une inscription à la place du joueur (erreur de config, inscription close, jeu à remplacer).

## Contexte (existant)

- Une inscription appartient à un joueur (`userId`) et porte des `gameSlots` ordonnés (JSON), chacun
  `{slotId, gameId, slotOrder, apworldHash?, playerYaml?, needsReview?}`. Un même jeu peut occuper plusieurs slots.
  Le nom de slot (`name:` du YAML) est validé par `RegistrationGameSelection::slotNameError`.
- Côté joueur : `PUT /api/v1/registrations/{id}/game-selection` (verrouillé à `registrationClosesAt`) et
  `PUT /api/v1/registrations/{id}/slots/{slotId}/yaml` (propriétaire + `isReserved`, apworld prêt, nom valide,
  `Registration::submitSlotPlayerYaml`).
- Côté admin (story 5.4) : `PATCH /api/v1/admin/events/{eventId}/registrations/{registrationId}`
  (`AdminRegistrationModification::update`, `{slots:[{gameId}]}`, sans verrou de date, `diffSlots` garde les slots
  existants et leur YAML). **Aucun écran ne l'appelle.** Aucune route admin n'enregistre un YAML.
- Front : `features/events/yaml-option-editor.tsx` (`YamlOptionEditor`) accepte déjà `saveUrl` ; le détail admin
  `features/admin/admin-registration-detail.tsx` montre le YAML en lecture seule (`GameCard`, story 5.8).

## Décisions (par défaut, à valider par Jean)

1. **L'admin passe outre la date de clôture** des inscriptions (c'est son rôle de filet de sécurité), pour les jeux
   comme pour le YAML. Une inscription annulée reste non modifiable (409 `inactive_registration`).
2. **Mêmes validations que le joueur** : jeux autorisés par l'event, apworld prêt, nom de slot valide.
3. **Pas de notification automatique** au joueur ; l'admin a déjà « Envoyer un message » (story 5.6) s'il veut
   prévenir. La trace est le journal PSR-3 (`admin.registrations.slot_yaml_update`, avec l'admin).
4. Une modification après la génération de la partie n'a pas d'effet tant que la partie n'est pas regénérée :
   hors périmètre, l'écran ne bloque rien.

## Critères d'acceptation

1. `PUT /api/v1/admin/events/{eventId}/registrations/{registrationId}/slots/{slotId}/yaml` (`{yaml}`), réservé aux
   admins, enregistre le YAML du slot sans contrôle de propriétaire ni de date : 200 ; 404 si l'event, l'inscription
   (ou une inscription d'un autre event) ou le slot n'existe pas ; 409 `inactive_registration` si annulée ; 422 si
   l'apworld n'est pas prêt ou si le nom de slot est invalide (mêmes messages que côté joueur). Le slot perd son
   `needsReview` comme côté joueur.
2. Le détail admin d'une inscription active propose « Modifier les jeux » : ajouter un slot (jeu parmi ceux de
   l'event), retirer un slot, puis enregistrer via le `PATCH` existant. Les slots conservés gardent leur YAML ;
   l'écran se recharge avec la réponse.
3. Chaque carte de slot propose « Modifier le YAML » : ouvre `YamlOptionEditor` sur ce slot avec `saveUrl` pointant
   la route admin, édition ouverte même inscriptions closes ; après enregistrement la carte montre le nouveau YAML.
4. Une inscription annulée n'affiche aucun bouton de modification.
5. Le joueur voit les changements dans son inscription (aucune donnée séparée).
6. Tests : fonctionnels API (admin OK après clôture, non-admin 403, slot inconnu 404, autre event 404, annulée 409,
   nom invalide 422, apworld absent 422) ; jest (boutons absents si annulée, édition des jeux appelle le PATCH,
   éditeur appelé avec la route admin).

## Tâches

- [x] API : `AdminSlotYamlModification` (Application/Command, résultat typé, pas de tableau brut) réutilisant
  `slotNameError` (l'extraire en support partagé si besoin) et `submitSlotPlayerYaml` ; route dans
  `AdminRegistrationController` ; journal PSR-3.
- [x] API : tests fonctionnels.
- [x] Front : client API admin (PATCH jeux, PUT YAML) avec garde de type de la réponse.
- [x] Front : mode édition des jeux dans `admin-registration-detail.tsx` (liste des jeux de l'event).
- [x] Front : bouton « Modifier le YAML » par slot, `YamlOptionEditor` avec `saveUrl` admin.
- [x] Front : tests jest.
- [x] Gates verts (`./scripts/gates.sh`).

## Dev Agent Record

### Implémentation

- API : `AdminSlotYamlModification` (Command, `void`, échecs typés) et route
  `PUT /api/v1/admin/events/{eventId}/registrations/{registrationId}/slots/{slotId}/yaml` (corps `{playerYaml}`, comme
  côté joueur). Journal `admin.registrations.slot_yaml_update`.
- API : `GET /api/v1/admin/events/{eventId}/registrations/{registrationId}/game-selection`, même charge que la route
  joueur (`RegistrationGameSelection::getSelectionForAdmin`), sans contrôle de propriétaire et toujours ouverte :
  l'éditeur YAML et le choix des jeux en ont besoin (options, jeux de l'event, plafond).
- `SlotYamlName::error` (Application/Support) : contrôle du nom de slot partagé entre le joueur et l'admin.
- Front : `admin-registration-editor.tsx` (`AdminGamesEditor`, `AdminSlotYamlEditor`), branché dans
  `admin-registration-detail.tsx` (rechargement du détail après enregistrement) ; `fetchGameSelection` et
  `fetchSlotYamlData` acceptent l'URL admin.
- Le `PATCH` existant (story 5.4) n'a pas changé : il ne vérifie pas les jeux désactivés, le front ne les propose pas.

### Tests

- `tests/Functional/AdminSlotYamlModificationTest.php` (11 tests).
- `admin-registration-edit.test.tsx` (7 tests).
- Gates : api `composer gates:fast` vert (3609 tests), front typecheck, lint, test (1434), build verts.

### Non fait

- Vérification visuelle dans l'app.
