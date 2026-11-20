import type { ApEvent } from "./ap-events";
import { parseItems, type TrackerItem } from "./items";
import { AccessibilityLevel, compareVersions } from "./levels";
import { PackFiles } from "./pack-files";
import { pinKey } from "./protocol";
import { TrackerEngine, type EngineOptions } from "./tracker-engine";

/**
 * Story 54.11: parity with PopTracker v0.36.0, one test per behaviour brought in line with its source
 * (core/jsonitem.{h,cpp}, core/luaitem.cpp, core/scripthost.cpp, doc/AUTOTRACKING.md).
 */
const MANIFEST = JSON.stringify({ name: "t", package_uid: "t", variants: { standard: {} } });

async function load(init: string, files: Record<string, unknown> = {}, options: EngineOptions = {}): Promise<TrackerEngine> {
  const entries: Record<string, string> = { "manifest.json": MANIFEST, "scripts/init.lua": init };
  for (const [path, content] of Object.entries(files)) entries[path] = typeof content === "string" ? content : JSON.stringify(content);
  const engine = await TrackerEngine.load(PackFiles.fromEntries(entries, "standard"), "standard", options);
  engine.initialState();
  return engine;
}

function parse(json: unknown[]): TrackerItem[] {
  let id = 1;
  return parseItems(json, () => id++, () => undefined, []);
}

const lookup = (items: TrackerItem[]) => (id: number) => items.find((item) => item.definition.id === id);
const none = () => undefined;

describe("items as PopTracker counts and clicks them (story 54.11, lot 1)", () => {
  it("counts a consumable without max_quantity with no ceiling", () => {
    const [keys] = parse([{ type: "consumable", codes: "keys" }]);
    keys.setCount(12);
    expect(keys.providedCount("keys", none)).toBe(12);
    keys.click("left", none);
    expect(keys.count).toBe(13);
  });

  it("takes the increment as the default decrement", () => {
    const [shards] = parse([{ type: "consumable", codes: "shards", increment: 5 }]);
    shards.click("left", none);
    shards.click("left", none);
    shards.click("right", none);
    expect(shards.count).toBe(5);
  });

  it("goes back from the off state of a looping progressive to its last stage, switched on", () => {
    const [bow] = parse([{ type: "progressive", loop: true, stages: [{ codes: "bow" }, { codes: "bow2" }] }]);
    expect(bow.isActive()).toBe(false);
    bow.click("right", none);
    expect([bow.isActive(), bow.stage]).toEqual([true, 1]);
    bow.click("right", none);
    bow.click("right", none);
    expect([bow.isActive(), bow.stage]).toEqual([false, 0]);
  });

  it("loops the stages of a progressive_toggle on right click, without loop in the pack", () => {
    const [song] = parse([{ type: "progressive_toggle", stages: [{ codes: "a" }, { codes: "b" }] }]);
    song.click("right", none);
    song.click("right", none);
    expect(song.stage).toBe(0);
  });

  it("gives a composite_toggle's code once per part that is on", () => {
    const items = parse([
      { type: "toggle", codes: "left_code" },
      { type: "toggle", codes: "right_code" },
      { type: "composite_toggle", item_left: "left_code", item_right: "right_code", codes: "both" },
    ]);
    const composite = items[2];
    composite.click("left", lookup(items));
    composite.click("right", lookup(items));
    expect(composite.providedCount("both", lookup(items))).toBe(2);
    expect(composite.providedCount("left_code", lookup(items))).toBe(0);
  });
});

