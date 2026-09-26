import { showsRunStatusLine, showsSettingsDelete } from "./run-overview-visibility";

/**
 * Un administrateur qui ouvre une partie privée dont il ne fait pas partie n'est ni propriétaire ni
 * participant. Tous les blocs de la vue d'ensemble sont gardés sur l'un ou l'autre, et la ligne
 * d'état qui sert de repli excluait `draft` et `idle`.
 *
 * Résultat : sur une partie en brouillon, sa vue d'ensemble était entièrement vide.
 */
describe("showsRunStatusLine", () => {
  test.each(["draft", "idle", "starting", "active", "completed", "cancelled"] as const)(
    "un tiers la voit quel que soit l'état : %s",
    (status) => {
      expect(showsRunStatusLine(false, false, status)).toBe(true);
    },
  );

  /** Un participant a la carte « mes jeux » sur ces deux états : la ligne ferait doublon. */
  test.each(["draft", "idle"] as const)("un participant ne la voit pas sur %s", (status) => {
    expect(showsRunStatusLine(false, true, status)).toBe(false);
  });

  test("un participant la voit une fois la partie lancée", () => {
    expect(showsRunStatusLine(false, true, "active")).toBe(true);
  });

  test.each(["draft", "active"] as const)("le propriétaire ne la voit jamais : %s", (status) => {
    expect(showsRunStatusLine(true, true, status)).toBe(false);
  });
});

/**
 * Story 16.20 : une partie terminée ne se supprime pas, sauf sur une seed importée - son suivi est
 * partiel par nature, et la supprimer laisse la session dont les stats de profil sont calculées.
 */
describe("showsSettingsDelete", () => {
  test("une partie en veille se supprime", () => {
    expect(showsSettingsDelete("idle", false)).toBe(true);
  });

  test("une partie terminée sur une seed importée se supprime", () => {
    expect(showsSettingsDelete("completed", true)).toBe(true);
  });

  test("une partie terminée générée sur le site reste", () => {
    expect(showsSettingsDelete("completed", false)).toBe(false);
  });

  test.each(["draft", "starting", "active", "stopping", "restarting", "cancelled"] as const)(
    "pas depuis les réglages : %s",
    (status) => {
      expect(showsSettingsDelete(status, true)).toBe(false);
    },
  );
});
