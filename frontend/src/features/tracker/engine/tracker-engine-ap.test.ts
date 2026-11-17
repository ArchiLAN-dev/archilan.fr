import { join } from "node:path";

import type { ApEvent, ApOutgoing } from "./ap-events";
import { Highlight } from "./levels";
import { packFromFolder } from "./pack-from-folder";
import { TrackerEngine } from "./tracker-engine";

/**
 * Story 54.6: the engine calls the pack's Archipelago handlers (Clear, Item, Location, Retrieved, SetReply) with the
 * events the page translates from the room, as PopTracker does.
 */
const MINI_PACK = join(__dirname, "__fixtures__", "mini-pack");

const CONNECTED: ApEvent = {
  kind: "connected",
  playerNumber: 2,
  teamNumber: 0,
  seed: "123",
  game: "Mini Game",
  checked: [101],
  missing: [100, 102],
  players: [
    { slot: 1, team: 0, alias: "Alice", name: "Alice", game: "Other Game" },
    { slot: 2, team: 0, alias: "Bob (MM)", name: "Bob", game: "Mini Game" },
  ],
  names: {
    "Mini Game": { items: { 1: "Sword", 2: "Progressive Bow", 3: "Bombs" }, locations: { 100: "Hut Chest", 101: "Hut Pots", 102: "Flower" } },
    "Other Game": { items: { 1: "Hammer" }, locations: {} },
  },
};

describe("TrackerEngine and Archipelago", () => {
  let engine: TrackerEngine;
  let sent: ApOutgoing[];

  beforeEach(async () => {
    sent = [];
    engine = await TrackerEngine.load(packFromFolder(MINI_PACK, "standard"), "standard", {
      onApSend: (packets) => sent.push(...packets),
    });
    engine.initialState();
  });

  afterEach(() => engine.dispose());

  function item(code: string) {
    const found = engine.findItem(code);
    if (found === undefined) throw new Error(code);
    return found;
  }

  function section(path: string) {
    const found = engine.pack().locations.flatMap((location) => location.sections).find((candidate) => candidate.path === path);
    if (found === undefined) throw new Error(path);
    return found;
  }

  function calls(): string[] {
    const value = engine.runLua("return table.concat(CALLS, '|')");
    return typeof value === "string" && value !== "" ? value.split("|") : [];
  }

  it("sets up the room, clears with the slot data, then replays the done locations and the items, in one pass", () => {
    const state = engine.applyAp([
      CONNECTED,
      { kind: "clear", slotData: { start_bombs: 2, nested: { list: [1, null, "éé"] } } },
      { kind: "locations", locations: [101] },
      {
        kind: "items",
        items: [
          { index: 0, item: 1, player: 1, location: 5, flags: 1 },
          { index: 1, item: 2, player: 2, location: 6, flags: 1 },
          { index: 2, item: 2, player: 2, location: 7, flags: 1 },
          { index: 3, item: 3, player: 1, location: 8, flags: 0 },
        ],
      },
    ]);

    expect(engine.runLua("return Archipelago.PlayerNumber")).toBe(2);
    expect(engine.runLua("return AutoTracker:GetConnectionState('AP')")).toBe(3);
    expect(engine.runLua("return SLOT_DATA.nested.list[3]")).toBe("éé");
    expect(engine.runLua("return math.type(SLOT_DATA.start_bombs)")).toBe("integer");
    expect(calls()).toEqual([
      "clear",
      "location 101 Hut Pots",
      "item 0 Sword 1",
      "item 1 Progressive Bow 2",
      "item 2 Progressive Bow 2",
      "item 3 Bombs 1",
    ]);
    expect(item("sword").state().active).toBe(true);
    expect(item("bow").state()).toMatchObject({ active: true, stage: 1 });
    expect(item("bombs").state().count).toBe(3);
    const pots = section("Forest/Hut/Pots");
    expect(state.sections[pots.id]?.available).toBe(0);
    expect(state.items[item("sword").definition.id]?.active).toBe(true);
    // The pack subscribed to its hints key through the page.
    expect(sent).toEqual([
      { cmd: "SetNotify", keys: ["_read_hints_0_2"] },
      { cmd: "Get", keys: ["_read_hints_0_2"] },
    ]);
  });

  it("answers the name and player questions from the room tables", () => {
    engine.applyAp([CONNECTED]);
    expect(engine.runLua("return Archipelago:GetPlayerAlias(1)")).toBe("Alice");
    expect(engine.runLua("return Archipelago:GetPlayerGame(2)")).toBe("Mini Game");
    expect(engine.runLua("return Archipelago:GetItemName(1, 'Other Game')")).toBe("Hammer");
    expect(engine.runLua("return Archipelago:GetLocationName(102)")).toBe("Flower");
    expect(engine.runLua("return Archipelago:GetItemName(99)")).toBeNull();
  });

  it("marks hinted sections from the data storage, at Get and at each update", () => {
    engine.applyAp([CONNECTED, { kind: "clear", slotData: {} }]);
    const hints = [{ finding_player: 2, location: 100, status: 30 }];
    const state = engine.applyAp([{ kind: "retrieved", key: "_read_hints_0_2", value: hints }]);
    const chest = section("Forest/Hut/Chest");
    expect(state.sections[chest.id]?.highlight).toBe(Highlight.Priority);

    const updated = engine.applyAp([
      { kind: "setReply", key: "_read_hints_0_2", value: [{ finding_player: 2, location: 102, status: 0 }], originalValue: hints },
    ]);
    expect(updated.sections[section("Forest/Glade/Flower").id]?.highlight).toBe(Highlight.Unspecified);
  });

  it("starts over on a new clear: a resync replays everything without doubling", () => {
    const items = [
      { index: 0, item: 3, player: 1, location: 5, flags: 0 },
      { index: 1, item: 3, player: 1, location: 6, flags: 0 },
    ];
    engine.applyAp([CONNECTED, { kind: "clear", slotData: {} }, { kind: "items", items }]);
    expect(item("bombs").state().count).toBe(2);
    // An index already seen is ignored by the pack...
    engine.applyAp([{ kind: "items", items: [items[1]!] }]);
    expect(item("bombs").state().count).toBe(2);
    // ...and a reconnection clears first, then replays from index 0.
    engine.applyAp([{ kind: "disconnected" }, CONNECTED, { kind: "clear", slotData: {} }, { kind: "items", items }]);
    expect(item("bombs").state().count).toBe(2);
  });

  it("keeps going when a handler fails, and tells why", () => {
    engine.runLua("Archipelago:AddLocationHandler('broken', function() error('boom') end)");
    engine.applyAp([CONNECTED, { kind: "locations", locations: [100] }]);
    expect(engine.logs.some((log) => log.level === "error" && log.message.includes("boom"))).toBe(true);
    expect(engine.sectionLevel("Forest/Hut/Chest")).not.toBeNull();
    expect(calls()).toContain("location 100 Hut Chest");
  });

  it("refuses what would write to the room", () => {
    expect(() => engine.runLua("Archipelago:LocationChecks({ 100 })")).toThrow(/unsupported_api/);
    expect(engine.unsupportedCalls.has("Archipelago:LocationChecks")).toBe(true);
    // Before the room is connected, Get asks nothing.
    expect(engine.runLua("return Archipelago:Get({ 'k' })")).toBe(false);
    expect(sent).toEqual([]);
  });
});