describe("watchers and Lua items as PopTracker runs them (story 54.11, lot 1)", () => {
  it("re-runs the logic and the watchers when an Archipelago handler changes a Lua item", async () => {
    const engine = await load(
      `Tracker:AddLocations("locations.json")
      WATCHED = {}
      local case = ScriptHost:CreateLuaItem()
      case.Name = "Badge case"
      case.PotentialCodes = { "badges" }
      case.ProvidesCodeFunc = function(self, code) return code == "badges" and (self:Get("count") or 0) or 0 end
      ScriptHost:AddWatchForCode("all", "*", function(codes) WATCHED[#WATCHED + 1] = codes end)
      Archipelago:AddItemHandler("badge", function() case:Set("count", (case:Get("count") or 0) + 1) end)`,
      { "locations.json": [{ name: "Gym", sections: [{ name: "x", access_rules: ["badges:2"] }] }] },
    );
    expect(engine.sectionLevel("Gym/x")).toBe(AccessibilityLevel.None);
    const items: ApEvent = { kind: "items", items: [{ index: 0, item: 1, location: 5, player: 1, flags: 1 }, { index: 1, item: 1, location: 6, player: 1, flags: 1 }] };
    engine.applyAp([items]);
    expect(engine.sectionLevel("Gym/x")).toBe(AccessibilityLevel.Normal);
    expect(engine.runLua("return WATCHED[1]")).toBe("badges");
    engine.dispose();
  });

  it("gives a '*' watch the codes of the changed item, and moves a watch added again to the end", async () => {
    const engine = await load(
      `Tracker:AddItems("items.json")
      ORDER = {}
      ScriptHost:AddWatchForCode("first", "*", function(codes) ORDER[#ORDER + 1] = "first:" .. codes end)
      ScriptHost:AddWatchForCode("second", "sword", function(code) ORDER[#ORDER + 1] = "second:" .. code end)
      ScriptHost:AddWatchForCode("first", "*", function(codes) ORDER[#ORDER + 1] = "first:" .. codes end)`,
      { "items.json": [{ name: "Sword", type: "toggle", codes: "sword, blade" }] },
    );
    const sword = engine.findItem("sword");
    if (sword === undefined) throw new Error("no sword");
    engine.apply({ type: "itemClick", itemId: sword.definition.id, button: "left" });
    expect(engine.runLua("return table.concat(ORDER, '|')")).toBe("second:sword|first:sword, blade");
    engine.dispose();
  });
});

describe("the Archipelago table as PopTracker fills it (story 54.11, lot 1)", () => {
  const connected: ApEvent = {
    kind: "connected",
    playerNumber: 1,
    teamNumber: 0,
    seed: "s",
    game: "G",
    checked: [1],
    missing: [2, 3],
    players: [],
    names: {},
  };

  it("lists a checked location once and takes it out of MissingLocations", async () => {
    const engine = await load(`Archipelago:AddLocationHandler("l", function() end)`);
    engine.applyAp([connected, { kind: "locations", locations: [1] }, { kind: "locations", locations: [2, 2] }]);
    expect(engine.runLua("return table.concat(Archipelago.CheckedLocations, ',')")).toBe("1,2");
    expect(engine.runLua("return table.concat(Archipelago.MissingLocations, ',')")).toBe("3");
    engine.dispose();
  });

  it("has ClientStatus under Archipelago and PopTracker's connection states", async () => {
    const engine = await load(``);
    expect(engine.runLua("return Archipelago.ClientStatus.GOAL")).toBe(30);
    expect(engine.runLua(`return AutoTracker:GetConnectionState("AP")`)).toBe(0);
    engine.applyAp([connected]);
    expect(engine.runLua(`return AutoTracker:GetConnectionState("AP")`)).toBe(3);
    engine.applyAp([{ kind: "disconnected" }]);
    expect(engine.runLua(`return AutoTracker:GetConnectionState("AP")`)).toBe(1);
    engine.dispose();
  });
});

describe("pins with their own visibility rules, as PopTracker (story 54.11, lot 2)", () => {
  it("shows a pin by its restrict rules and hides it while a force rule holds", async () => {
    const engine = await load(`Tracker:AddItems("items.json")
Tracker:AddLocations("locations.json")`, {
      "items.json": [
        { name: "Lens", type: "toggle", codes: "lens" },
        { name: "Night", type: "toggle", codes: "night" },
      ],
      "locations.json": [
        {
          name: "Grave",
          sections: [{ name: "x" }],
          map_locations: [
            { map: "world", x: 1, y: 1 },
            { map: "world", x: 2, y: 2, restrict_visibility_rules: ["lens"] },
            { map: "world", x: 3, y: 3, force_invisibility_rules: ["night"] },
          ],
        },
      ],
    });
    const grave = engine.pack().locations.find((location) => location.name === "Grave");
    if (grave === undefined) throw new Error("no Grave");
    const first = engine.initialState();
    expect(first.pins).toEqual({ [pinKey(grave.id, 1)]: false, [pinKey(grave.id, 2)]: true });

    const lens = engine.findItem("lens");
    const night = engine.findItem("night");
    if (lens === undefined || night === undefined) throw new Error("no items");
    engine.apply({ type: "itemSet", itemId: lens.definition.id, state: { active: true } });
    const update = engine.apply({ type: "itemSet", itemId: night.definition.id, state: { active: true } });
    expect(update.pins).toEqual({ [pinKey(grave.id, 2)]: false });
    engine.dispose();
  });
});

