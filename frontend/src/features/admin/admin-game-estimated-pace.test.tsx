import { renderToStaticMarkup } from "react-dom/server";

import { EstimatedPaceSection, MAX_ESTIMATED_CHECKS_PER_HOUR, parseEstimatedPace } from "./admin-game-editor";
import type { AdminGame } from "./admin-games-api";

/** Story 56.2: the admin's pace estimate on the game's sheet. */
describe("estimated pace field", () => {
  test("an empty field clears the estimate, a whole number in bounds is kept", () => {
    expect(parseEstimatedPace("")).toBeNull();
    expect(parseEstimatedPace("  ")).toBeNull();
    expect(parseEstimatedPace(" 60 ")).toBe(60);
    expect(parseEstimatedPace(String(MAX_ESTIMATED_CHECKS_PER_HOUR))).toBe(MAX_ESTIMATED_CHECKS_PER_HOUR);
  });

  test("anything else is refused before it reaches the API", () => {
    expect(parseEstimatedPace("0")).toBe("invalid");
    expect(parseEstimatedPace("12.5")).toBe("invalid");
    expect(parseEstimatedPace("-3")).toBe("invalid");
    expect(parseEstimatedPace("beaucoup")).toBe("invalid");
    expect(parseEstimatedPace(String(MAX_ESTIMATED_CHECKS_PER_HOUR + 1))).toBe("invalid");
  });

  test("the field shows the stored estimate", () => {
    const game = { id: "g1", estimatedChecksPerHour: 45 } as AdminGame;
    const html = renderToStaticMarkup(<EstimatedPaceSection game={game} onUpdate={() => {}} />);

    expect(html).toContain("Checks par heure estimés");
    expect(html).toContain('value="45"');
  });

  test("a game without an estimate shows an empty field", () => {
    const html = renderToStaticMarkup(<EstimatedPaceSection game={{ id: "g1" } as AdminGame} onUpdate={() => {}} />);

    expect(html).toContain('value=""');
  });
});
