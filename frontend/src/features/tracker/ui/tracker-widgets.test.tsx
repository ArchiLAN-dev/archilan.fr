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
import { TrackerBoard } from "./tracker-board";
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

async function render(
  update: Partial<StateUpdate> = {},
  settings: TrackerSettings = DEFAULT_SETTINGS,
  layout = "tracker_default",
  board = false,
) {
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
        {board ? <TrackerBoard root={layout} /> : <TrackerLayout name={layout} />}
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
    // Story 54.9: the board sits in a frame that shrinks it to the room it has (full size here).
    const frame = container.querySelector<HTMLElement>('[data-widget="canvas"] > div');
    expect(frame?.style.height).toBe("100px");
    const board = frame?.querySelector<HTMLElement>(".relative") ?? null;
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

  it("honours item_size and draws the site's colours, not the pack's background (story 54.9)", async () => {
    await render();
    expect(byLabel("Sword").style.getPropertyValue("--item-w")).toBe("24px");
    expect(byLabel("Crystal").style.getPropertyValue("--item-w")).toBe("32px");
    const background = container.querySelector<HTMLElement>('[data-widget="container"]')?.style.background ?? "";
    expect(background).toBe("");
  });
});

describe("the ArchiLAN layout of a pack (story 54.9)", () => {
  function click(target: Element) {
    fire(target, new MouseEvent("click", { bubbles: true }));
  }

  function button(text: string): HTMLButtonElement {
    const found = Array.from(container.querySelectorAll("button")).find((candidate) => candidate.textContent?.trim() === text);
    if (found === undefined) throw new Error(`no button ${text}`);
    return found;
  }

  it("puts the items in a side panel by section and the map apart, without the pack's tabs of text", async () => {
    await render({}, DEFAULT_SETTINGS, "tracker_default", true);
    const panel = byLabel("Objets");
    expect(panel.querySelector("h3")?.textContent).toBe("Items");
    expect(panel.querySelector('[aria-label="Sword"]')).not.toBeNull();
    expect(byLabel("Cartes").querySelector('[aria-label="Carte world"]')).not.toBeNull();
    expect(container.textContent).not.toContain("Hello");
    // On a phone, two tabs; the map one first.
    expect(button("Cartes").getAttribute("aria-selected")).toBe("true");
    click(button("Objets"));
    expect(panel.className).toContain("flex");
    expect(byLabel("Cartes").className).toMatch(/(^| )hidden( |$)/);
  });

  it("folds the items away on a wide screen and brings them back", async () => {
    await render({}, DEFAULT_SETTINGS, "tracker_default", true);
    click(button("Replier"));
    expect(byLabel("Objets").className).toContain("lg:hidden");
    click(byLabel("Afficher les objets"));
    expect(byLabel("Objets").className).toContain("lg:flex");
  });

  it("gives the whole width to the items of a pack without map", async () => {
    await render({}, DEFAULT_SETTINGS, "items", true);
    expect(byLabel("Objets").className).toContain("w-full");
    expect(container.querySelector('[aria-label="Cartes"]')).toBeNull();
    expect(container.querySelector('[role="tablist"]')).toBeNull();
  });

  it("counts the pins of the map by colour, and a counter narrows the map to them (story 54.9)", async () => {
    await render({}, DEFAULT_SETTINGS, "tracker_default", true);
    const legend = byLabel("Légende de la carte");
    expect(byLabel("Cartes").contains(legend)).toBe(true);
    expect(legend.textContent).toContain("1 faisable");
    expect(legend.textContent).toContain("1 pas encore");
    // Nothing of a colour absent from the map.
    expect(legend.textContent).not.toContain("hors logique");

    const reachable = Array.from(legend.querySelectorAll("button")).find((candidate) => candidate.textContent === "1 faisable");
    if (reachable === undefined) throw new Error("no counter");
    click(reachable);
    expect(reachable.getAttribute("aria-pressed")).toBe("true");
    expect(container.querySelector('[aria-label^="Swamp"]')).not.toBeNull();
    expect(container.querySelector('[aria-label^="Woodfall"]')).toBeNull();
    click(button("Tout afficher"));
    expect(container.querySelector('[aria-label^="Woodfall"]')).not.toBeNull();
  });

  it("explains the colours in plain words on demand (story 54.9)", async () => {
    await render({}, DEFAULT_SETTINGS, "tracker_default", true);
    expect(container.textContent).not.toContain("Il te manque encore quelque chose.");
    click(byLabel("Que veulent dire les couleurs ?"));
    expect(container.textContent).toContain("Il te manque encore quelque chose.");
    expect(container.textContent).toContain("Hors logique");
  });

  it("draws a layout it cannot read the way the pack writes it", async () => {
    await render({}, DEFAULT_SETTINGS, "no_such_layout", true);
    expect(container.querySelector('[data-board="pack"]')).not.toBeNull();
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
  it("colours each pin by its level, with PopTracker's colours", async () => {
    await render();
    expect(byLabel("Woodfall : Pas encore").style.background).toBe("var(--color-tracker-none)");
    expect(byLabel("Swamp : Faisable").style.background).toBe("var(--color-tracker-normal)");
    expect(byLabel("Swamp : Faisable").className).toContain("rotate-45");
  });

  it("splits the pin of a location whose sections differ, as PopTracker does (story 54.9)", async () => {
    await render({ sections: { 10: section(AccessibilityLevel.Normal, 1) } });
    const pin = byLabel("Woodfall : En partie : faisable, pas encore");
    expect(pin.getAttribute("data-marks")).toBe("3");
    // Top and left red, bottom and right green: no colour of its own for a mixed location.
    expect(pin.style.background).toContain("conic-gradient");
  });

  it("follows the settings: sequence breaks and what is done", async () => {
    await render(
      {
        sections: { 10: section(AccessibilityLevel.SequenceBreak, 1), 20: section(AccessibilityLevel.Cleared, 0) },
        locations: { 1: AccessibilityLevel.SequenceBreak, 2: AccessibilityLevel.Cleared },
      },
      { showSequenceBreaks: false, hideCleared: true },
    );
    expect(byLabel("Woodfall : Pas encore")).not.toBeNull();
    expect(container.querySelector('[aria-label^="Swamp"]')).toBeNull();
  });

  it("marks the hinted locations and sections, but not what is done (story 54.6)", async () => {
    await render({
      sections: {
        10: { ...section(AccessibilityLevel.Normal, 1), highlight: Highlight.Priority },
        20: { ...section(AccessibilityLevel.Cleared, 0), highlight: Highlight.Priority },
      },
    });
    const pin = byLabel("Woodfall : En partie : faisable, pas encore, indice : important");
    expect(pin.querySelector("[data-hint]")).not.toBeNull();
    expect(byLabel("Swamp : Fait").querySelector("[data-hint]")).toBeNull();
    fire(pin, new MouseEvent("click", { bubbles: true }));
    expect(byLabel("Entrance : Faisable, indice : important")).not.toBeNull();
    expect(byLabel("Pots : Pas encore")).not.toBeNull();
  });

  it("lists the sections of a location; a click does one, a right click undoes it", async () => {
    await render({ sections: { 10: section(AccessibilityLevel.Normal, 1), 11: section(AccessibilityLevel.Normal, 2) } });
    const pin = container.querySelector('[aria-label^="Woodfall"]');
    if (pin === null) throw new Error("no pin");
    fire(pin, new MouseEvent("click", { bubbles: true }));
    const dialog = container.querySelector('[role="dialog"]');
    expect(dialog?.textContent).toContain("Entrance");
    expect(dialog?.textContent).toContain("2/3");
    fire(byLabel("Entrance : Faisable"), new MouseEvent("click", { bubbles: true }));
    fire(byLabel("Pots : Faisable"), new MouseEvent("contextmenu", { bubbles: true, cancelable: true }));
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
