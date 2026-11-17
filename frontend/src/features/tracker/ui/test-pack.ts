import type { ItemDefinition, ItemStage } from "../engine/items";
import { AccessibilityLevel, Highlight } from "../engine/levels";
import type { LoadedPack, SectionState, StateUpdate } from "../engine/protocol";

/** A loaded pack written by hand for the screen tests (story 54.5). */
function stage(img: string, codes: string[], name: string | null = null): ItemStage {
  return { img, imgMods: "", disabledImg: null, disabledImgMods: null, name, codes };
}

function item(id: number, name: string, type: ItemDefinition["type"], stages: ItemStage[], extra: Partial<ItemDefinition> = {}): ItemDefinition {
  return {
    id,
    name,
    type,
    stages,
    minQuantity: 0,
    maxQuantity: 0,
    baseItemId: null,
    leftItemId: null,
    rightItemId: null,
    compositeImages: [],
    overlayBackground: null,
    overlayFontSize: null,
    ...extra,
  };
}

export const SWORD = item(1, "Sword", "toggle", [stage("images/sword.png", ["sword"])]);
export const BOMBS = item(2, "Bombs", "consumable", [stage("images/bombs.png", ["bombs"])], { maxQuantity: 3 });
export const BOW = item(3, "Bow", "progressive", [
  stage("images/bow.png", ["bow"], "Bow"),
  stage("images/silver.png", ["silver_arrows"], "Silver arrows"),
]);
export const CRYSTAL = item(4, "Crystal", "toggle", [stage("images/crystal.png", ["crystal"])]);
export const OCARINA = item(5, "Ocarina", "static", [stage("images/ocarina.png", ["ocarina"])]);

export const TEST_PACK: LoadedPack = {
  manifest: {
    name: "Test pack",
    gameName: "Test game",
    packageUid: "test_pack",
    packageVersion: "1.0.0",
    platform: "pc",
    variants: [
      { uid: "standard", displayName: "Maps and items", flags: ["ap"] },
      { uid: "itemsonly", displayName: "Items only", flags: ["ap"] },
    ],
    minPopTrackerVersion: null,
    targetPopTrackerVersion: null,
  },
  variantUid: "standard",
  items: [SWORD, BOMBS, BOW, CRYSTAL, OCARINA],
  maps: [{ name: "world", img: "images/world.png", locationSize: 20, locationBorderThickness: 2, locationShape: null }],
  layouts: {
    tracker_default: {
      type: "container",
      background: "#80000000",
      content: {
        type: "dock",
        content: [
          { type: "group", header: "Items", dock: "left", content: { type: "layout", key: "items" } },
          { type: "tabbed", tabs: [{ title: "World", content: { type: "map", maps: ["world"] } }, { title: "Notes", content: { type: "text", text: "Hello" } }] },
        ],
      },
    },
    canvas_board: {
      type: "canvas",
      width: 200,
      height: 100,
      content: [{ type: "item", item: "bow", width: 40, height: 30, canvas_left: 120, canvas_top: 50 }],
    },
    items: {
      type: "array",
      orientation: "vertical",
      margin: "3,1",
      content: [
        { type: "itemgrid", item_size: 24, item_margin: "4,0", rows: [["sword", "bombs", ""], ["bow", "ocarina", "unknown"]] },
        { type: "item", item: "crystal" },
        { type: "recentpins" },
      ],
    },
  },
  locations: [
    {
      id: 1,
      path: "Woodfall",
      name: "Woodfall",
      parentId: null,
      mapLocations: [
        { map: "world", x: 100, y: 50, size: null, borderThickness: null, shape: null, restrictVisibilityRules: [], forceInvisibilityRules: [] },
      ],
      images: { unopened: null, opened: null },
      overlayBackground: null,
      sections: [
        { id: 10, path: "Woodfall/Entrance", name: "Entrance", itemCount: 1, hostedItems: [], clearAsGroup: true, refId: null, images: { unopened: null, opened: null } },
        { id: 11, path: "Woodfall/Pots", name: "Pots", itemCount: 3, hostedItems: [], clearAsGroup: false, refId: null, images: { unopened: null, opened: null } },
        { id: 12, path: "Woodfall/Boss", name: "Boss", itemCount: 0, hostedItems: ["crystal"], clearAsGroup: true, refId: null, images: { unopened: null, opened: null } },
      ],
    },
    {
      id: 2,
      path: "Swamp",
      name: "Swamp",
      parentId: null,
      mapLocations: [
        { map: "world", x: 10, y: 10, size: 12, borderThickness: 1, shape: "diamond", restrictVisibilityRules: [], forceInvisibilityRules: [] },
      ],
      images: { unopened: null, opened: null },
      overlayBackground: null,
      sections: [
        { id: 20, path: "Swamp/Chest", name: "Chest", itemCount: 1, hostedItems: [], clearAsGroup: true, refId: null, images: { unopened: null, opened: null } },
      ],
    },
  ],
};

export function section(level: AccessibilityLevel, available: number, visible = true): SectionState {
  return { available, level, visible, highlight: Highlight.None };
}

export const INITIAL_STATE: StateUpdate = {
  items: {
    1: { active: false, stage: 0, count: 0, overlay: "" },
    2: { active: false, stage: 0, count: 0, overlay: "" },
    3: { active: false, stage: 0, count: 0, overlay: "" },
    4: { active: false, stage: 0, count: 0, overlay: "" },
    5: { active: true, stage: 0, count: 0, overlay: "" },
  },
  sections: {
    10: section(AccessibilityLevel.None, 1),
    11: section(AccessibilityLevel.None, 3),
    12: section(AccessibilityLevel.None, 0),
    20: section(AccessibilityLevel.Normal, 1),
  },
  locations: { 1: AccessibilityLevel.None, 2: AccessibilityLevel.Normal },
};
