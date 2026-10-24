# Story 16.25 - La fanfare de goal moins forte

**Status:** review
**Epic:** 16 - Parties privées
**Date:** 2026-10-24
**Origine :** demande de Jean le 2026-10-24 : le son du goal est beaucoup trop fort.

## Constat

La fanfare de goal (`useChiptune`) sort avec un gain maître de 0.30. L'aperçu du sélecteur (`FanfarePicker`) en a un
autre, 0.22 : l'aperçu ne donne même pas le vrai niveau.

## Critères d'acceptation

1. La fanfare de goal et son aperçu partagent un seul gain maître, `FANFARE_GAIN`, dans `fanfares.ts`.
2. Ce gain vaut 0.12 : 2,5 fois moins fort que la fanfare d'avant, un peu plus de 45 % de l'aperçu d'avant.
3. Rien d'autre ne change (notes, enveloppes, choix de fanfare, coupure par défaut de 16.24, overlay OBS).

## Tâches

- [x] `FANFARE_GAIN` exporté de `fanfares.ts`, utilisé par `use-chiptune.ts` et `fanfare-picker.tsx`.
- [x] Test : le gain partagé reste sous l'ancien niveau.
