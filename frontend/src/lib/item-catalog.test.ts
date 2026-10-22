import { asItemGroups, asItemNames, buildItemCatalog, isKnownItemName } from "./item-catalog";

/** Story 9.58: the game's items and groups as the YAML editors suggest and check them. */
describe("item catalog", () => {
  const catalog = buildItemCatalog(["Dream_Nail", "Lurien", "Mantis_Claw"], { Skills: ["Dream_Nail", "Mantis_Claw"], Dreamers: ["Lurien"] });

  it("suggests the groups first, then Everything, then the items", () => {
    expect(catalog?.suggestions).toEqual(["Dreamers", "Skills", "Everything", "Dream_Nail", "Lurien", "Mantis_Claw"]);
    expect(catalog?.annotations).toMatchObject({ Skills: "groupe, 2 items", Dreamers: "groupe, 1 item", Everything: "groupe, tous les items" });
  });

  it("knows the items and the groups, flags anything else", () => {
    expect(isKnownItemName(catalog, "Mantis_Claw")).toBe(true);
    expect(isKnownItemName(catalog, " Skills ")).toBe(true);
    expect(isKnownItemName(catalog, "Everything")).toBe(true);
    expect(isKnownItemName(catalog, "Mantis Claw")).toBe(false);
    expect(isKnownItemName(catalog, "")).toBe(true);
  });

  it("is absent for a game without introspected items, which flags nothing", () => {
    expect(buildItemCatalog(null, null)).toBeNull();
    expect(buildItemCatalog([], { Skills: ["A"] })).toBeNull();
    expect(isKnownItemName(null, "Anything")).toBe(true);
  });

  it("reads the API payload defensively", () => {
    expect(asItemNames(["A", 1, "", null, "B"])).toEqual(["A", "B"]);
    expect(asItemNames("A")).toBeNull();
    expect(asItemGroups({ Skills: ["A", 2], Empty: [], Bad: "A" })).toEqual({ Skills: ["A"] });
    expect(asItemGroups(["Skills"])).toBeNull();
  });
});
