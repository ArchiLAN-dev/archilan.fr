import { parseItems, type ItemParseIssue, type TrackerItem } from "./items";

function parse(json: unknown[]): { items: TrackerItem[]; issues: ItemParseIssue[] } {
  let id = 1;
  const issues: ItemParseIssue[] = [];
  const items = parseItems(json, () => id++, () => undefined, issues);
  return { items, issues };
}

function one(json: Record<string, unknown>): TrackerItem {
  const [item] = parse([json]).items;
  return item;
}

const lookup = (items: TrackerItem[]) => (id: number) => items.find((item) => item.definition.id === id);
const none = () => undefined;

describe("items", () => {
  it("toggles a toggle and provides each of its codes", () => {
    const item = one({ name: "Sword", type: "toggle", codes: "sword, blade" });
    expect(item.providedCount("blade", none)).toBe(0);
    item.click("left", none);
    expect(item.providedCount("sword", none)).toBe(1);
    expect(item.providedCount("blade", none)).toBe(1);
    expect(item.providedCount("other", none)).toBe(0);
  });

  it("starts a toggle active with initial_active_state", () => {
    expect(one({ type: "toggle", codes: "a", initial_active_state: true }).isActive()).toBe(true);
  });

  it("always provides a static item", () => {
    const item = one({ type: "static", codes: "s" });
    item.click("left", none);
    expect(item.providedCount("s", none)).toBe(1);
  });

  it("counts a consumable between its bounds, with increment and decrement", () => {
    const item = one({ type: "consumable", codes: "keys", max_quantity: 4, increment: 3, decrement: 2 });
    item.click("left", none);
    expect(item.providedCount("keys", none)).toBe(3);
    item.click("left", none);
    expect(item.count).toBe(4);
    item.click("right", none);
    item.click("right", none);
    item.click("right", none);
    expect(item.count).toBe(0);
  });

  it("has no upper bound when max_quantity is below min_quantity", () => {
    const item = one({ type: "consumable", codes: "rupees", min_quantity: 1, max_quantity: 0, initial_quantity: 5 });
    expect(item.count).toBe(5);
    item.setCount(9999);
    expect(item.count).toBe(9999);
    item.setCount(-3);
    expect(item.count).toBe(1);
  });

  it("walks the stages of a progressive item, with an off state by default", () => {
    const item = one({
      type: "progressive",
      stages: [{ codes: "a" }, { codes: "b" }, { codes: "c", inherit_codes: false }],
    });
    expect(item.providedCount("a", none)).toBe(0);
    item.click("left", none);
    expect([item.providedCount("a", none), item.stage]).toEqual([1, 0]);
    item.click("left", none);
    expect([item.providedCount("a", none), item.providedCount("b", none)]).toEqual([1, 1]);
    item.click("left", none);
    expect([item.providedCount("a", none), item.providedCount("c", none)]).toEqual([0, 1]);
    item.click("left", none);
    expect(item.stage).toBe(2);
    item.click("right", none);
    item.click("right", none);
    item.click("right", none);
    expect(item.isActive()).toBe(false);
  });

  it("loops a progressive item without off state and honours initial_stage_idx", () => {
    const item = one({ type: "progressive", allow_disabled: false, loop: true, initial_stage_idx: 1, stages: [{}, {}] });
    expect([item.isActive(), item.stage]).toEqual([true, 1]);
    item.click("left", none);
    expect(item.stage).toBe(0);
    item.setActive(false);
    expect(item.isActive()).toBe(true);
  });

  it("switches a progressive_toggle on and off on left click, its stage on right click", () => {
    const item = one({ type: "progressive_toggle", loop: true, stages: [{ codes: "a" }, { codes: "b", inherit_codes: false }] });
    item.click("right", none);
    expect([item.stage, item.providedCount("b", none)]).toEqual([1, 0]);
    item.click("left", none);
    expect(item.providedCount("b", none)).toBe(1);
    item.click("right", none);
    expect(item.stage).toBe(0);
  });

  it("drives the base item of a toggle_badged on left click and its badge on right click", () => {
    const { items } = parse([
      { name: "Sword", type: "toggle", codes: "sword" },
      { name: "Spin", type: "toggle_badged", base_item: "sword", codes: "spin" },
    ]);
    const [sword, spin] = items;
    expect(spin.definition.baseItemId).toBe(sword.definition.id);
    spin.click("left", lookup(items));
    expect([sword.isActive(), spin.isActive()]).toEqual([true, false]);
    spin.click("right", lookup(items));
    expect(spin.providedCount("spin", lookup(items))).toBe(1);
  });

  it("links a composite_toggle to its left and right items", () => {
    const { items } = parse([
      { type: "toggle", codes: "left_code" },
      { type: "toggle", codes: "right_code" },
      { type: "composite_toggle", item_left: "left_code", item_right: "right_code", codes: "both" },
    ]);
    const [left, right, composite] = items;
    expect(composite.providedCount("both", lookup(items))).toBe(0);
    composite.click("right", lookup(items));
    expect([left.isActive(), right.isActive()]).toEqual([false, true]);
    expect(composite.providedCount("both", lookup(items))).toBe(1);
  });

  it("reports an unknown type and skips it", () => {
    const { items, issues } = parse([{ name: "Odd", type: "spinner" }, { type: "toggle", codes: "a" }]);
    expect(items).toHaveLength(1);
    expect(issues[0].message).toContain("spinner");
  });
});
