import { join } from "node:path";

import { AccessibilityLevel } from "./levels";
import { packFromFolder } from "./pack-from-folder";
import { PackFiles } from "./pack-files";
import { TrackerEngine } from "./tracker-engine";

const MINI_PACK = join(__dirname, "__fixtures__", "mini-pack");

const MANIFEST = JSON.stringify({ name: "t", package_uid: "t", variants: { standard: {} } });

async function load(variant = "standard"): Promise<TrackerEngine> {
  return TrackerEngine.load(packFromFolder(MINI_PACK, variant), variant);
}

function itemId(engine: TrackerEngine, code: string): number {
  const item = engine.findItem(code);
  if (item === undefined) throw new Error(`no item ${code}`);
  return item.definition.id;
}

function sectionId(engine: TrackerEngine, path: string): number {
  const section = engine
    .pack()
    .locations.flatMap((location) => location.sections)
    .find((candidate) => candidate.path === path);
  if (section === undefined) throw new Error(`no section ${path}`);
  return section.id;
}

function packWithInit(init: string): PackFiles {
  return PackFiles.fromEntries({ "manifest.json": MANIFEST, "scripts/init.lua": init }, "standard");
}

describe("TrackerEngine with the mini pack", () => {
  let engine: TrackerEngine;

  beforeEach(async () => {
    engine = await load();
    engine.initialState();
  });

  afterEach(() => engine.dispose());

  it("loads the manifest, the items, maps, layouts and locations through init.lua", () => {
    const pack = engine.pack();
    expect(pack.manifest).toMatchObject({ name: "Mini pack", packageUid: "mini_pack", packageVersion: "1.0.0" });
    expect(pack.manifest.variants.map((variant) => variant.uid)).toEqual(["standard", "compact"]);
    expect(pack.items.map((item) => item.name)).toEqual(["Sword", "Bow", "Bombs", "Watch counter", "Crystal"]);
    expect(pack.maps).toEqual([
      { name: "world", img: "images/sword.png", locationSize: 16, locationBorderThickness: 2, locationShape: null },
    ]);
    expect(pack.layouts).toEqual({ tracker_default: { type: "itemgrid", rows: [["sword", "bow", "bombs"]] } });
    expect(pack.locations.map((location) => location.path)).toEqual([
      "Forest",
      "Forest/Hut",
      "Cave",
      "Cave/Mouth",
      "Forest/Glade",
    ]);
  });

  it("resolves require inside the pack and exposes the PopTracker globals", () => {
    expect(engine.runLua("return HELPERS_NAME")).toBe("logic.helpers");
    expect(engine.runLua("return VARIANT")).toBe("standard");
    expect(engine.runLua("return PopVersion")).toBe("0.34.0");
    expect(engine.runLua("return AccessibilityLevel.SequenceBreak")).toBe(AccessibilityLevel.SequenceBreak);
    expect(engine.runLua("return Highlight.Priority")).toBe(3);
    expect(engine.runLua("return Archipelago.PlayerNumber")).toBe(-1);
    expect(engine.runLua("return AutoTracker:GetConnectionState('AP')")).toBe(0);
    expect(engine.archipelagoHandlerCount("Clear")).toBe(1);
    expect(engine.archipelagoHandlerCount("Item")).toBe(1);
  });

  it("closes every location of a parent the player cannot reach (inherited rules)", () => {
    expect(engine.sectionLevel("Forest/Hut/Chest")).toBe(AccessibilityLevel.None);
    expect(engine.sectionLevel("Forest/Hut/Pots")).toBe(AccessibilityLevel.None);
    expect(engine.sectionLevel("Forest/Glade/Flower")).toBe(AccessibilityLevel.None);

    engine.apply({ type: "itemClick", itemId: itemId(engine, "sword"), button: "left" });

    expect(engine.sectionLevel("Forest/Hut/Chest")).toBe(AccessibilityLevel.Normal);
    expect(engine.sectionLevel("Forest/Hut/Pots")).toBe(AccessibilityLevel.Normal);
    expect(engine.sectionLevel("Forest/Glade/Flower")).toBe(AccessibilityLevel.Normal);
  });

  it("evaluates every rule form against the inventory", () => {
    const sword = itemId(engine, "sword");
    const bow = itemId(engine, "bow");
    const bombs = itemId(engine, "bombs");
    engine.apply({ type: "itemClick", itemId: sword, button: "left" });

    expect(engine.sectionLevel("Forest/Hut/Ledge")).toBe(AccessibilityLevel.SequenceBreak);
    expect(engine.sectionLevel("Forest/Hut/Peek")).toBe(AccessibilityLevel.None);
    expect(engine.sectionLevel("Forest/Hut/Arrows")).toBe(AccessibilityLevel.SequenceBreak);
    expect(engine.sectionLevel("Forest/Hut/Shelf")).toBe(AccessibilityLevel.None);
    expect(engine.sectionLevel("Forest/Hut/Comma")).toBe(AccessibilityLevel.None);
    expect(engine.sectionLevel("Cave/Mouth/Via Hut")).toBe(AccessibilityLevel.Normal);

    engine.apply({ type: "itemClick", itemId: bow, button: "left" });
    expect(engine.sectionLevel("Forest/Hut/Ledge")).toBe(AccessibilityLevel.Normal);
    expect(engine.sectionLevel("Forest/Hut/Peek")).toBe(AccessibilityLevel.Inspect);
    expect(engine.sectionLevel("Forest/Hut/Arrows")).toBe(AccessibilityLevel.Normal);

    engine.apply({ type: "itemClick", itemId: bow, button: "left" });
    expect(engine.sectionLevel("Forest/Hut/Peek")).toBe(AccessibilityLevel.Normal);

    // The third stage does not inherit: fire arrows only, the bow code is gone.
    engine.apply({ type: "itemClick", itemId: bow, button: "left" });
    expect(engine.sectionLevel("Forest/Hut/Ledge")).toBe(AccessibilityLevel.SequenceBreak);

    engine.apply({ type: "itemSet", itemId: bombs, state: { count: 2 } });
    expect(engine.sectionLevel("Forest/Hut/Shelf")).toBe(AccessibilityLevel.Normal);
    expect(engine.sectionLevel("Forest/Hut/Comma")).toBe(AccessibilityLevel.Normal);
    expect(engine.sectionLevel("Forest/Hut/Bombs counted")).toBe(AccessibilityLevel.None);
    engine.apply({ type: "itemSet", itemId: bombs, state: { count: 3 } });
    expect(engine.sectionLevel("Forest/Hut/Bombs counted")).toBe(AccessibilityLevel.Normal);
  });

  it("treats a failing rule function as false and logs it once", () => {
    engine.apply({ type: "itemClick", itemId: itemId(engine, "sword"), button: "left" });
    expect(engine.sectionLevel("Forest/Hut/Broken")).toBe(AccessibilityLevel.None);
    engine.apply({ type: "itemClick", itemId: itemId(engine, "bow"), button: "left" });
    const errors = engine.logs.filter((log) => log.message.includes("broken_rule"));
    expect(errors).toHaveLength(1);
    expect(errors[0].message).toContain("broken on purpose");
  });

  it("settles an @ loop as PopTracker does, without recursing nor reporting an error", () => {
    expect(engine.sectionLevel("Cave/Mouth/Loop A")).toBe(AccessibilityLevel.None);
    expect(engine.logs.some((log) => log.level === "info" && log.message.startsWith("Règles circulaires"))).toBe(true);
    expect(engine.logs.filter((log) => log.message.includes("Boucle") || log.message.includes("circulaires"))).toHaveLength(1);
  });

  it("sends only what changed, by id, and shares the state of a ref section", () => {
    const chest = sectionId(engine, "Forest/Hut/Chest");
    const ref = engine
      .pack()
      .locations.find((location) => location.path === "Cave/Mouth")
      ?.sections.find((section) => section.refId !== null);
    expect(ref?.refId).toBe(chest);

    const diff = engine.apply({ type: "itemClick", itemId: itemId(engine, "sword"), button: "left" });
    expect(diff.items[itemId(engine, "sword")]).toMatchObject({ active: true });
    expect(diff.sections[chest]).toMatchObject({ level: AccessibilityLevel.Normal });
    expect(ref === undefined ? undefined : diff.sections[ref.id]).toBeUndefined();
    expect(diff.items[itemId(engine, "bombs")]).toBeUndefined();

    const again = engine.apply({ type: "itemSet", itemId: itemId(engine, "sword"), state: { active: true } });
    expect(again.sections).toEqual({});
  });

  it("clears sections one check at a time or as a group, and marks them cleared", () => {
    engine.apply({ type: "itemClick", itemId: itemId(engine, "sword"), button: "left" });
    const pots = sectionId(engine, "Forest/Hut/Pots");

    expect(engine.apply({ type: "sectionClear", sectionId: pots }).sections[pots]).toMatchObject({ available: 2 });
    expect(engine.apply({ type: "sectionUnclear", sectionId: pots }).sections[pots]).toMatchObject({ available: 3 });
    const cleared = engine.apply({ type: "sectionClear", sectionId: pots, count: 3 });
    expect(cleared.sections[pots]).toMatchObject({ available: 0, level: AccessibilityLevel.Cleared });
  });

  it("checks the hosted item when its section is cleared, which reveals a hidden section", () => {
    engine.apply({ type: "itemClick", itemId: itemId(engine, "sword"), button: "left" });
    const crystal = sectionId(engine, "Forest/Hut/Crystal");
    const hidden = sectionId(engine, "Forest/Hut/Hidden");
    expect(engine.initialState().sections[hidden].visible).toBe(false);

    const diff = engine.apply({ type: "sectionClear", sectionId: crystal });
    expect(diff.items[itemId(engine, "crystal")]).toMatchObject({ active: true });
    expect(diff.sections[crystal]).toMatchObject({ level: AccessibilityLevel.Cleared });
    expect(diff.sections[hidden]).toMatchObject({ visible: true });

    const undo = engine.apply({ type: "sectionUnclear", sectionId: crystal });
    expect(undo.items[itemId(engine, "crystal")]).toMatchObject({ active: false });
  });

  it("runs the watchers after a change, and holds them during BulkUpdate", () => {
    const counter = itemId(engine, "watch_counter");
    const diff = engine.apply({ type: "itemClick", itemId: itemId(engine, "sword"), button: "left" });
    expect(diff.items[counter]).toMatchObject({ count: 1 });

    engine.runLua("Tracker.BulkUpdate = true\nTracker:FindObjectForCode('sword').Active = false");
    expect(engine.apply({ type: "itemClick", itemId: itemId(engine, "crystal"), button: "left" }).items[counter]).toBeUndefined();

    engine.runLua("Tracker.BulkUpdate = false");
    expect(engine.apply({ type: "itemClick", itemId: itemId(engine, "crystal"), button: "left" }).items[counter]).toMatchObject({
      count: 2,
    });
  });

  it("lets Lua read and write items and sections", () => {
    engine.runLua(`
      local bombs = Tracker:FindObjectForCode("bombs")
      bombs.AcquiredCount = 7
      local pots = Tracker:FindObjectForCode("@Forest/Hut/Pots")
      pots.AvailableChestCount = 1
    `);
    expect(engine.runLua("return Tracker:FindObjectForCode('bombs').AcquiredCount")).toBe(3);
    expect(engine.runLua("return Tracker:FindObjectForCode('bombs').MaxCount")).toBe(3);
    expect(engine.runLua("return Tracker:FindObjectForCode('bow').Type")).toBe("progressive");
    expect(engine.runLua("return Tracker:FindObjectForCode('@Hut/Pots').ChestCount")).toBe(3);
    expect(engine.runLua("return Tracker:FindObjectForCode('@Hut/Pots').AvailableChestCount")).toBe(1);
    expect(engine.runLua("return Tracker:FindObjectForCode('nothing')")).toBeNull();
    expect(engine.runLua("return Tracker:ProviderCountForCode('blade')")).toBe(0);
  });

  it("serves the files of the active variant first", async () => {
    const compact = await load("compact");
    expect(compact.pack().layouts).toEqual({ tracker_default: { type: "itemgrid", rows: [["sword"]] } });
    expect(compact.runLua("return VARIANT")).toBe("compact");
    compact.dispose();
  });
});

