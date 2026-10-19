import { renderToStaticMarkup } from "react-dom/server";

import { ItemCatalogContext, ItemNameInput } from "./item-name-input";
import { buildItemCatalog } from "@/lib/item-catalog";

/** Story 9.58: an item field flags a name the game does not declare, and only then. */
describe("ItemNameInput", () => {
  const catalog = buildItemCatalog(["Mantis_Claw"], { Skills: ["Mantis_Claw"] });

  function render(value: string, withCatalog = true): string {
    return renderToStaticMarkup(
      <ItemCatalogContext.Provider value={withCatalog ? catalog : null}>
        <ItemNameInput value={value} onChange={() => undefined} />
      </ItemCatalogContext.Provider>,
    );
  }

  it("says nothing about an item or a group of the game", () => {
    expect(render("Mantis_Claw")).not.toContain("Inconnu de ce jeu");
    expect(render("Skills")).not.toContain("Inconnu de ce jeu");
  });

  it("flags a name the game does not declare", () => {
    expect(render("Mantis Claw")).toContain("Inconnu de ce jeu");
  });

  it("stays a plain field without the game's items", () => {
    const html = render("Anything", false);

    expect(html).not.toContain("Inconnu de ce jeu");
    expect(html).not.toContain('role="combobox"');
  });
});