describe("items a layout names, as PopTracker finds them (story 54.11, lot 2)", () => {
  it("gives the screen the code of a Lua item known by CanProvideCodeFunc only", async () => {
    const engine = await load(
      `Tracker:AddLayouts("layouts.json")
      local tide = ScriptHost:CreateLuaItem()
      tide.Name = "Tide"
      tide.CanProvideCodeFunc = function(self, code) return code == "tide" end`,
      { "layouts.json": { tracker_default: { type: "itemgrid", rows: [["tide", "unknown"]] } } },
    );
    const tide = engine.pack().items.find((item) => item.name === "Tide");
    expect(tide?.stages[0]?.codes).toEqual(["tide"]);
    engine.dispose();
  });
});

describe("item properties from Lua, as PopTracker (story 54.11, lot 2)", () => {
  it("draws a JSON item's .Icon until its state changes", async () => {
    const engine = await load(
      `Tracker:AddItems("items.json")
      Tracker:FindObjectForCode("sword").Icon = ImageReference:FromImageReference(ImageReference:FromPackRelativePath("images/golden.png"), "dim")`,
      { "items.json": [{ name: "Sword", type: "toggle", codes: "sword", img: "images/sword.png" }] },
    );
    const sword = engine.findItem("sword");
    if (sword === undefined) throw new Error("no sword");
    expect(sword.state().icon).toEqual({ img: "images/golden.png", imgMods: "dim" });
    engine.apply({ type: "itemClick", itemId: sword.definition.id, button: "left" });
    expect(sword.state().icon).toBeUndefined();
    engine.dispose();
  });

  it("adds IconMods to a Lua item's icon, keeps Get/Set in ItemState, gives an Owner, honours IgnoreUserInput", async () => {
    const engine = await load(
      `local lamp = ScriptHost:CreateLuaItem()
      lamp.Name = "Lamp"
      lamp.Icon = ImageReference:FromPackRelativePath("images/lamp.png")
      lamp.IconMods = "@disabled"
      lamp:Set("lit", true)
      LIT = lamp.ItemState.lit
      OWNER = type(lamp.Owner)
      CLICKS = 0
      lamp.OnLeftClickFunc = function() CLICKS = CLICKS + 1 end
      lamp.IgnoreUserInput = true`,
    );
    const lamp = engine.pack().items.find((item) => item.name === "Lamp");
    if (lamp === undefined) throw new Error("no lamp");
    expect(lamp.stages[0]).toMatchObject({ img: "images/lamp.png", imgMods: "@disabled" });
    expect(engine.runLua("return LIT")).toBe(true);
    expect(engine.runLua("return OWNER")).toBe("table");
    engine.apply({ type: "itemClick", itemId: lamp.id, button: "left" });
    expect(engine.runLua("return CLICKS")).toBe(0);
    engine.dispose();
  });
});

describe("the engine's guards against slow packs (story 54.11)", () => {
  it("fails a pack call stuck in a loop once its budget is spent, coroutines too (story 54.13)", async () => {
    const budgets = { call: 0.2, frame: 0.05 };
    await expect(load("while true do end", {}, { budgets })).rejects.toThrow("temps de calcul dépassé");
    await expect(load("coroutine.wrap(function() while true do end end)()", {}, { budgets })).rejects.toThrow("temps de calcul dépassé");
    await expect(
      load("local co = coroutine.create(function() while true do end end) local ok, e = coroutine.resume(co) error(e)", {}, { budgets }),
    ).rejects.toThrow("temps de calcul dépassé");
  });

  it("drops a frame handler that blows its budget, and keeps the others (story 54.13)", async () => {
    const engine = await load(
      `ticks = 0
      ScriptHost:AddOnFrameHandler("stuck", function() while true do end end)
      ScriptHost:AddOnFrameHandler("fine", function() ticks = ticks + 1 end)`,
      {},
      { budgets: { call: 2, frame: 0.05 } },
    );
    engine.frame(0.1);
    expect(engine.hasFrameHandlers()).toBe(true);
    expect(engine.logs.some((log) => log.message.includes("frame handler stuck") && log.message.includes("temps de calcul dépassé"))).toBe(true);
    engine.frame(0.1);
    expect(engine.logs.filter((log) => log.message.includes("frame handler stuck"))).toHaveLength(1);
    engine.dispose();
  });

  it("fails at once a rule that calls itself without end, as PopTracker's stack overflow, and logs it", async () => {
    const engine = await load(
      `Tracker:AddLocations("locations.json")
      function has(item) return hasAll({ item }) end
      function hasAll(items) for _, item in ipairs(items) do if not has(item) then return false end end return true end`,
      { "locations.json": [{ name: "Lantern room", sections: [{ name: "x", access_rules: ["$has|lantern"] }] }] },
    );
    expect(engine.sectionLevel("Lantern room/x")).toBe(AccessibilityLevel.None);
    expect(engine.logs.some((log) => log.level === "error" && log.message.includes("récursion sans fin"))).toBe(true);
    engine.dispose();
  });

  it("keeps the counts Lua asked for only until the state changes", async () => {
    const engine = await load(
      `Tracker:AddItems("items.json")
      Tracker:AddLocations("locations.json")
      function swords() return Tracker:ProviderCountForCode("sword") >= 1 end`,
      {
        "items.json": [{ name: "Sword", type: "toggle", codes: "sword" }],
        "locations.json": [{ name: "Cave", sections: [{ name: "x", access_rules: ["$swords"] }] }],
      },
    );
    expect(engine.sectionLevel("Cave/x")).toBe(AccessibilityLevel.None);
    const sword = engine.findItem("sword");
    if (sword === undefined) throw new Error("no sword");
    engine.apply({ type: "itemClick", itemId: sword.definition.id, button: "left" });
    expect(engine.sectionLevel("Cave/x")).toBe(AccessibilityLevel.Normal);
    engine.dispose();
  });
});

