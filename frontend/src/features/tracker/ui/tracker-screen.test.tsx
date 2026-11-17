/**
 * @jest-environment ./src/tests/jsdom-environment.mjs
 */
import { act } from "react";
import { createRoot, type Root } from "react-dom/client";

import type { GameTrackerPack } from "@/features/trackers/tracker-packs-api";

import type { TrackerRequest, TrackerResponse } from "../engine/protocol";
import { TrackerClient, type WorkerLike } from "../engine/tracker-client";
import { INITIAL_STATE, TEST_PACK } from "./test-pack";
import { TrackerScreen } from "./tracker-screen";
import type { TrackerSessionDeps } from "./use-tracker-session";

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

class NoResizeObserver {
  observe() {}
  disconnect() {}
  unobserve() {}
}

const pack: GameTrackerPack = {
  id: "p1",
  name: "Majora's Mask: Recompiled",
  author: "Seto10987, G4M3R L1F3",
  licenseSpdx: "MIT",
  sourceUrl: "https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack",
  isDefault: true,
  hasApVariant: true,
  defaultVariantUid: null,
  version: {
    id: "v1",
    packageVersion: "0.9.5.3",
    rootPath: "pack-0.9.5.3",
    sizeBytes: 10,
    sha256: "x",
    variants: [
      { uid: "standard", displayName: "Maps and items", flags: ["ap"] },
      { uid: "itemsonly", displayName: "Items only", flags: ["ap"] },
    ],
  },
};

type Script = {
  /** The answer to `load`; the default loads the test pack in the asked variant. */
  load?: (request: Extract<TrackerRequest, { type: "load" }>) => TrackerResponse;
  logs?: string[];
};

let requests: TrackerRequest[];

/** A Worker that answers like the engine would, without Lua. */
function fakeDeps(script: Script = {}): TrackerSessionDeps {
  return {
    fetchArchive: async (_versionId, onProgress) => {
      onProgress(5, 10);
      return new ArrayBuffer(10);
    },
    createClient: (onLog) =>
      new TrackerClient({
        onLog,
        createWorker: (): WorkerLike => {
          let listener: (data: unknown) => void = () => undefined;
          return {
            postMessage(message) {
              const request = message as TrackerRequest;
              requests.push(request);
              const answer = (response: TrackerResponse) => queueMicrotask(() => listener(response));
              switch (request.type) {
                case "load":
                  for (const log of script.logs ?? []) answer({ type: "log", level: "error", message: log });
                  answer(
                    script.load?.(request) ?? {
                      type: "loaded",
                      requestId: request.requestId,
                      pack: { ...TEST_PACK, variantUid: request.variantUid },
                      state: INITIAL_STATE,
                    },
                  );
                  return;
                case "image":
                  answer({ type: "image", requestId: request.requestId, path: request.path, blob: null });
                  return;
                case "itemClick":
                  answer({
                    type: "state",
                    requestId: request.requestId,
                    state: { items: { [request.itemId]: { active: true, stage: 0, count: 0, overlay: "" } }, sections: {}, locations: {} },
                  });
                  return;
                case "bulk": {
                  const items: TrackerResponse & { type: "state" } = {
                    type: "state",
                    requestId: request.requestId,
                    state: { items: {}, sections: {}, locations: {} },
                  };
                  for (const action of request.actions) {
                    if (action.type === "itemSet") {
                      items.state.items[action.itemId] = { active: action.state.active ?? false, stage: 0, count: 0, overlay: "" };
                    }
                  }
                  answer(items);
                  return;
                }
                default:
                  answer({ type: "state", requestId: request.requestId, state: { items: {}, sections: {}, locations: {} } });
              }
            },
            terminate() {},
            onMessage(next) {
              listener = next;
            },
            onError() {},
          };
        },
      }),
  };
}

let container: HTMLDivElement;
let root: Root;

beforeAll(() => {
  Object.defineProperty(window, "ResizeObserver", { value: NoResizeObserver, configurable: true });
  Object.defineProperty(window, "matchMedia", {
    configurable: true,
    value: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }),
  });
});

