import { AccessibilityLevel } from "../engine/levels";
import { readColor, readItemSize, readMargin, readSize, readSpacing } from "./layout-attributes";
import { PackImages, parseImageMods } from "./pack-images";
import { INITIAL_STATE, section, TEST_PACK } from "./test-pack";
import { fitTransform, zoomAt } from "./tracker-map";
import {
  clearSaved,
  isSavedTracker,
  readSaved,
  restoreActions,
  snapshot,
  trackerStorageKey,
  writeSaved,
} from "./tracker-storage";
import {
  DEFAULT_SETTINGS,
  displayLevel,
  EMPTY_VIEW,
  indexItemsByCode,
  locationLevel,
  locationMarks,
  countPins,
  pinCategory,
  mergeView,
  PinMark,
  pinBackground,
  pinLabel,
  rootLayoutName,
} from "./tracker-view";

describe("tracker view (story 54.5)", () => {
  it("merges the engine diffs into the whole picture", () => {
    const view = mergeView(EMPTY_VIEW, INITIAL_STATE);
    const next = mergeView(view, { items: { 1: { active: true, stage: 0, count: 0, overlay: "" } }, sections: {}, locations: {} });
    expect(next.items[1]?.active).toBe(true);
    expect(next.items[2]).toEqual(view.items[2]);
    expect(view.items[1]?.active).toBe(false);
  });

  it("shows a sequence break as out of reach when the player hides them", () => {
    expect(displayLevel(AccessibilityLevel.SequenceBreak, DEFAULT_SETTINGS)).toBe(AccessibilityLevel.SequenceBreak);
    expect(displayLevel(AccessibilityLevel.SequenceBreak, { ...DEFAULT_SETTINGS, showSequenceBreaks: false })).toBe(
      AccessibilityLevel.None,
    );
    expect(displayLevel(AccessibilityLevel.Normal, { ...DEFAULT_SETTINGS, showSequenceBreaks: false })).toBe(
      AccessibilityLevel.Normal,
    );
  });

  it("hides a pin whose sections are all invisible", () => {
    const [woodfall] = TEST_PACK.locations;
    if (woodfall === undefined) throw new Error("fixture");
    const view = mergeView(EMPTY_VIEW, INITIAL_STATE);
    expect(locationLevel(woodfall, view)).toBe(AccessibilityLevel.None);
    const hidden = mergeView(view, {
      items: {},
      sections: {
        10: section(AccessibilityLevel.None, 1, false),
        11: section(AccessibilityLevel.None, 3, false),
        12: section(AccessibilityLevel.None, 0, false),
      },
      locations: {},
    });
    expect(locationLevel(woodfall, hidden)).toBeNull();
  });

  it("finds items by any of their codes, the first item winning", () => {
    const byCode = indexItemsByCode(TEST_PACK.items);
    expect(byCode.get("silver_arrows")?.name).toBe("Bow");
    expect(byCode.get("sword")?.name).toBe("Sword");
    expect(byCode.get("nothing")).toBeUndefined();
  });

  it("picks the root layout as PopTracker does", () => {
    expect(rootLayoutName(TEST_PACK)).toBe("tracker_default");
    expect(rootLayoutName({ ...TEST_PACK, layouts: { tracker_vertical: {}, tracker_horizontal: {} } })).toBe("tracker_horizontal");
    expect(rootLayoutName({ ...TEST_PACK, layouts: {} })).toBeNull();
  });
});

describe("layout attributes", () => {
  it("reads sizes, spacings and margins in PopTracker's formats", () => {
    expect(readSize(24)).toEqual({ width: 24, height: 24 });
    expect(readSize("32,24")).toEqual({ width: 32, height: 24 });
    expect(readSize("nope")).toBeNull();
    // Story 54.9: PopTracker's item_width / item_height, for items that are not square.
    const base = { width: 32, height: 32 };
    expect(readItemSize({ item_width: 320, item_height: 64 }, base)).toEqual({ width: 320, height: 64 });
    expect(readItemSize({ item_size: 24, item_height: 12 }, base)).toEqual({ width: 24, height: 12 });
    expect(readItemSize({}, base)).toEqual(base);
    expect(readSpacing("4,0")).toEqual({ x: 4, y: 0 });
    expect(readSpacing(3)).toEqual({ x: 3, y: 3 });
    expect(readMargin("3,1")).toEqual({ left: 3, top: 1, right: 3, bottom: 1 });
    expect(readMargin("1,2,3,4")).toEqual({ left: 1, top: 2, right: 3, bottom: 4 });
    expect(readMargin(undefined)).toBeNull();
  });

  it("turns PopTracker's alpha-first colours into CSS", () => {
    expect(readColor("#000000")).toBe("#000000");
    expect(readColor("#80112233")).toBe("#11223380");
    expect(readColor("#00000000")).toBe("#00000000");
    expect(readColor("url(x)")).toBeNull();
  });

  it("reads image modifiers", () => {
    expect(parseImageMods("@disabled,overlay|images/o.png")).toEqual({ disabled: true, overlays: ["images/o.png"] });
    expect(parseImageMods("@enabled")).toEqual({ disabled: false, overlays: [] });
    expect(parseImageMods(null)).toEqual({ disabled: false, overlays: [] });
  });
});