describe("TrackerEngine sandbox", () => {
  let engine: TrackerEngine;

  beforeAll(async () => {
    engine = await TrackerEngine.load(packWithInit("x = 1"), "standard");
  });

  afterAll(() => engine.dispose());

  it("takes io, debug, package and file loading away from the pack", () => {
    expect(engine.runLua("return io == nil and debug == nil and package == nil")).toBe(true);
    expect(engine.runLua("return dofile == nil and loadfile == nil and string.dump == nil")).toBe(true);
  });

  it("leaves os with time only", () => {
    expect(engine.runLua("return os.execute == nil and os.exit == nil and os.getenv == nil and os.remove == nil")).toBe(true);
    expect(engine.runLua("return type(os.time()) == 'number' and type(os.clock()) == 'number'")).toBe(true);
  });

  it("gives no way to reach the browser's JS", () => {
    expect(engine.runLua("return js == nil and __pt_bridge == nil and window == nil")).toBe(true);
    const userdata = engine.runLua(`
      local seen, found = {}, 0
      local function walk(value)
        if type(value) == "userdata" then found = found + 1 end
        if type(value) ~= "table" or seen[value] then return end
        seen[value] = true
        for _, child in pairs(value) do walk(child) end
        local meta = getmetatable(value)
        if type(meta) == "table" then walk(meta) end
      end
      walk(_G)
      walk(getmetatable(""))
      return found
    `);
    expect(userdata).toBe(0);
  });

  it("refuses bytecode and keeps collectgarbage to count", () => {
    expect(engine.runLua(`local f, err = load("\\27Lua"); return f == nil and err:find("binary") ~= nil`)).toBe(true);
    expect(engine.runLua(`return collectgarbage("collect") == 0 and collectgarbage("count") > 0`)).toBe(true);
  });

  it("only loads scripts from inside the pack", () => {
    expect(engine.runLua(`return ScriptHost:LoadScript("../../outside.lua")`)).toBe(false);
    expect(engine.runLua(`return pcall(require, "../outside")`)).toBe(false);
    expect(engine.runLua(`return pcall(require, "missing.module")`)).toBe(false);
  });

  it("answers memory autotracking with nil and a log", () => {
    expect(engine.runLua(`return ScriptHost:AddMemoryWatch("x", 0, 1, function() end) == nil`)).toBe(true);
    expect(engine.runLua(`return AutoTracker:ReadU8(0) == nil`)).toBe(true);
    expect(engine.logs.some((log) => log.message.includes("autotracking mémoire non pris en charge"))).toBe(true);
  });
});