beforeEach(() => {
  requests = [];
  window.localStorage.clear();
  container = document.createElement("div");
  document.body.appendChild(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

async function settle() {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

async function open(deps: TrackerSessionDeps, initialVariantUid: string | null = null) {
  await act(async () => {
    root.render(
      <TrackerScreen deps={deps} gameName="Majora's Mask" initialPackId={null} initialVariantUid={initialVariantUid} packs={[pack]} />,
    );
  });
  await settle();
}

function byLabel(label: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`[aria-label="${label}"]`);
  if (found === null) throw new Error(`nothing labelled ${label}`);
  return found;
}

function buttonNamed(text: string): HTMLButtonElement {
  const found = Array.from(document.querySelectorAll("button")).find((button) => button.textContent?.trim() === text);
  if (found === undefined) throw new Error(`no button ${text}`);
  return found;
}

describe("the tracker screen (story 54.5)", () => {
  it("loads the default variant of the pack and draws its layout, credits and choices", async () => {
    await open(fakeDeps());
    expect(requests[0]).toMatchObject({ type: "load", rootPath: "pack-0.9.5.3", variantUid: "standard" });
    expect(container.querySelector('[data-testid="tracker-layout"]')).not.toBeNull();
    expect(byLabel("Sword").tagName).toBe("BUTTON");
    expect(byLabel("Variante : Maps and items")).not.toBeNull();
    expect(container.textContent).toContain("par Seto10987, G4M3R L1F3");
    expect(container.textContent).toContain("Afficher les séquences breaks");
  });

  it("opens the variant asked in the address, and falls back on the default for an unknown one", async () => {
    await open(fakeDeps(), "itemsonly");
    expect(requests[0]).toMatchObject({ type: "load", variantUid: "itemsonly" });
    act(() => root.unmount());
    root = createRoot(container);
    requests = [];
    await open(fakeDeps(), "nope");
    expect(requests[0]).toMatchObject({ type: "load", variantUid: "standard" });
  });

  it("lists the errors the pack reported without blocking the screen", async () => {
    await open(fakeDeps({ logs: ["scripts/init.lua:14: fichier introuvable : scripts/inverse_rules.lua"] }));
    expect(container.textContent).toContain("Le pack a signalé des erreurs (2)");
    expect(container.textContent).toContain("inverse_rules.lua");
    expect(container.textContent).toContain("Élément d'affichage non géré : recentpins");
    expect(byLabel("Sword")).not.toBeNull();
  });

  it("explains a pack that cannot load and offers to try again", async () => {
    await open(
      fakeDeps({
        load: (request) => ({ type: "error", requestId: request.requestId, kind: "lua_error", message: "init.lua a planté", file: "scripts/init.lua", line: 3 }),
      }),
    );
    expect(container.querySelector('[role="alert"]')?.textContent).toContain("init.lua a planté (scripts/init.lua, ligne 3)");
    expect(buttonNamed("Réessayer")).not.toBeNull();
  });

  it("keeps the state in the browser, and the reset empties it", async () => {
    await open(fakeDeps());
    await act(async () => {
      byLabel("Sword").click();
    });
    await settle();
    expect(window.localStorage.getItem("tracker:test_pack:1.0.0:standard")).toContain('"1":{"active":true');

    act(() => root.unmount());
    root = createRoot(container);
    requests = [];
    await open(fakeDeps());
    expect(requests[1]).toEqual({
      type: "bulk",
      requestId: 2,
      actions: [{ type: "itemSet", itemId: 1, state: { active: true, stage: 0, count: 0 } }],
    });
    expect(byLabel("Sword").getAttribute("aria-pressed")).toBe("true");

    await act(async () => {
      buttonNamed("Remettre à zéro").click();
    });
    const confirm = Array.from(document.querySelectorAll('[role="alertdialog"] button')).find(
      (button) => button.textContent?.trim() === "Remettre à zéro",
    );
    if (!(confirm instanceof HTMLButtonElement)) throw new Error("no confirm button");
    await act(async () => {
      confirm.click();
    });
    await settle();
    expect(window.localStorage.getItem("tracker:test_pack:1.0.0:standard")).toBeNull();
    expect(requests.filter((request) => request.type === "load")).toHaveLength(2);
    expect(byLabel("Sword").getAttribute("aria-pressed")).toBe("false");
  });
});
