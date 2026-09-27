import { overrideIsActive } from "./apworld-preflight-override";
import type { ApworldPreflight } from "./admin-games-api";

function verdict(status: ApworldPreflight["status"], overridden: boolean): ApworldPreflight {
  return { status, error: "", checkedAt: "", overridden, blocks: status === "failed" && !overridden };
}

/**
 * Story 38.10 : une dérogation autorise un jeu malgré un test échoué. La page affichait « Dérogation
 * active : le jeu reste sélectionnable malgré le verdict » sous un test réussi, ce qui ne voulait rien
 * dire - une version forcée pendant son test gardait sa dérogation après l'avoir passé.
 */
describe("overrideIsActive", () => {
  test("une dérogation sur un test échoué est active", () => {
    expect(overrideIsActive(verdict("failed", true))).toBe(true);
  });

  test.each(["passed", "pending", "skipped"] as const)("rien à autoriser sur un test %s", (status) => {
    expect(overrideIsActive(verdict(status, true))).toBe(false);
  });

  test("sans dérogation, rien d'actif", () => {
    expect(overrideIsActive(verdict("failed", false))).toBe(false);
    expect(overrideIsActive(null)).toBe(false);
  });
});
