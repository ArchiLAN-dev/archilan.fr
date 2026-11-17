import { AccessibilityLevel } from "./levels";
import { PackFiles } from "./pack-files";
import { TrackerEngine } from "./tracker-engine";

/**
 * Story 54.8: what the compatibility campaign added to the engine, one test per addition, on packs written here in
 * the shape of the published ones (Inscryption, Cuphead, Pokémon Platinum, Hollow Knight, Celeste, Spyro 3).
 */
const MANIFEST = JSON.stringify({ name: "t", package_uid: "t", variants: { standard: {} } });

type Files = Record<string, unknown>;

async function load(init: string, files: Files = {}): Promise<TrackerEngine> {
  const entries: Record<string, string> = { "manifest.json": MANIFEST, "scripts/init.lua": init };
  for (const [path, content] of Object.entries(files)) entries[path] = typeof content === "string" ? content : JSON.stringify(content);
  const engine = await TrackerEngine.load(PackFiles.fromEntries(entries, "standard"), "standard");
  engine.initialState();
  return engine;
}

function sections(...list: { name: string; rules: unknown[] }[]): unknown[] {
  return list.map(({ name, rules }) => ({ name, sections: [{ name: "x", access_rules: rules }] }));
}

const ADD_ALL = `
Tracker:AddItems("items.json")
Tracker:AddLocations("locations.json")
`;

