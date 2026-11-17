/**
 * @jest-environment ./src/tests/jsdom-environment.mjs
 */
import { act } from "react";
import { createRoot, type Root } from "react-dom/client";

import { AccessibilityLevel, Highlight } from "../engine/levels";
import type { EngineAction, StateUpdate } from "../engine/protocol";
import { PackImages } from "./pack-images";
import { INITIAL_STATE, section, TEST_PACK } from "./test-pack";
import { TrackerContextProvider, type TrackerScreenContext } from "./tracker-context";
import { TrackerLayout } from "./tracker-layout";
import { DEFAULT_SETTINGS, EMPTY_VIEW, indexItemsByCode, mergeView, type TrackerSettings } from "./tracker-view";

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

class NoResizeObserver {
  observe() {}
  disconnect() {}
  unobserve() {}
}

beforeAll(() => {
  Object.defineProperty(window, "ResizeObserver", { value: NoResizeObserver, configurable: true });
  Object.defineProperty(window, "matchMedia", {
    configurable: true,
    value: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }),
  });
});

let container: HTMLDivElement;
let root: Root;
let acted: EngineAction[];
let unsupported: string[];

beforeEach(() => {
  container = document.createElement("div");
  document.body.appendChild(container);
  root = createRoot(container);
  acted = [];
  unsupported = [];
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
  jest.useRealTimers();
});

async function render(update: Partial<StateUpdate> = {}, settings: TrackerSettings = DEFAULT_SETTINGS, layout = "tracker_default") {
  const view = mergeView(mergeView(EMPTY_VIEW, INITIAL_STATE), { items: {}, sections: {}, locations: {}, ...update });
  const images = new PackImages(
    async (path) => new Blob([path]),
    (blob) => `blob:${blob.size}`,
    () => undefined,
  );
  const context: TrackerScreenContext = {
    pack: TEST_PACK,
    view,
    itemsByCode: indexItemsByCode(TEST_PACK.items),
    itemsById: new Map(TEST_PACK.items.map((item) => [item.id, item])),
    settings,
    images,
    act: (action) => acted.push(action),
    reportUnsupported: (type) => unsupported.push(type),
  };
  await act(async () => {
    root.render(
      <TrackerContextProvider value={context}>
        <TrackerLayout name={layout} />
      </TrackerContextProvider>,
    );
  });
  // Images arrive asynchronously, then the map image fires `load`.
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
  const mapImage = container.querySelector<HTMLImageElement>('[aria-label="Carte world"] img');
  if (mapImage !== null) {
    Object.defineProperty(mapImage, "naturalWidth", { value: 400 });
    Object.defineProperty(mapImage, "naturalHeight", { value: 200 });
    await act(async () => {
      mapImage.dispatchEvent(new Event("load"));
    });
  }
}

function byLabel(label: string): HTMLElement {
  const found = container.querySelector<HTMLElement>(`[aria-label="${label}"]`);
  if (found === null) throw new Error(`nothing labelled ${label}`);
  return found;
}

function fire(target: Element, event: Event) {
  act(() => {
    target.dispatchEvent(event);
  });
}

function pointer(type: string, target: Element, pointerType: string) {
  const event = new MouseEvent(type, { bubbles: true, cancelable: true });
  Object.defineProperty(event, "pointerType", { value: pointerType });
  Object.defineProperty(event, "pointerId", { value: 1 });
  fire(target, event);
}

