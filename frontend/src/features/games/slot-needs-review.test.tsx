import { renderToStaticMarkup } from "react-dom/server";

import { parseNeedsReview, SlotNeedsReview } from "./slot-needs-review";

/** Story 38.7: a slot whose YAML no longer holds after its game switched apworld is shown "à revoir". */
describe("SlotNeedsReview", () => {
  test("says the game changed version and lists what no longer holds", () => {
    const html = renderToStaticMarkup(
      <SlotNeedsReview reasons={["« goal » : la valeur « moon » n'est plus acceptée.", "« removed » n'existe plus dans cette version."]} />,
    );

    expect(html).toContain("À revoir");
    expect(html).toContain("Ce jeu a changé de version");
    expect(html).toContain("la valeur « moon » n&#x27;est plus acceptée.");
    expect(html).toContain("« removed » n&#x27;existe plus dans cette version.");
  });

  test("renders nothing for a slot that holds", () => {
    expect(renderToStaticMarkup(<SlotNeedsReview reasons={[]} />)).toBe("");
  });
});

describe("parseNeedsReview", () => {
  test("keeps the reasons of a slot to review", () => {
    expect(parseNeedsReview({ needsReview: ["a", "b"] })).toEqual(["a", "b"]);
  });

  test("reads a slot without the field, or with a malformed one, as nothing to review", () => {
    expect(parseNeedsReview({})).toEqual([]);
    expect(parseNeedsReview({ needsReview: "a" })).toEqual([]);
    expect(parseNeedsReview({ needsReview: ["a", 3, null] })).toEqual(["a"]);
    expect(parseNeedsReview(null)).toEqual([]);
  });
});