describe("the engine after the compatibility campaign (story 54.8)", () => {
  it("provides the codes a progressive item carries next to its stages (Inscryption options)", async () => {
    const engine = await load(
      `${ADD_ALL}
      function goal_stage() return Tracker:FindObjectForCode("goal").CurrentStage == 0 end`,
      {
        "items.json": [
          {
            name: "Goal",
            type: "progressive",
            codes: "goal",
            allow_disabled: false,
            stages: [
              { codes: "inorder", inherit_codes: false },
              { codes: "anyorder", inherit_codes: false },
            ],
          },
        ],
        "locations.json": sections({ name: "ByCode", rules: ["goal"] }, { name: "ByLua", rules: ["$goal_stage"] }, { name: "Stage", rules: ["anyorder"] }),
      },
    );
    expect(engine.sectionLevel("ByCode/x")).toBe(AccessibilityLevel.Normal);
    expect(engine.sectionLevel("ByLua/x")).toBe(AccessibilityLevel.Normal);
    expect(engine.sectionLevel("Stage/x")).toBe(AccessibilityLevel.None);
    expect(engine.logs.filter((log) => log.level === "error")).toEqual([]);
    engine.dispose();
  });

  it("settles rules that need each other, as PopTracker does (a Cuphead boss needs its isle, the isle its boss)", async () => {
    const engine = await load(ADD_ALL, {
      "items.json": [{ name: "Key", type: "toggle", codes: "key" }],
      "locations.json": sections({ name: "Boss", rules: ["@Isle/x"] }, { name: "Isle", rules: ["@Boss/x", "key"] }),
    });
    expect(engine.sectionLevel("Boss/x")).toBe(AccessibilityLevel.None);
    const key = engine.findItem("key");
    if (key === undefined) throw new Error("no key");
    engine.apply({ type: "itemSet", itemId: key.definition.id, state: { active: true } });
    expect(engine.sectionLevel("Isle/x")).toBe(AccessibilityLevel.Normal);
    // Evaluated first, the boss met the isle while the isle was being evaluated: a second pass gives it the isle's level.
    expect(engine.sectionLevel("Boss/x")).toBe(AccessibilityLevel.Normal);
    expect(engine.logs.filter((log) => log.level === "error")).toEqual([]);
    engine.dispose();
  });

  it("runs Lua items: codes, drawing, clicks and FindObjectForCode (Pokémon Platinum, Hollow Knight)", async () => {
    const engine = await load(
      `${ADD_ALL}
      local item = ScriptHost:CreateLuaItem()
      item.Name = "Badge case"
      item.Icon = ImageReference:FromImageReference(ImageReference:FromPackRelativePath("images/case.png"), "@disabled")
      item.ItemState = { count = 0 }
      item.PotentialCodes = { "badges" }
      item.ProvidesCodeFunc = function(self, code) return self.ItemState.count end
      item.OnLeftClickFunc = function(self)
        self.ItemState.count = self.ItemState.count + 1
        self.BadgeText = tostring(self.ItemState.count)
      end
      item.OnRightClickFunc = function(self) self.ItemState.count = 0 end
      function case_count() return Tracker:FindObjectForCode("badges").ItemState.count end`,
      {
        "items.json": [],
        "locations.json": sections({ name: "Gym", rules: ["badges:2"] }, { name: "Count", rules: ["$case_count"] }),
      },
    );
    const definition = engine.pack().items.find((item) => item.name === "Badge case");
    if (definition === undefined) throw new Error("no Lua item");
    expect(definition.stages[0]).toMatchObject({ img: "images/case.png", imgMods: "@disabled", codes: ["badges"] });
    expect(engine.sectionLevel("Gym/x")).toBe(AccessibilityLevel.None);

    engine.apply({ type: "itemClick", itemId: definition.id, button: "left" });
    const update = engine.apply({ type: "itemClick", itemId: definition.id, button: "left" });
    expect(update.items[definition.id]).toMatchObject({ overlay: "2", icon: { img: "images/case.png" }, name: "Badge case" });
    expect(engine.sectionLevel("Gym/x")).toBe(AccessibilityLevel.Normal);
    expect(engine.sectionLevel("Count/x")).toBe(AccessibilityLevel.Normal);

    engine.apply({ type: "itemClick", itemId: definition.id, button: "right" });
    expect(engine.sectionLevel("Gym/x")).toBe(AccessibilityLevel.None);
    // A saved state never sets a Lua item: its state lives in Lua.
    expect(() => engine.apply({ type: "itemSet", itemId: definition.id, state: { active: false } })).not.toThrow();
    engine.dispose();
  });

  it("runs async scripts in their own environment after the logic pass, then their callback (Celeste)", async () => {
    const engine = await load(
      `${ADD_ALL}
      RESULT = nil
      function async_ready()
        if RESULT == nil and not STARTED then
          STARTED = true
          ScriptHost:RunScriptAsync("scripts/job.lua", { n = 21 }, function(value)
            RESULT = value
            Tracker:FindObjectForCode("refresh").Active = true
          end)
        end
        return RESULT == 42
      end`,
      {
        "scripts/job.lua": `local helper = require("scripts/helper")
          if Tracker ~= nil or RESULT ~= nil then return -1 end
          return helper(arg.n)`,
        "scripts/helper.lua": "return function(n) return n * 2 end",
        "items.json": [{ name: "Refresh", type: "toggle", codes: "refresh" }],
        "locations.json": sections({ name: "Summit", rules: ["$async_ready"] }),
      },
    );
    expect(engine.sectionLevel("Summit/x")).toBe(AccessibilityLevel.Normal);
    expect(engine.logs.filter((log) => log.level === "error")).toEqual([]);
    engine.dispose();
  });

  it("tells a function PopTracker does not have from one the site lacks, and keeps a pack whose init.lua fails late (Spyro 3)", async () => {
    const engine = await load(`${ADD_ALL}\nTracker:AddMemoryWatch("LocationWatch", 0, 0, print)`, {
      "items.json": [{ name: "Gem", type: "toggle", codes: "gem" }],
      "locations.json": sections({ name: "Town", rules: ["gem"] }),
    });
    expect([...engine.unsupportedCalls]).toEqual([]);
    expect(engine.logs.some((log) => log.level === "error" && log.message.includes("AddMemoryWatch"))).toBe(true);
    expect(engine.findItem("gem")).toBeDefined();
    engine.dispose();

    await expect(TrackerEngine.load(PackFiles.fromEntries({ "manifest.json": MANIFEST, "scripts/init.lua": "Tracker:Nope()" }, "standard"), "standard")).rejects.toMatchObject({
      kind: "lua_error",
    });
  });
});
