/**
 * @jest-environment ./src/tests/jsdom-environment.mjs
 */
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act } from "react";
import { createRoot, type Root } from "react-dom/client";

import { ConnectionFields } from "@/components/connection-fields";
import { fetchGameTrackerPacks, type GameTrackerPack } from "@/features/trackers/tracker-packs-api";

jest.mock("../trackers/tracker-packs-api", () => ({ fetchGameTrackerPacks: jest.fn() }));

const packsOf = jest.mocked(fetchGameTrackerPacks);

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

/** Story 54.7: « Ouvrir le tracker » next to a slot whose game has a pack. */
let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  container = document.createElement("div");
  document.body.appendChild(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

async function render(withPacks: Record<string, boolean>) {
  packsOf.mockImplementation(async (gameId) => (withPacks[gameId] ? [{ id: "p" } as GameTrackerPack] : []));
  await act(async () => {
    root.render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ConnectionFields
          host="archilan.fr"
          password="pw-secret"
          port={35012}
          slots={[
            {
              name: "Jean MM",
              game: "Majora's Mask",
              tracker: { gameId: "g-mm", gameSlug: "majoras-mask", ref: { kind: "event", registrationId: "reg-1", slotName: "Jean MM" } },
            },
            {
              name: "Jean OoT",
              game: "Ocarina of Time",
              tracker: { gameId: "g-oot", gameSlug: "oot", ref: { kind: "event", registrationId: "reg-1", slotName: "Jean OoT" } },
            },
            { name: "Jean Imported", game: null },
          ]}
          uri="wss://archilan.fr:35012"
        />
      </QueryClientProvider>,
    );
  });
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
}

it("shows the button only for a game with a pack, opening a new tab on the slot without its password", async () => {
  await render({ "g-mm": true });
  const links = Array.from(container.querySelectorAll("a"));
  expect(links.map((link) => link.getAttribute("aria-label"))).toEqual(["Ouvrir le tracker de Jean MM dans un nouvel onglet"]);
  const [link] = links;
  expect(link?.getAttribute("href")).toBe("/jeux/majoras-mask/tracker?slot=event%3Areg-1%3AJean%2520MM");
  expect(link?.getAttribute("target")).toBe("_blank");
  expect(link?.getAttribute("href")).not.toContain("pw-secret");
  expect(packsOf).toHaveBeenCalledWith("g-oot");
});
