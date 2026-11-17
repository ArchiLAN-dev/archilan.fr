/**
 * @jest-environment ./src/tests/jsdom-environment.mjs
 */
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act } from "react";
import { createRoot, type Root } from "react-dom/client";

import { ENGINE_VERSION } from "@/features/tracker/engine/engine-version";

import { TrackerPackRow } from "./admin-game-tracker-packs";
import { hasFailedVariant, packCompatibility, trackerTrialHref } from "./admin-tracker-pack-compatibility";
import { updateTrackerPack, type AdminTrackerPack, type TrackerPackCompatibility } from "./admin-tracker-packs-api";

jest.mock("./admin-tracker-packs-api", () => ({
  ...jest.requireActual<object>("./admin-tracker-packs-api"),
  updateTrackerPack: jest.fn(async () => ({ ok: true })),
}));

const update = jest.mocked(updateTrackerPack);

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

/** Story 54.8: the state of each variant on the site, the trial link, and the confirmation before offering a broken pack. */
const pack: AdminTrackerPack = {
  id: "p1",
  name: "MM",
  author: null,
  packageUid: "MM_AP",
  gameName: null,
  sourceUrl: "https://github.com/o/r",
  licenseSpdx: "MIT",
  hasApVariant: true,
  versionId: "v1",
  packageVersion: "1.0",
  gitTag: null,
  sizeBytes: 1024,
  variants: [
    { uid: "standard", displayName: "Maps and items", flags: ["ap"] },
    { uid: "itemsonly", displayName: "Items only", flags: ["ap"] },
  ],
  minPopTrackerVersion: null,
  importedAt: "2026-11-16T10:00:00+00:00",
  enabled: false,
  isDefault: false,
  defaultVariantUid: null,
  licenseAcknowledged: false,
  availableUpdate: null,
  lastCheckedAt: null,
  lastCheckError: null,
  versions: [],
  rootPath: "",
  sha256: "abc",
  compatibility: [],
};

function tried(variantUid: string, status: TrackerPackCompatibility["status"], extra: Partial<TrackerPackCompatibility> = {}): TrackerPackCompatibility {
  return { variantUid, status, errors: [], unsupportedApis: [], engineVersion: ENGINE_VERSION, reportedAt: "2026-11-17T10:00:00+00:00", ...extra };
}

describe("packCompatibility", () => {
  it.each<[TrackerPackCompatibility[], string]>([
    [[], "untried"],
    [[tried("standard", "ok")], "untried"],
    [[tried("standard", "ok"), tried("itemsonly", "ok")], "ok"],
    [[tried("standard", "warnings")], "warnings"],
    [[tried("standard", "warnings"), tried("itemsonly", "failed")], "failed"],
  ])("sums up %j as %s", (compatibility, state) => {
    expect(packCompatibility({ ...pack, compatibility })).toBe(state);
  });

  it("ignores reports of variants the version no longer has", () => {
    expect(hasFailedVariant({ ...pack, compatibility: [tried("gone", "failed")] })).toBe(false);
    expect(hasFailedVariant({ ...pack, compatibility: [tried("itemsonly", "failed")] })).toBe(true);
  });

  it("links the trial to the version, in trial mode", () => {
    expect(trackerTrialHref("majoras-mask", "p1", "v1")).toBe("/jeux/majoras-mask/tracker?pack=p1&version=v1&essai=1");
  });
});

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  update.mockClear();
  container = document.createElement("div");
  document.body.appendChild(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

async function row(value: AdminTrackerPack) {
  await act(async () => {
    root.render(
      <QueryClientProvider client={new QueryClient()}>
        <ul>
          <TrackerPackRow gameId="g1" gameSlug="majoras-mask" pack={value} />
        </ul>
      </QueryClientProvider>,
    );
  });
}

function enableBox(): HTMLInputElement {
  const label = Array.from(container.querySelectorAll("label")).find((candidate) => candidate.textContent?.includes("Proposé aux joueurs"));
  const box = label?.querySelector("input");
  if (box === null || box === undefined) throw new Error("no switch");
  return box;
}

function button(text: string): HTMLButtonElement {
  const found = Array.from(container.querySelectorAll("button")).find((candidate) => candidate.textContent?.trim() === text);
  if (found === undefined) throw new Error(`no button ${text}`);
  return found;
}

describe("the pack's compatibility on its admin row", () => {
  it("shows each variant's state, its errors and missing functions, and the trial link", async () => {
    await row({
      ...pack,
      compatibility: [
        tried("standard", "warnings", {
          errors: [{ kind: "error", message: "fichier introuvable", file: "scripts/init.lua", line: 14 }],
          unsupportedApis: ["ScriptHost:AddOnFrameHandler"],
          engineVersion: ENGINE_VERSION - 1,
        }),
      ],
    });
    expect(container.querySelector("summary [data-compatibility]")?.getAttribute("data-compatibility")).toBe("warnings");
    expect(container.textContent).toContain("Avec avertissements");
    expect(container.textContent).toContain("Pas encore essayé");
    expect(container.textContent).toContain("fichier introuvable (scripts/init.lua:14)");
    expect(container.textContent).toContain("ScriptHost:AddOnFrameHandler");
    expect(container.textContent).toContain("à refaire");
    const link = Array.from(container.querySelectorAll("a")).find((a) => a.textContent?.includes("Essayer"));
    expect(link?.getAttribute("href")).toBe("/jeux/majoras-mask/tracker?pack=p1&version=v1&essai=1");
  });

  it("offers a pack whose variants load without asking", async () => {
    await row({ ...pack, compatibility: [tried("standard", "ok")] });
    await act(async () => enableBox().click());
    expect(update).toHaveBeenCalledWith("p1", { enabled: true });
  });

  it("asks before offering a pack with a variant that does not load", async () => {
    await row({ ...pack, compatibility: [tried("itemsonly", "failed")] });
    await act(async () => enableBox().click());
    expect(update).not.toHaveBeenCalled();
    expect(container.textContent).toContain("ne se charge pas sur le site");

    await act(async () => button("Annuler").click());
    expect(container.textContent).not.toContain("ne se charge pas sur le site");
    expect(update).not.toHaveBeenCalled();

    await act(async () => enableBox().click());
    await act(async () => button("Proposer quand même").click());
    expect(update).toHaveBeenCalledWith("p1", { enabled: true });
  });
});