describe("map transform", () => {
  it("fits the whole image, centred", () => {
    expect(fitTransform({ width: 200, height: 100 }, { width: 400, height: 400 })).toEqual({ scale: 2, x: 0, y: 100 });
  });

  it("zooms around the pointer", () => {
    const zoomed = zoomAt({ scale: 1, x: 0, y: 0 }, 2, { x: 50, y: 50 });
    expect(zoomed).toEqual({ scale: 2, x: -50, y: -50 });
    expect(zoomAt({ scale: 8, x: 0, y: 0 }, 2, { x: 0, y: 0 }).scale).toBe(8);
    // Story 54.9: never below the whole map, which comes back centred.
    const bounds = { image: { width: 200, height: 100 }, viewport: { width: 400, height: 400 } };
    expect(zoomAt({ scale: 3, x: -50, y: 20 }, 0.5, { x: 0, y: 0 }, bounds)).toEqual({ scale: 2, x: 0, y: 100 });
    expect(zoomAt({ scale: 3, x: 0, y: 0 }, 1.2, { x: 0, y: 0 }, bounds).scale).toBeCloseTo(3.6);
  });
});

describe("pack images", () => {
  it("reads each image once and tells the listeners", async () => {
    const load = jest.fn(async (path: string) => (path === "a.png" ? new Blob(["a"]) : null));
    const revoke = jest.fn();
    const images = new PackImages(load, () => "blob:a", revoke);
    const listener = jest.fn();
    images.subscribe(listener);
    images.request("a.png");
    images.request("a.png");
    images.request("missing.png");
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    expect(load).toHaveBeenCalledTimes(2);
    expect(images.get("a.png")).toBe("blob:a");
    expect(images.get("missing.png")).toBeNull();
    expect(listener).toHaveBeenCalled();
    images.dispose();
    expect(revoke).toHaveBeenCalledWith("blob:a");
  });
});

describe("tracker storage", () => {
  const initial = mergeView(EMPTY_VIEW, INITIAL_STATE);

  afterEach(() => {
    jest.restoreAllMocks();
  });

  it("keys the state by pack, version and variant", () => {
    expect(trackerStorageKey(TEST_PACK)).toBe("tracker:test_pack:1.0.0:standard");
  });

  it("saves only what changed and restores it with engine actions", () => {
    const played = mergeView(initial, {
      items: { 1: { active: true, stage: 0, count: 0, overlay: "" }, 2: { active: true, stage: 0, count: 2, overlay: "" } },
      sections: { 11: section(AccessibilityLevel.None, 1) },
      locations: {},
    });
    const saved = snapshot(TEST_PACK, initial, played);
    expect(saved).toEqual({
      version: 1,
      items: { 1: { active: true, stage: 0, count: 0 }, 2: { active: true, stage: 0, count: 2 } },
      sections: { 11: 1 },
    });
    expect(isSavedTracker(JSON.parse(JSON.stringify(saved)))).toBe(true);
    expect(
      restoreActions(TEST_PACK, initial, { ...saved, items: { ...saved.items, 99: { active: true, stage: 0, count: 0 } } }),
    ).toEqual([
      { type: "itemSet", itemId: 1, state: { active: true, stage: 0, count: 0 } },
      { type: "itemSet", itemId: 2, state: { active: true, stage: 0, count: 2 } },
      { type: "sectionClear", sectionId: 11, count: 2 },
    ]);
  });

  it("compares with the state the pack loaded in, not with item_count", () => {
    // A pack's Lua may set a counter section to 1 check left of 3 when it loads (Majora's Mask does).
    const loaded = mergeView(initial, { items: {}, sections: { 11: section(AccessibilityLevel.None, 1) }, locations: {} });
    expect(snapshot(TEST_PACK, loaded, loaded)).toEqual({ version: 1, items: {}, sections: {} });
    // Undone past its load state: a section not cleared as a group comes back one check at a time.
    const undone = mergeView(loaded, { items: {}, sections: { 11: section(AccessibilityLevel.None, 3) }, locations: {} });
    const saved = snapshot(TEST_PACK, loaded, undone);
    expect(saved.sections).toEqual({ 11: 3 });
    expect(restoreActions(TEST_PACK, loaded, saved)).toEqual([
      { type: "sectionUnclear", sectionId: 11 },
      { type: "sectionUnclear", sectionId: 11 },
    ]);
  });

  it("round-trips through localStorage and forgets an empty state", () => {
    const store = new Map<string, string>();
    const storage = {
      getItem: (key: string) => store.get(key) ?? null,
      setItem: (key: string, value: string) => void store.set(key, value),
      removeItem: (key: string) => void store.delete(key),
    };
    Object.defineProperty(globalThis, "window", { value: { localStorage: storage }, configurable: true });
    try {
      const saved = { version: 1 as const, items: { 1: { active: true, stage: 0, count: 0 } }, sections: {} };
      writeSaved("k", saved);
      expect(readSaved("k")).toEqual(saved);
      writeSaved("k", { version: 1, items: {}, sections: {} });
      expect(store.has("k")).toBe(false);
      store.set("k", "{broken");
      expect(readSaved("k")).toBeNull();
      store.set("k", JSON.stringify({ version: 2 }));
      expect(readSaved("k")).toBeNull();
      clearSaved("k");
      expect(store.has("k")).toBe(false);
    } finally {
      Reflect.deleteProperty(globalThis, "window");
    }
  });

  it("keeps working when the browser refuses storage", () => {
    const refuse = () => {
      throw new Error("SecurityError");
    };
    Object.defineProperty(globalThis, "window", {
      value: { localStorage: { getItem: refuse, setItem: refuse, removeItem: refuse } },
      configurable: true,
    });
    try {
      expect(readSaved("k")).toBeNull();
      expect(() => writeSaved("k", { version: 1, items: { 1: { active: true, stage: 0, count: 0 } }, sections: {} })).not.toThrow();
      expect(() => clearSaved("k")).not.toThrow();
    } finally {
      Reflect.deleteProperty(globalThis, "window");
    }
  });
});