describe("UI hints, as PopTracker (story 54.11, lot 4)", () => {
  it("sends Tracker:UiHint calls to the screen with the next state, once", async () => {
    const engine = await load(
      `Tracker:AddItems("items.json")
      ScriptHost:AddWatchForCode("go", "boots", function() Tracker:UiHint("ActivateTab", "Lanayru") end)`,
      { "items.json": [{ name: "Boots", type: "toggle", codes: "boots" }] },
    );
    const boots = engine.findItem("boots");
    if (boots === undefined) throw new Error("no boots");
    const update = engine.apply({ type: "itemClick", itemId: boots.definition.id, button: "left" });
    expect(update.hints).toEqual([{ name: "ActivateTab", value: "Lanayru" }]);
    expect(engine.apply({ type: "itemClick", itemId: boots.definition.id, button: "left" }).hints).toEqual([{ name: "ActivateTab", value: "Lanayru" }]);
    engine.dispose();
  });
});

describe("frame handlers, as PopTracker (story 54.11, lot 4)", () => {
  it("calls them with the elapsed seconds and sends only what they change", async () => {
    const engine = await load(
      `Tracker:AddItems("items.json")
      local waited = 0
      ScriptHost:AddOnFrameHandler("timer", function(elapsed)
        waited = waited + elapsed
        if waited >= 1 then
          Tracker:FindObjectForCode("lamp").Active = true
          ScriptHost:RemoveOnFrameHandler("timer")
        end
      end)`,
      { "items.json": [{ name: "Lamp", type: "toggle", codes: "lamp" }] },
    );
    const lamp = engine.findItem("lamp");
    if (lamp === undefined) throw new Error("no lamp");
    expect(engine.hasFrameHandlers()).toBe(true);
    expect(engine.frame(0.5)).toBeNull();
    expect(engine.frame(0.6)?.items[lamp.definition.id]).toMatchObject({ active: true });
    expect(engine.hasFrameHandlers()).toBe(false);
    engine.dispose();
  });
});

describe("the PopTracker version, as packs test it (story 54.11, lot 4)", () => {
  it("compares versions part by part", () => {
    expect(compareVersions("0.25.9", "0.26.0")).toBeLessThan(0);
    expect(compareVersions("0.35.10", "0.35.4")).toBeGreaterThan(0);
    expect(compareVersions("1.0", "1.0.0")).toBe(0);
  });

  it("warns when a pack wants a newer PopTracker, and answers OpenLink as PopTracker validates", async () => {
    const entries = {
      "manifest.json": JSON.stringify({ name: "t", package_uid: "t", min_poptracker_version: "0.40.0", variants: { standard: {} } }),
      "scripts/init.lua": "",
    };
    const engine = await TrackerEngine.load(PackFiles.fromEntries(entries, "standard"), "standard");
    expect(engine.logs.some((log) => log.level === "warn" && log.message.includes("0.40.0"))).toBe(true);
    expect(engine.runLua(`return Tracker:OpenLink("https://archilan.fr", "site")`)).toBe(true);
    expect(engine.runLua(`return Tracker:OpenLink("javascript:alert(1)")`)).toBe(false);
    engine.dispose();
  });
});
