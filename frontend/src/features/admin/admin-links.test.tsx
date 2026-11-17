/**
 * @jest-environment ./src/tests/jsdom-environment.mjs
 */
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act } from "react";
import { createRoot, type Root } from "react-dom/client";

import { fetchAdminLinks, fetchLinkStats, saveLink, type TrackedLink } from "../links/links-api";

import { dayLabel, LinkStatsView, originLabel } from "./admin-link-stats";
import { AdminLinks, LinkForm, linkUrl, shownLinks } from "./admin-links";

jest.mock("../links/links-api", () => ({
  ...jest.requireActual<object>("../links/links-api"),
  fetchAdminLinks: jest.fn(),
  fetchLinkStats: jest.fn(),
  saveLink: jest.fn(),
}));

const fetchLinks = jest.mocked(fetchAdminLinks);
const save = jest.mocked(saveLink);
const fetchStats = jest.mocked(fetchLinkStats);

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

/** Story 55.1: the admin of the tracked links. */
const DISCORD: TrackedLink = {
  id: "l1",
  code: "discord-nov",
  label: "Discord novembre",
  target: "/evenements/archilan-4",
  direction: "inbound",
  active: true,
  createdAt: "2026-11-17T10:00:00+00:00",
  visits: { total: 12, last7: 2, last30: 5 },
  partnerId: null,
};
const LUDO: TrackedLink = {
  ...DISCORD,
  id: "l2",
  code: "ludo",
  label: "Ludothèque",
  target: "https://ludo.example",
  direction: "outbound",
  active: false,
  visits: { total: 40, last7: 9, last30: 30 },
};

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  container = document.createElement("div");
  document.body.appendChild(container);
  root = createRoot(container);
  fetchLinks.mockReset();
  save.mockReset();
  fetchStats.mockReset();
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

async function render(node: React.ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  await act(async () => {
    root.render(<QueryClientProvider client={client}>{node}</QueryClientProvider>);
  });
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
}

function input(label: RegExp): HTMLInputElement {
  const field = Array.from(container.querySelectorAll("label")).find((candidate) => label.test(candidate.textContent ?? ""))?.querySelector("input");
  if (!(field instanceof HTMLInputElement)) throw new Error(`no field ${label}`);
  return field;
}

function type(field: HTMLInputElement, value: string) {
  const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value")?.set;
  setter?.call(field, value);
  field.dispatchEvent(new Event("input", { bubbles: true }));
}

async function submit() {
  const form = container.querySelector("form");
  if (form === null) throw new Error("no form");
  await act(async () => {
    form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
  });
}

describe("AdminLinks", () => {
  it("lists each link with its full address, its direction and its state", async () => {
    fetchLinks.mockResolvedValue([DISCORD, LUDO]);
    await render(<AdminLinks />);

    expect(container.textContent).toContain(linkUrl("discord-nov"));
    expect(container.textContent).toContain("Entrant");
    expect(container.textContent).toContain("Sortant");
    expect(container.textContent).toContain("Désactivé");
  });

  it("copies the full address of a link", async () => {
    fetchLinks.mockResolvedValue([DISCORD]);
    const writeText = jest.fn(async () => undefined);
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });
    await render(<AdminLinks />);

    const copy = container.querySelector('[aria-label="Copier le lien discord-nov"]');
    if (copy === null) throw new Error("no copy button");
    await act(async () => {
      copy.dispatchEvent(new MouseEvent("click", { bubbles: true }));
    });

    expect(writeText).toHaveBeenCalledWith(linkUrl("discord-nov"));
    expect(container.textContent).toContain("Copié");
  });
});

describe("LinkForm", () => {
  it("creates a link in lower case and shows the API's refusal", async () => {
    save.mockResolvedValue({ ok: false, message: "Ce code est déjà pris." });
    const onSaved = jest.fn(async () => undefined);
    await render(<LinkForm current={null} onSaved={onSaved} />);

    await act(async () => {
      type(input(/Code/), "Discord-Nov");
      type(input(/Libellé/), "Discord");
      type(input(/Destination/), " /evenements ");
    });
    await submit();

    expect(save).toHaveBeenCalledWith({ code: "discord-nov", label: "Discord", target: "/evenements", active: true }, null);
    expect(container.querySelector('[role="alert"]')?.textContent).toBe("Ce code est déjà pris.");
    expect(onSaved).not.toHaveBeenCalled();
  });

  it("keeps the code of an existing link", async () => {
    save.mockResolvedValue({ ok: true, id: "l1" });
    const onSaved = jest.fn(async () => undefined);
    await render(<LinkForm current={DISCORD} onSaved={onSaved} />);

    expect(input(/Code/).disabled).toBe(true);
    await submit();

    expect(save).toHaveBeenCalledWith({ code: "discord-nov", label: "Discord novembre", target: "/evenements/archilan-4", active: true }, "l1");
    expect(onSaved).toHaveBeenCalled();
  });
});

describe("link statistics (story 55.2)", () => {
  it("puts the most visited over 30 days first and filters by direction", () => {
    expect(shownLinks([DISCORD, LUDO], "all").map((link) => link.id)).toEqual(["l2", "l1"]);
    expect(shownLinks([DISCORD, LUDO], "inbound").map((link) => link.id)).toEqual(["l1"]);
    expect(shownLinks([DISCORD, LUDO], "outbound").map((link) => link.id)).toEqual(["l2"]);
  });

  it("shows the counts of each link and filters the list", async () => {
    fetchLinks.mockResolvedValue([DISCORD, LUDO]);
    await render(<AdminLinks />);

    expect(container.textContent).toContain("5 sur 30 jours");
    expect(container.textContent).toContain("Des passages, pas des visiteurs uniques");
    const inbound = Array.from(container.querySelectorAll("button")).find((button) => button.textContent === "Entrants");
    if (inbound === undefined) throw new Error("no filter");
    await act(async () => {
      inbound.dispatchEvent(new MouseEvent("click", { bubbles: true }));
    });
    expect(inbound.getAttribute("aria-pressed")).toBe("true");
    expect(container.textContent).not.toContain("Ludothèque");
  });

  it("lists the origins, direct included, with their share", async () => {
    await render(
      <LinkStatsView
        stats={{
          days: [
            { day: "2026-11-16", visits: 0 },
            { day: "2026-11-17", visits: 4 },
          ],
          origins: [
            { host: "discord.com", visits: 3 },
            { host: "", visits: 1 },
          ],
        }}
      />,
    );
    expect(container.textContent).toContain("discord.com3 (75 %)");
    expect(container.textContent).toContain("Direct ou inconnu1 (25 %)");
    expect(container.querySelectorAll("table tr")).toHaveLength(2);
  });

  it("labels days and empty origins", () => {
    expect(dayLabel("2026-11-17")).toBe("17 nov.");
    expect(originLabel("")).toBe("Direct ou inconnu");
    expect(originLabel("t.co")).toBe("t.co");
  });

  it("does not offer to edit a partner's link (story 55.3)", async () => {
    fetchLinks.mockResolvedValue([{ ...LUDO, partnerId: "p1", label: "Partenaire : Ludothèque" }]);
    await render(<AdminLinks />);
    expect(container.textContent).toContain("Partenaire");
    expect(container.querySelector('button[aria-label="Modifier Partenaire : Ludothèque"]')).toBeNull();
  });
});
