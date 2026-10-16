import { selectionErrorMessage } from "./game-selection-errors";

/** Story 38.16: a refused selection names the game and the reason. */
describe("selectionErrorMessage", () => {
  const names: Record<string, string> = { g1: "Beat Saber", g2: "Hollow Knight" };
  const nameOf = (id: string) => names[id] ?? id;

  it("names each refused game with its reason", () => {
    const body = {
      error: {
        code: "validation_failed",
        message: "Sélection invalide.",
        details: { "gameIds.1": ["Le monde Archipelago de ce jeu a échoué au test de génération ; il ne peut pas être ajouté pour le moment."] },
      },
    };

    expect(selectionErrorMessage(body, ["g2", "g1"], nameOf)).toBe(
      "Beat Saber : Le monde Archipelago de ce jeu a échoué au test de génération ; il ne peut pas être ajouté pour le moment.",
    );
  });

  it("falls back to the headline, then to a generic sentence", () => {
    expect(selectionErrorMessage({ error: { message: "Run verrouillée.", details: [] } }, [], nameOf)).toBe("Run verrouillée.");
    expect(selectionErrorMessage(null, [], nameOf)).toBe("Impossible de sauvegarder la sélection.");
  });
});
