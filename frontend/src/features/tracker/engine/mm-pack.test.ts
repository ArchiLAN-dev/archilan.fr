import { existsSync } from "node:fs";

import { AccessibilityLevel } from "./levels";
import { packFromFolder } from "./pack-from-folder";
import { TrackerEngine } from "./tracker-engine";

/**
 * The Majora's Mask pack (MIT, v0.9.5.3) of story 54.4. It is not committed: point TRACKER_MM_PACK_DIR at the folder
 * holding its manifest.json to run these tests, they are skipped otherwise.
 *
 *   TRACKER_MM_PACK_DIR=/path/to/Majoras-Mask-AP-PopTracker-Pack-0.9.5.3 pnpm jest mm-pack
 */
const folder = process.env.TRACKER_MM_PACK_DIR ?? "";
const run = folder !== "" && existsSync(`${folder}/manifest.json`) ? describe : describe.skip;

const ENTRANCE = "Dungeons/Woodfall Temple/Woodfall Temple Entrance Chest SF";
const SWITCH = "Dungeons/Woodfall Temple/Woodfall Temple Switch Chest SF";

async function load(variant: string): Promise<TrackerEngine> {
  return TrackerEngine.load(packFromFolder(folder, variant), variant);
}

function give(engine: TrackerEngine, code: string, state: { active?: boolean; stage?: number; count?: number }): void {
  const item = engine.findItem(code);
  if (item === undefined) throw new Error(`no item for ${code}`);
  engine.apply({ type: "itemSet", itemId: item.definition.id, state });
}

run("Majora's Mask pack", () => {
  it.each(["standard", "itemsonly", "mapsonly"])("loads the %s variant without Lua error nor unsupported API", async (variant) => {
    const started = performance.now();
    const engine = await load(variant);
    engine.initialState();
    const elapsed = performance.now() - started;

    expect([...engine.unsupportedCalls]).toEqual([]);
    // The pack's own bug, as PopTracker shows it too: init.lua loads scripts/inverse_rules.lua, the file is
    // inverse_logic.lua, so the rule function it defines is missing.
    const errors = engine.logs.filter((log) => log.level === "error").map((log) => log.message);
    expect(errors).toContain("Script introuvable : scripts/inverse_rules.lua");
    expect(errors.filter((message) => !message.includes("inverse_rules") && !message.includes("small_key_sanity_off"))).toEqual([]);
    expect(engine.archipelagoHandlerCount("Item")).toBe(1);
    // tracker.jsonc wraps its layouts in a `layouts` key (story 54.5): the screen needs them unwrapped.
    expect(engine.pack().layouts).toHaveProperty("tracker_default");
    expect(engine.pack().layouts).not.toHaveProperty("layouts");
    expect(elapsed).toBeLessThan(3000);
    engine.dispose();
  });

  it("opens Woodfall Temple with Deku, Ocarina, Sonata and a bottle, the switch chest with the bow", async () => {
    const engine = await load("standard");
    engine.initialState();
    expect(engine.sectionLevel(ENTRANCE)).toBe(AccessibilityLevel.None);

    give(engine, "deku", { active: true });
    give(engine, "ocarina", { active: true });
    give(engine, "sonata", { active: true });
    expect(engine.sectionLevel(ENTRANCE)).toBe(AccessibilityLevel.None);

    give(engine, "bottles", { count: 1 });
    expect(engine.sectionLevel(ENTRANCE)).toBe(AccessibilityLevel.Normal);
    expect(engine.sectionLevel(SWITCH)).toBe(AccessibilityLevel.None);

    give(engine, "bow", { stage: 1 });
    expect(engine.sectionLevel(SWITCH)).toBe(AccessibilityLevel.Normal);
    engine.dispose();
  });

  /**
   * AC 3. Ten sections under four inventories (normal logic, the pack's default). Expected values were written by
   * reading the pack's rules and Lua by hand before running the engine (6 = Normal, 0 = None). The PopTracker 0.34
   * desktop column is still to be read by hand: until then this compares the engine with the pack's rules, not
   * with PopTracker itself.
   */
  const INVENTORIES: Record<string, Array<[string, { active?: boolean; stage?: number; count?: number }]>> = {
    A_nothing: [],
    B_woodfall: [
      ["deku", { active: true }],
      ["ocarina", { active: true }],
      ["sonata", { active: true }],
      ["bottles", { count: 1 }],
    ],
    C_woodfall_bow_hookshot: [
      ["deku", { active: true }],
      ["ocarina", { active: true }],
      ["sonata", { active: true }],
      ["bottles", { count: 1 }],
      ["bow", { stage: 1 }],
      ["hookshot", { active: true }],
    ],
    D_zora_bombs_epona: [
      ["zora", { active: true }],
      ["bombs", { stage: 1 }],
      ["epona", { active: true }],
      ["ocarina", { active: true }],
    ],
  };

  //                                                                                       A  B  C  D
  const EXPECTED: Array<[string, number[]]> = [
    ["Termina Field/South East Termina Field/Termina Grass Chest", [6, 6, 6, 6]],
    ["Termina Field/Termina Underwater Chest/Termina Underwater Chest", [0, 0, 0, 6]],
    ["Termina Field/Termina Log Bombable Grotto/Left Cow", [0, 0, 0, 6]],
    ["Termina Field/East Clock Town Astral Observatory/East Clock Town Astral Observatory", [0, 6, 6, 6]],
    ["Southern Swamp/Road to Swamp Tree HP/Road to Swamp Tree HP", [0, 0, 6, 6]],
    ["Southern Swamp/Deku Palace HP/Deku Palace HP", [0, 6, 6, 0]],
    ["Southern Swamp/Southern Swamp Grotto Chest/Southern Swamp Grotto Chest", [0, 6, 6, 0]],
    ["Clock Town/South Clock Town Corner Chest/South Clock Town Corner Chest", [0, 0, 6, 0]],
    [ENTRANCE, [0, 6, 6, 0]],
    [SWITCH, [0, 0, 6, 0]],
  ];

  it.each(Object.keys(INVENTORIES).map((name, column) => [name, column] as const))(
    "matches the hand-read accessibility of ten sections with inventory %s",
    async (name, column) => {
      const engine = await load("standard");
      engine.initialState();
      for (const [code, state] of INVENTORIES[name]) give(engine, code, state);
      const actual = EXPECTED.map(([path]) => [path, engine.sectionLevel(path)]);
      expect(actual).toEqual(EXPECTED.map(([path, levels]) => [path, levels[column]]));
      engine.dispose();
    },
  );

  it("updates the state of an item change in under 100 ms", async () => {
    const engine = await load("standard");
    engine.initialState();
    const deku = engine.findItem("deku");
    if (deku === undefined) throw new Error("no deku");
    const started = performance.now();
    const diff = engine.apply({ type: "itemClick", itemId: deku.definition.id, button: "left" });
    expect(performance.now() - started).toBeLessThan(100);
    expect(diff.items[deku.definition.id]?.active).toBe(true);
    engine.dispose();
  });
});