describe("tracker layout widgets (story 54.5)", () => {
  it("draws container, dock, group, layout, array, itemgrid, item and tabbed widgets", async () => {
    await render();
    expect(container.querySelector('[data-widget="container"]')).not.toBeNull();
    expect(container.querySelector('[data-dock="left"]')).not.toBeNull();
    expect(container.querySelector("h3")?.textContent).toBe("Items");
    for (const label of ["Sword", "Bombs : 0", "Bow", "Ocarina", "Crystal"]) expect(byLabel(label).tagName).toBe("BUTTON");
    expect(container.querySelectorAll('[role="tab"]')).toHaveLength(2);
    expect(container.querySelector('[aria-label="Carte world"]')).not.toBeNull();
  });

  it("switches tabs and draws text widgets", async () => {
    await render();
    const notes = Array.from(container.querySelectorAll('[role="tab"]')).find((tab) => tab.textContent === "Notes");
    if (notes === undefined) throw new Error("no Notes tab");
    fire(notes, new MouseEvent("click", { bubbles: true }));
    expect(container.textContent).toContain("Hello");
    expect(container.querySelector('[aria-label="Carte world"]')).toBeNull();
  });

  it("reports the widgets it does not draw, once", async () => {
    await render();
    expect(unsupported).toEqual(["recentpins"]);
  });

  it("places the children of a canvas at their position and size (story 54.8)", async () => {
    await render({}, DEFAULT_SETTINGS, "canvas_board");
    const board = container.querySelector<HTMLElement>('[data-widget="canvas"] > div');
    expect(board?.style.width).toBe("200px");
    const placed = board?.firstElementChild as HTMLElement | null;
    expect(placed?.style.left).toBe("120px");
    expect(placed?.style.top).toBe("50px");
    expect(byLabel("Bow").style.getPropertyValue("--item-w")).toBe("40px");
    expect(unsupported).toEqual([]);
  });

  it("draws a Lua item with the icon and name its state carries (story 54.8)", async () => {
    await render({ items: { 3: { active: true, stage: 0, count: 0, overlay: "", icon: { img: "images/pokemon/25.png", imgMods: "" }, name: "Evolve Pikachu" } } });
    expect(container.querySelector('[aria-label="Evolve Pikachu"]')).not.toBeNull();
  });

  it("honours item_size and the alpha-first background", async () => {
    await render();
    expect(byLabel("Sword").style.getPropertyValue("--item-w")).toBe("24px");
    expect(byLabel("Crystal").style.getPropertyValue("--item-w")).toBe("32px");
    const background = container.querySelector<HTMLElement>('[data-widget="container"]')?.style.background ?? "";
    expect(background.replaceAll(" ", "")).toMatch(/rgba\(0,0,0,0\.5\d*\)|#00000080/);
  });
});

describe("tracker items", () => {
  it("advances on a click and goes back on a right click, without the context menu", async () => {
    await render();
    fire(byLabel("Sword"), new MouseEvent("click", { bubbles: true }));
    const menu = new MouseEvent("contextmenu", { bubbles: true, cancelable: true });
    fire(byLabel("Bow"), menu);
    expect(menu.defaultPrevented).toBe(true);
    expect(acted).toEqual([
      { type: "itemClick", itemId: 1, button: "left" },
      { type: "itemClick", itemId: 3, button: "right" },
    ]);
  });

  it("changes a consumable with the wheel and shows its count", async () => {
    await render({ items: { 2: { active: true, stage: 0, count: 2, overlay: "" } } });
    const bombs = byLabel("Bombs : 2");
    expect(bombs.textContent).toBe("2");
    const up = new WheelEvent("wheel", { bubbles: true, cancelable: true, deltaY: -100 });
    fire(bombs, up);
    fire(bombs, new WheelEvent("wheel", { bubbles: true, cancelable: true, deltaY: 100 }));
    expect(up.defaultPrevented).toBe(true);
    expect(acted).toEqual([
      { type: "itemClick", itemId: 2, button: "left" },
      { type: "itemClick", itemId: 2, button: "right" },
    ]);
  });

  it("goes back on a long press, and the click that ends it does nothing", async () => {
    await render();
    jest.useFakeTimers();
    const sword = byLabel("Sword");
    pointer("pointerdown", sword, "touch");
    act(() => {
      jest.advanceTimersByTime(600);
    });
    pointer("pointerup", sword, "touch");
    fire(sword, new MouseEvent("click", { bubbles: true }));
    expect(acted).toEqual([{ type: "itemClick", itemId: 1, button: "right" }]);
  });

  it("greys inactive items and marks the static ones as not pressable", async () => {
    await render({ items: { 1: { active: true, stage: 0, count: 0, overlay: "" } } });
    expect(byLabel("Sword").getAttribute("aria-pressed")).toBe("true");
    expect(byLabel("Bow").getAttribute("aria-pressed")).toBe("false");
    expect(byLabel("Bow").querySelector("img")?.className).toContain("grayscale");
    expect(byLabel("Sword").querySelector("img")?.className).not.toContain("grayscale");
    expect(byLabel("Ocarina").hasAttribute("aria-pressed")).toBe(false);
  });
});

describe("map pins and the location popover", () => {
  it("colours each pin by its level", async () => {
    await render();
    expect(byLabel("Woodfall : Inaccessible").className).toContain("bg-tracker-none");
    expect(byLabel("Swamp : Accessible").className).toContain("bg-tracker-normal");
    expect(byLabel("Swamp : Accessible").className).toContain("rotate-45");
  });

  it("follows the settings: sequence breaks and what is done", async () => {
    await render(
      {
        sections: { 10: section(AccessibilityLevel.SequenceBreak, 1), 20: section(AccessibilityLevel.Cleared, 0) },
        locations: { 1: AccessibilityLevel.SequenceBreak, 2: AccessibilityLevel.Cleared },
      },
      { showSequenceBreaks: false, hideCleared: true },
    );
    expect(byLabel("Woodfall : Inaccessible")).not.toBeNull();
    expect(container.querySelector('[aria-label^="Swamp"]')).toBeNull();
  });

  it("marks the hinted locations and sections, but not what is done (story 54.6)", async () => {
    await render({
      sections: {
        10: { ...section(AccessibilityLevel.Normal, 1), highlight: Highlight.Priority },
        20: { ...section(AccessibilityLevel.Cleared, 0), highlight: Highlight.Priority },
      },
    });
    const pin = byLabel("Woodfall : Inaccessible, indice : important");
    expect(pin.querySelector("[data-hint]")).not.toBeNull();
    expect(byLabel("Swamp : Accessible").querySelector("[data-hint]")).toBeNull();
    fire(pin, new MouseEvent("click", { bubbles: true }));
    expect(byLabel("Entrance : Accessible, indice : important")).not.toBeNull();
    expect(byLabel("Pots : Inaccessible")).not.toBeNull();
  });

  it("lists the sections of a location; a click does one, a right click undoes it", async () => {
    await render({ sections: { 10: section(AccessibilityLevel.Normal, 1), 11: section(AccessibilityLevel.Normal, 2) } });
    const pin = container.querySelector('[aria-label^="Woodfall"]');
    if (pin === null) throw new Error("no pin");
    fire(pin, new MouseEvent("click", { bubbles: true }));
    const dialog = container.querySelector('[role="dialog"]');
    expect(dialog?.textContent).toContain("Entrance");
    expect(dialog?.textContent).toContain("2/3");
    fire(byLabel("Entrance : Accessible"), new MouseEvent("click", { bubbles: true }));
    fire(byLabel("Pots : Accessible"), new MouseEvent("contextmenu", { bubbles: true, cancelable: true }));
    expect(acted).toEqual([
      { type: "sectionClear", sectionId: 10 },
      { type: "sectionUnclear", sectionId: 11 },
    ]);
    // The hosted item of the boss section is drawn next to it.
    expect(dialog?.querySelector('[aria-label="Crystal"]')).not.toBeNull();
    fire(byLabel("Fermer"), new MouseEvent("click", { bubbles: true }));
    expect(container.querySelector('[role="dialog"]')).toBeNull();
  });
});
