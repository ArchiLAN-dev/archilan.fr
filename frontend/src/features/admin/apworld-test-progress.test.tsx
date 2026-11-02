import { runningTestLabel } from "./apworld-test-progress";

/** Story 38.33: the running test says since when it generates, once the clock has been read. */
describe("runningTestLabel", () => {
  test("before the first tick it says the test runs", () => {
    expect(runningTestLabel(null)).toBe("Test de génération en cours");
  });

  test("under a minute it does not say zero", () => {
    expect(runningTestLabel(0)).toBe("Test de génération en cours depuis moins d'une minute");
  });

  test("then it counts the minutes", () => {
    expect(runningTestLabel(3)).toBe("Test de génération en cours depuis 3 min");
  });
});
