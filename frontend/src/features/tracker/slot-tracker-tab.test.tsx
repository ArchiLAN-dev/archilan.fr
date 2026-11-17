/**
 * @jest-environment ./src/tests/jsdom-environment.mjs
 */
import { act } from "react";
import { createRoot, type Root } from "react-dom/client";

import type { ConnectionSlot } from "@/components/slot-name-field";

import { ownSlotTracker, SlotTrackerPanel } from "./slot-tracker-tab";

const screens: { slotRef: string | null; embedded: boolean }[] = [];

jest.mock("./ui/tracker-screen", () => ({
  TrackerScreen: (props: { slotRef?: string | null; embedded?: boolean }) => {
    screens.push({ slotRef: props.slotRef ?? null, embedded: props.embedded ?? false });
    return <p>tracker</p>;
  },
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const TRACKER = { gameId: "g1", gameSlug: "majoras-mask", ref: { kind: "run" as const, runId: "r1", slotName: "Link: 1" } };
const SLOTS: ConnectionSlot[] = [
  { name: "Link: 1", game: "Majora's Mask", tracker: TRACKER },
  { name: "Zelda", game: "Imported", tracker: undefined },
];

describe("ownSlotTracker", () => {
  it("finds the tracker of the viewer's own slot", () => {
    expect(ownSlotTracker(SLOTS, "Link: 1")).toBe(TRACKER);
  });

  it("gives nothing for another player's slot, an unknown name or a slot without tracker", () => {
    expect(ownSlotTracker(SLOTS, "Someone else")).toBeUndefined();
    expect(ownSlotTracker(SLOTS, "Zelda")).toBeUndefined();
    expect(ownSlotTracker(SLOTS, null)).toBeUndefined();
    expect(ownSlotTracker(undefined, "Link: 1")).toBeUndefined();
    expect(ownSlotTracker([], "Link: 1")).toBeUndefined();
  });
});

describe("SlotTrackerPanel", () => {
  let container: HTMLDivElement;
  let root: Root;

  beforeEach(() => {
    screens.length = 0;
    container = document.createElement("div");
    document.body.appendChild(container);
    root = createRoot(container);
  });

  afterEach(() => {
    act(() => root.unmount());
    container.remove();
  });

  it("connects the tracker by the slot's reference, never a password, without a heading of its own", () => {
    act(() => root.render(<SlotTrackerPanel gameName="Majora's Mask" packs={[]} tracker={TRACKER} visible />));
    expect(screens.at(-1)).toEqual({ slotRef: "run:r1:Link%3A%201", embedded: true });
    expect(container.querySelector("div")?.hidden).toBe(false);
  });

  it("stays mounted but hidden behind another tab", () => {
    act(() => root.render(<SlotTrackerPanel gameName="Majora's Mask" packs={[]} tracker={TRACKER} visible />));
    const shown = container.querySelector("p");
    act(() => root.render(<SlotTrackerPanel gameName="Majora's Mask" packs={[]} tracker={TRACKER} visible={false} />));
    expect(container.querySelector("div")?.hidden).toBe(true);
    expect(container.querySelector("p")).toBe(shown);
  });
});
