/**
 * @jest-environment ./src/tests/jsdom-environment.mjs
 */
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act } from "react";
import { createRoot, type Root } from "react-dom/client";

import type { GameTrackerPack } from "@/features/trackers/tracker-packs-api";

import type { ApOutgoing } from "../engine/ap-events";
import { Highlight } from "../engine/levels";
import type { TrackerRequest, TrackerResponse } from "../engine/protocol";
import { TrackerClient, type WorkerLike } from "../engine/tracker-client";
import type { SlotConnection } from "../tracker-slot-api";
import type { ApClientLike, ApSocketHandlers } from "./ap-link";
import { INITIAL_STATE, TEST_PACK } from "./test-pack";
import type { AdminTrackerPack, CompatibilityReport } from "@/features/admin/admin-tracker-packs-api";

import { REPORT_DELAY_MS } from "./compatibility-report";
import { TrackerScreen } from "./tracker-screen";
import { TrackerTrial } from "./tracker-trial";
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

/** Story 54.6: the room the fake Archipelago client talks to. */
type FakeRoom = { handlers: ApSocketHandlers | null; sent: ApOutgoing[]; logins: string[][] };
let room: FakeRoom;
/** Story 54.7: what the API says of the slot the tracker was opened on. */
let slotAnswer: SlotConnection;
let slotRequests: unknown[];
let queryClient: QueryClient;

function fakeApClient(): ApClientLike {
  return {
    listen(handlers) {
      room.handlers = handlers;
    },
    async login(url, slot, password) {
      room.logins.push([url, slot, password]);
      room.handlers?.connected({
        team: 0,
        slot: 1,
        players: [{ team: 0, slot: 1, alias: slot, name: slot }],
        missing_locations: [],
        checked_locations: [],
        slot_data: {},
        slot_info: { "1": { name: slot, game: "MM" } },
      });
      room.handlers?.receivedItems({ index: 0, items: [{ item: 1, location: 0, player: 1, flags: 1 }] });
    },
    send(packets) {
      for (const packet of packets) if (packet.cmd !== "Sync") room.sent.push(packet);
    },
    disconnect() {},
    names: () => ({}),
    seed: () => "s",
  };
}

