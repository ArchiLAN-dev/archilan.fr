import { groupMessages, mapLabel, mapPathLabel, outlineLayout } from "./layout-outline";
import type { LayoutNode } from "./layout-attributes";
import { TEST_PACK } from "./test-pack";

/** The shape of the Majora's Mask pack (G4M3RL1F3): an item dock on the left, nested tabs of maps on the right. */
const MM_LIKE: Record<string, LayoutNode> = {
  tracker_default: {
    type: "container",
    background: "#222222",
    content: {
      type: "dock",
      content: [
        {
          type: "array",
          dock: "left",
          content: [
            { type: "group", header: "ITEMS", content: { type: "layout", key: "vertical_items" } },
            { type: "group", header: "MASKS", content: { type: "itemgrid", rows: [["deku"]] } },
          ],
        },
        { type: "layout", key: "map_tabs" },
      ],
    },
  },
  vertical_items: { type: "itemgrid", rows: [["bow", "hookshot"]] },
  map_tabs: {
    type: "tabbed",
    tabs: [
      { title: "Termina", content: { type: "map", maps: ["overworld"] } },
      {
        title: "Dungeons",
        content: {
          type: "tabbed",
          tabs: [
            { title: "Woodfall Temple", content: { type: "map", maps: ["woodfall_temple"] } },
            { title: "Again", content: { type: "map", maps: ["overworld"] } },
          ],
        },
      },
    ],
  },
  items_only: { type: "group", header: "Items", content: { type: "layout", key: "vertical_items" } },
};

describe("outlineLayout", () => {
  it("takes the maps out of the nested tabs, named by them, each once", () => {
    const outline = outlineLayout(MM_LIKE.tracker_default as LayoutNode, MM_LIKE);
    expect(outline.maps).toEqual([
      { name: "overworld", path: ["Termina"] },
      { name: "woodfall_temple", path: ["Dungeons", "Woodfall Temple"] },
    ]);
    expect(outline.maps.map(mapLabel)).toEqual(["Termina", "Woodfall Temple"]);
    expect(mapPathLabel(outline.maps[1]!)).toBe("Dungeons › Woodfall Temple");
  });

  it("takes the blocks of items out in the pack's order, titled by their headers", () => {
    const outline = outlineLayout(MM_LIKE.tracker_default as LayoutNode, MM_LIKE);
    expect(outline.sections.map((section) => section.title)).toEqual(["ITEMS", "MASKS"]);
    // The header becomes the section's title; the group's children are what the panel draws.
    expect(outline.sections[0]?.nodes).toEqual([{ type: "layout", key: "vertical_items" }]);
  });

  it("gives an items-only variant its sections and no map", () => {
    const outline = outlineLayout(MM_LIKE.items_only as LayoutNode, MM_LIKE);
    expect(outline.maps).toEqual([]);
    expect(outline.sections.map((section) => section.title)).toEqual(["Items"]);
  });

  it("reads the test pack: the World map, the Items group, nothing for a tab of text", () => {
    const outline = outlineLayout(TEST_PACK.layouts.tracker_default as LayoutNode, TEST_PACK.layouts);
    expect(outline.maps).toEqual([{ name: "world", path: ["World"] }]);
    expect(outline.sections.map((section) => section.title)).toEqual(["Items"]);
  });

  it("titles a block of items by the tab it sits in, and finds nothing in an unknown structure", () => {
    const tabs: LayoutNode = {
      type: "tabbed",
      tabs: [
        { title: "Gear", content: { type: "array", content: [{ type: "item", item: "sword" }] } },
        { title: "Map", content: { type: "map", maps: ["world"] } },
      ],
    };
    expect(outlineLayout(tabs, {}).sections).toEqual([{ title: "Gear", nodes: [tabs.tabs instanceof Array ? (tabs.tabs[0] as LayoutNode).content as LayoutNode : {}] }]);
    expect(outlineLayout({ type: "something_new", content: [{ type: "text", text: "?" }] }, {})).toEqual({ maps: [], sections: [] });
  });

  it("stops on a layout that references itself", () => {
    const loop: Record<string, LayoutNode> = { a: { type: "layout", key: "a" } };
    expect(outlineLayout({ type: "layout", key: "a" }, loop)).toEqual({ maps: [], sections: [] });
  });
});

describe("groupMessages", () => {
  it("keeps each message once, in arrival order, with how often it came", () => {
    expect(groupMessages([{ message: "a" }, { message: "b" }, { message: "a" }, { message: "a" }])).toEqual([
      { message: "a", count: 3 },
      { message: "b", count: 1 },
    ]);
  });
});