describe("PopTracker's pins (story 54.9)", () => {
  const location = TEST_PACK.locations[0]!;
  const view = (sections: Record<number, ReturnType<typeof section>>) => mergeView(EMPTY_VIEW, { items: {}, sections, locations: {} });

  it("gathers one state per section still to do", () => {
    const marks = locationMarks(location, view({ 10: section(AccessibilityLevel.Normal, 1), 11: section(AccessibilityLevel.None, 1), 12: section(AccessibilityLevel.Cleared, 0) }), DEFAULT_SETTINGS);
    expect(marks).toBe(PinMark.Reachable | PinMark.Unreachable);
    expect(pinLabel(marks ?? -1)).toBe("En partie : faisable, pas encore");
    expect(pinLabel(PinMark.Inspect)).toBe("À regarder");
  });

  it("is done when every visible section is, hidden when none is visible", () => {
    expect(locationMarks(location, view({ 10: section(AccessibilityLevel.Cleared, 0) }), DEFAULT_SETTINGS)).toBe(0);
    expect(pinLabel(0)).toBe("Fait");
    expect(locationMarks(location, view({ 10: section(AccessibilityLevel.Normal, 1, false) }), DEFAULT_SETTINGS)).toBeNull();
  });

  it("counts a partial section as a glitch, and a sequence break as out of reach when they are hidden", () => {
    expect(locationMarks(location, view({ 10: section(AccessibilityLevel.Partial, 1) }), DEFAULT_SETTINGS)).toBe(PinMark.SequenceBreak);
    expect(
      locationMarks(location, view({ 10: section(AccessibilityLevel.SequenceBreak, 1) }), { showSequenceBreaks: false, hideCleared: false }),
    ).toBe(PinMark.Unreachable);
  });

  it("draws one colour, or the quarters PopTracker gives the combination", () => {
    expect(pinBackground(PinMark.Reachable)).toBe("var(--color-tracker-normal)");
    expect(pinBackground(PinMark.Reachable | PinMark.Unreachable)).toBe(
      "conic-gradient(from -45deg, var(--color-tracker-none) 0 25%, var(--color-tracker-normal) 0 50%, var(--color-tracker-normal) 0 75%, var(--color-tracker-none) 0 100%)",
    );
  });
});

describe("the legend's counters (story 54.9)", () => {
  it("files a pin by its states, and counts the pins of one map", () => {
    expect(pinCategory(0)).toBe("done");
    expect(pinCategory(PinMark.Inspect)).toBe("inspect");
    expect(pinCategory(PinMark.Reachable | PinMark.Unreachable)).toBe("mixed");
    const view = mergeView(EMPTY_VIEW, INITIAL_STATE);
    expect(countPins(TEST_PACK.locations, "world", view, DEFAULT_SETTINGS)).toEqual({
      reachable: 1,
      sequence: 0,
      inspect: 0,
      unreachable: 1,
      mixed: 0,
      done: 0,
    });
    expect(countPins(TEST_PACK.locations, "elsewhere", view, DEFAULT_SETTINGS).reachable).toBe(0);
  });
});