/** A Worker that answers like the engine would, without Lua. */
function fakeDeps(script: Script = {}): TrackerSessionDeps {
  return {
    fetchArchive: async (_versionId, onProgress) => {
      onProgress(5, 10);
      return new ArrayBuffer(10);
    },
    createApClient: fakeApClient,
    fetchSlotConnection: async (ref) => {
      slotRequests.push(ref);
      return slotAnswer;
    },
    createClient: ({ onLog, onApSend }) =>
      new TrackerClient({
        onLog,
        onApSend,
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
                case "ap": {
                  // The engine would run the pack's handlers: the sword arrives, the hut chest is hinted.
                  const kinds = request.events.map((event) => event.kind);
                  if (kinds.includes("clear")) answer({ type: "apSend", packets: [{ cmd: "Get", keys: ["_read_hints_0_1"] }] });
                  answer({
                    type: "state",
                    requestId: request.requestId,
                    state: {
                      items: kinds.includes("items") ? { 1: { active: true, stage: 0, count: 0, overlay: "" } } : {},
                      sections: { 10: { available: 1, level: 6, visible: true, highlight: Highlight.Priority } },
                      locations: {},
                    },
                  });
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
  room = { handlers: null, sent: [], logins: [] };
  slotAnswer = { kind: "denied" };
  slotRequests = [];
  queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
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

async function open(deps: TrackerSessionDeps, initialVariantUid: string | null = null, slotRef: string | null = null) {
  await act(async () => {
    root.render(
      <QueryClientProvider client={queryClient}>
        <TrackerScreen
          deps={deps}
          gameName="Majora's Mask"
          gameSlug="majoras-mask"
          initialPackId={null}
          initialVariantUid={initialVariantUid}
          packs={[pack]}
          slotRef={slotRef}
        />
      </QueryClientProvider>,
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
    // Story 54.9: the settings sit in the Options menu; the layout is the site's (maps and items apart).
    expect(container.querySelector('[data-board="archilan"]')).not.toBeNull();
    expect(container.textContent).not.toContain("Montrer ce qui est faisable hors logique");
    await act(async () => {
      buttonNamed("Options").click();
    });
    expect(container.textContent).toContain("Montrer ce qui est faisable hors logique");
    expect(container.textContent).toContain("Masquer ce qui est fait");
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

  it("groups the same error repeated and says it comes from the pack (story 54.9)", async () => {
    await open(fakeDeps({ logs: ["handler boom", "handler boom", "handler boom"] }));
    expect(container.textContent).toContain("Le pack a signalé des erreurs (4)");
    const repeated = Array.from(container.querySelectorAll("details li")).find((item) => item.textContent?.startsWith("handler boom"));
    expect(repeated?.textContent).toBe("handler boom×3");
    expect(container.querySelectorAll("details li")).toHaveLength(2);
    expect(container.textContent).toContain("viennent du pack");
  });

  it("shows the tracker alone full screen, without the site around it (story 54.9)", async () => {
    await open(fakeDeps());
    const shown: Element[] = [];
    const original = Element.prototype.requestFullscreen;
    Element.prototype.requestFullscreen = function (this: Element) {
      shown.push(this);
      return Promise.resolve();
    };
    try {
      await act(async () => {
        byLabel("Plein écran").click();
      });
    } finally {
      Element.prototype.requestFullscreen = original;
    }
    expect(shown).toHaveLength(1);
    expect(shown[0]?.querySelector('[data-board="archilan"]')).not.toBeNull();
    expect(container.firstElementChild).toBe(shown[0]);
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
      buttonNamed("Options").click();
    });
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

function type(label: string, value: string) {
  const input = Array.from(document.querySelectorAll("label")).find((candidate) => candidate.textContent?.startsWith(label))?.querySelector("input");
  if (input === undefined || input === null) throw new Error(`no input ${label}`);
  const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value")?.set;
  act(() => {
    setter?.call(input, value);
    input.dispatchEvent(new Event("input", { bubbles: true }));
  });
}

describe("autotracking from an Archipelago room (story 54.6)", () => {
  it("connects with the form, fills the tracker from the room and saves nothing in the browser", async () => {
    await open(fakeDeps());
    await act(async () => {
      buttonNamed("Connecter à Archipelago").click();
    });
    type("Adresse de la room", "localhost:38281");
    type("Nom du slot", "Jean");
    type("Mot de passe", "secret");
    await act(async () => {
      buttonNamed("Connecter").click();
    });
    await settle();

    expect(room.logins).toEqual([["localhost:38281", "Jean", "secret"]]);
    expect(container.textContent).toContain("Relié à la room, slot Jean.");
    const ap = requests.find((request) => request.type === "ap");
    expect(ap?.type === "ap" ? ap.events.map((event) => event.kind) : []).toEqual(["connected", "clear", "locations", "items"]);
    expect(byLabel("Sword").getAttribute("aria-pressed")).toBe("true");
    // The pack asked for its hints key through the page.
    expect(room.sent).toEqual([{ cmd: "Get", keys: ["_read_hints_0_1"] }]);
    // The room holds the state: nothing saved, and the password is never remembered.
    expect(window.localStorage.getItem("tracker:test_pack:1.0.0:standard")).toBeNull();
    expect(window.localStorage.getItem("tracker:room")).toBe(JSON.stringify({ address: "localhost:38281", slot: "Jean" }));
  });

  it("says when the room overwrites manual clicks", async () => {
    await open(fakeDeps());
    await act(async () => {
      buttonNamed("Connecter à Archipelago").click();
    });
    type("Adresse de la room", "localhost:38281");
    type("Nom du slot", "Jean");
    await act(async () => {
      buttonNamed("Connecter").click();
    });
    await settle();
    await act(async () => {
      byLabel("Sword").click();
    });
    await settle();
    expect(container.textContent).not.toContain("clics manuels");

    // The server starts over (a Sync): the pack clears and replays.
    await act(async () => {
      room.handlers?.receivedItems({ index: 0, items: [{ item: 1, location: 0, player: 1, flags: 1 }] });
    });
    await settle();
    expect(container.textContent).toContain("vos clics manuels ont été remplacés");

    await act(async () => {
      buttonNamed("Déconnecter").click();
    });
    expect(container.textContent).toContain("Suivi manuel.");
  });
});

describe("the tracker opened on a slot (story 54.7)", () => {
  const SLOT = "event:reg-1:Jean%20MM";
  const READY: SlotConnection = {
    kind: "ready",
    gameSlug: "majoras-mask",
    credentials: { address: "wss://archilan.fr:35012", slot: "Jean MM", password: "pw-secret" },
  };

  it("reads the slot's connection and connects without typing anything", async () => {
    slotAnswer = READY;
    await open(fakeDeps(), null, SLOT);
    await settle();
    expect(slotRequests).toEqual([{ kind: "event", registrationId: "reg-1", slotName: "Jean MM" }]);
    expect(room.logins).toEqual([["wss://archilan.fr:35012", "Jean MM", "pw-secret"]]);
    expect(container.textContent).toContain("Relié à la room, slot Jean MM.");
    expect(window.location.href).not.toContain("pw-secret");
  });

  it("waits for a party not launched yet, then connects once it runs", async () => {
    slotAnswer = { kind: "waiting", gameSlug: "majoras-mask" };
    await open(fakeDeps(), null, SLOT);
    expect(container.textContent).toContain("La partie n'est pas lancée");
    expect(room.logins).toEqual([]);

    slotAnswer = READY;
    await act(async () => {
      await queryClient.refetchQueries({ queryKey: ["tracker-slot", SLOT] });
    });
    await settle();
    expect(room.logins).toHaveLength(1);
    expect(container.textContent).not.toContain("La partie n'est pas lancée");
  });

  it("tells a slot that is not the player's, and keeps its state apart from the other slots", async () => {
    await open(fakeDeps(), null, SLOT);
    expect(container.textContent).toContain("Ce slot n'est pas le vôtre");
    expect(room.logins).toEqual([]);
    await act(async () => {
      byLabel("Sword").click();
    });
    await settle();
    expect(window.localStorage.getItem(`tracker:test_pack:1.0.0:standard:${SLOT}`)).toContain('"1":{"active":true');
    expect(window.localStorage.getItem("tracker:test_pack:1.0.0:standard")).toBeNull();
  });

  it("refuses a slot of another game, and a broken link", async () => {
    slotAnswer = { ...READY, gameSlug: "ocarina-of-time" };
    await open(fakeDeps(), null, SLOT);
    expect(container.textContent).toContain("un autre jeu");
    expect(room.logins).toEqual([]);

    act(() => root.unmount());
    root = createRoot(container);
    await open(fakeDeps(), null, "nope:1");
    expect(container.textContent).toContain("Le lien vers votre slot n'est pas valide");
  });

  it("remembers the variant chosen for the slot", async () => {
    await open(fakeDeps(), null, SLOT);
    const select = byLabel("Variante : Maps and items");
    expect(select).not.toBeNull();
    window.localStorage.setItem(`tracker:choice:${SLOT}`, JSON.stringify({ packId: "p1", variantUid: "itemsonly" }));
    act(() => root.unmount());
    root = createRoot(container);
    requests = [];
    await open(fakeDeps(), null, SLOT);
    expect(requests[0]).toMatchObject({ type: "load", variantUid: "itemsonly" });
  });
});

describe("an admin's trial of a pack (story 54.8)", () => {
  const adminPack = {
    id: "p1",
    name: "Majora's Mask: Recompiled",
    author: "Seto10987",
    licenseSpdx: "MIT",
    sourceUrl: "https://github.com/o/r",
    enabled: false,
    defaultVariantUid: null,
    versions: [
      {
        id: "v-old",
        packageVersion: "0.9.5.2",
        gitTag: null,
        sizeBytes: 10,
        importedAt: "2026-11-01T10:00:00+00:00",
        rootPath: "pack-0.9.5.2",
        sha256: "x",
        variants: [{ uid: "standard", displayName: "Maps and items", flags: ["ap"] }],
        current: false,
      },
    ],
  } as unknown as AdminTrackerPack;

  async function trial(fetchPacks: () => Promise<AdminTrackerPack[] | null>, deps: TrackerSessionDeps, reports: [string, CompatibilityReport][]) {
    await act(async () => {
      root.render(
        <QueryClientProvider client={queryClient}>
          <TrackerTrial
            deps={deps}
            fetchPacks={fetchPacks}
            gameId="g1"
            gameName="Majora's Mask"
            gameSlug="majoras-mask"
            initialVariantUid={null}
            packId="p1"
            sendReport={async (versionId, report) => {
              reports.push([versionId, report]);
              return true;
            }}
            versionId="v-old"
          />
        </QueryClientProvider>,
      );
    });
    await settle();
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, REPORT_DELAY_MS + 50));
    });
    await settle();
  }

  it("opens a version not offered to players and reports what its load found", async () => {
    const reports: [string, CompatibilityReport][] = [];
    await trial(async () => [adminPack], fakeDeps({ logs: ["Fonction non prise en charge : ScriptHost:AddOnFrameHandler", "boom"] }), reports);
    expect(requests[0]).toMatchObject({ type: "load", rootPath: "pack-0.9.5.2", variantUid: "standard" });
    expect(container.textContent).toContain("pas encore proposé aux joueurs");
    expect(reports).toHaveLength(1);
    const [versionId, report] = reports[0] ?? [];
    expect(versionId).toBe("v-old");
    expect(report).toMatchObject({ variantUid: "standard", status: "warnings", errors: [{ kind: "error", message: "boom" }] });
    expect(report?.unsupportedApis).toEqual(["ScriptHost:AddOnFrameHandler", "widget:recentpins"]);
    expect(container.textContent).toContain("État de cette variante enregistré");
  });

  it("reports a variant that does not load", async () => {
    const reports: [string, CompatibilityReport][] = [];
    await trial(
      async () => [adminPack],
      fakeDeps({ load: (request) => ({ type: "error", requestId: request.requestId, kind: "lua_error", message: "init.lua a planté" }) }),
      reports,
    );
    expect(reports.map(([, report]) => report.status)).toEqual(["failed"]);
  });

  it("gives nothing to someone who is not an admin", async () => {
    await trial(async () => null, fakeDeps(), []);
    expect(container.textContent).toContain("réservé aux admins");
    expect(requests).toEqual([]);
  });

  it("says when the pack no longer keeps the version", async () => {
    await trial(async () => [{ ...adminPack, versions: [] }], fakeDeps(), []);
    expect(container.textContent).toContain("n'existe plus");
  });
});
