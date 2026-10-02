import { renderToStaticMarkup } from "react-dom/server";

import { DisabledGameBanner } from "./game-detail";

/** Story 11.5: the page of a disabled game says why it cannot be picked. */
describe("DisabledGameBanner", () => {
  test("shows the admin's message", () => {
    const html = renderToStaticMarkup(<DisabledGameBanner message="Apworld en réparation." />);

    expect(html).toContain("Ce jeu est temporairement désactivé.");
    expect(html).toContain("Apworld en réparation.");
  });

  test("falls back to a generic line without a message", () => {
    expect(renderToStaticMarkup(<DisabledGameBanner message={null} />)).toContain("nouvelle partie");
    expect(renderToStaticMarkup(<DisabledGameBanner message="   " />)).toContain("nouvelle partie");
  });
});
