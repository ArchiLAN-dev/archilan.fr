import { renderToStaticMarkup } from "react-dom/server";
import { Shield } from "lucide-react";

import { SHEET_SECTIONS, SHEET_TABS, SheetPager, SheetSection, SheetTabPanel, SheetTabs, resolveSheetTab, sheetPage } from "./admin-sheet-section";

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

/** Story 36.9: the sheet's long lists page by five. */
describe("sheetPage", () => {
  const items = Array.from({ length: 12 }, (_, i) => i);

  test("slices a page and counts the pages", () => {
    expect(sheetPage(items, 1)).toEqual({ rows: [0, 1, 2, 3, 4], page: 1, pages: 3 });
    expect(sheetPage(items, 3)).toEqual({ rows: [10, 11], page: 3, pages: 3 });
  });

  test("clamps a page past the end, so a list that shrinks never shows an empty page", () => {
    expect(sheetPage(items, 9).page).toBe(3);
    expect(sheetPage([], 2)).toEqual({ rows: [], page: 1, pages: 1 });
  });

  test("the pager disables the way out at both ends", () => {
    const first = renderToStaticMarkup(<SheetPager label="Pages" onPage={() => {}} page={1} pages={3} />);
    expect(first).toMatch(/<button[^>]*disabled=""[^>]*>.*Précédent/);
    expect(first).toContain("Page 1 sur 3");
    expect(renderToStaticMarkup(<SheetPager label="Pages" onPage={() => {}} page={1} pages={1} />)).toBe("");
  });
});

/** Story 36.8: the sheet in five tabs, the open one kept in the address. */
describe("SheetTabs", () => {
  test("every panel belongs to exactly one tab, in page order", () => {
    expect(SHEET_TABS.map((tab) => tab.id)).toEqual(["compte", "moderation", "association", "jeu", "journal"]);
    expect(SHEET_TABS.flatMap((tab) => tab.sections)).toEqual(SHEET_SECTIONS.map((section) => section.id));
  });

  test("the open tab comes from the address, else from an old anchor, else « Compte »", () => {
    expect(resolveSheetTab("moderation", "")).toBe("moderation");
    expect(resolveSheetTab("jeu", "#adhesion")).toBe("jeu");
    expect(resolveSheetTab(null, "#pelles")).toBe("jeu");
    expect(resolveSheetTab(null, "#inscriptions")).toBe("association");
    expect(resolveSheetTab(null, "")).toBe("compte");
    expect(resolveSheetTab("inconnu", "#nulle-part")).toBe("compte");
  });

  test("an accessible tab bar: the open tab selected and focusable, the others reachable by arrows", () => {
    const html = renderToStaticMarkup(<SheetTabs active="association" onSelect={() => {}} />);

    expect(html).toContain('role="tablist"');
    expect(html).toContain('aria-label="Sections de la fiche"');
    for (const tab of SHEET_TABS) expect(html).toContain(`aria-controls="sheet-panel-${tab.id}"`);
    expect(html).toMatch(/aria-selected="true"[^>]*id="sheet-tab-association"[^>]*tabindex="0"/);
    expect(html).toMatch(/aria-selected="false"[^>]*id="sheet-tab-compte"[^>]*tabindex="-1"/);
    expect(html).toContain("Jeu et pelles");
  });

  test("the panel is labelled by its tab", () => {
    const html = renderToStaticMarkup(
      <SheetTabPanel tab="journal">
        <p>contenu</p>
      </SheetTabPanel>,
    );

    expect(html).toMatch(/role="tabpanel"/);
    expect(html).toContain('aria-labelledby="sheet-tab-journal"');
    expect(html).toContain('id="sheet-panel-journal"');
  });
});