describe("TrackerEngine failures", () => {
  it("stops on an unsupported API with its name", async () => {
    const failure = TrackerEngine.load(packWithInit("Archipelago:LocationChecks({ 1 })"), "standard");
    await expect(failure).rejects.toMatchObject({ kind: "unsupported_api", message: expect.stringContaining("Archipelago:LocationChecks") });
  });

  it("reports a Lua error with its file and line", async () => {
    const failure = TrackerEngine.load(packWithInit("local ok = 1\nlocal broken = nil + 1"), "standard");
    await expect(failure).rejects.toMatchObject({ kind: "lua_error", file: "scripts/init.lua", line: 2 });
  });

  it("refuses an unknown variant and a pack without init.lua", async () => {
    await expect(TrackerEngine.load(packWithInit(""), "other")).rejects.toMatchObject({ kind: "variant_unknown" });
    const noInit = PackFiles.fromEntries({ "manifest.json": MANIFEST }, "standard");
    await expect(TrackerEngine.load(noInit, "standard")).rejects.toMatchObject({ kind: "file_missing" });
  });

  it("refuses an unreadable manifest", async () => {
    const files = PackFiles.fromEntries({ "manifest.json": "{ nope", "scripts/init.lua": "" }, "standard");
    await expect(TrackerEngine.load(files, "standard")).rejects.toMatchObject({ kind: "manifest_invalid" });
  });
});
