import { renderToStaticMarkup } from "react-dom/server";
import { Shield } from "lucide-react";

import { SHEET_SECTIONS, SheetNav, SheetSection } from "./admin-sheet-section";

/**
 * Story 36.7. The admin user sheet reads as distinct panels: each one is a section separated from the
 * previous by a rule, with its title and help in their own column, and the header lists them all.
 */
describe("SheetSection", () => {
  test("is an anchored, labelled section separated from the previous one", () => {
    const html = renderToStaticMarkup(
      <SheetSection description="Sanctions et échanges." icon={Shield} id="moderation" title="Modération">
        <p>contenu</p>
      </SheetSection>,
    );

    expect(html).toMatch(/<section[^>]*id="moderation"/);
    expect(html).toMatch(/<section[^>]*aria-labelledby="moderation-title"/);
    expect(html).toMatch(/<h2[^>]*id="moderation-title"[^>]*>.*Modération<\/h2>/);
    expect(html).toContain("Sanctions et échanges.");
    expect(html).toMatch(/<section[^>]*class="[^"]*border-t[^"]*"/);
    expect(html).toContain("<p>contenu</p>");
  });
});

describe("SheetNav", () => {
  test("links every panel of the sheet, in order", () => {
    const html = renderToStaticMarkup(<SheetNav />);

    expect(SHEET_SECTIONS.map((section) => section.id)).toEqual([
      "identite",
      "acces",
      "moderation",
      "adhesion",
      "inscriptions",
      "jeu",
      "pelles",
      "journal",
    ]);
    for (const section of SHEET_SECTIONS) {
      expect(html).toContain(`href="#${section.id}"`);
      expect(html).toContain(section.label.replace("'", "&#x27;"));
    }
    expect(html).toContain('aria-label="Sections de la fiche"');
  });
});
