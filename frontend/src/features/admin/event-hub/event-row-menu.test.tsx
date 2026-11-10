/**
 * @jest-environment ./src/tests/jsdom-environment.mjs
 */
import { act } from "react";
import { createRoot, type Root } from "react-dom/client";

import type { AdminEvent } from "../admin-events-api";
import { EventRowMenu } from "./event-row-menu";

const push = jest.fn();
jest.mock("next/navigation", () => ({
  useRouter: () => ({ push }),
}));

const event: AdminEvent = {
  id: "e1",
  title: "ArchiLAN 4",
  description: "",
  coverImageUrl: null,
  photoGallery: [],
  status: "published",
  startsAt: "2026-11-13T17:00:00+00:00",
  endsAt: "2026-11-15T17:00:00+00:00",
  venue: "",
  capacity: 40,
  confirmedRegistrations: 0,
  isAtCapacity: false,
  registrationOpensAt: "2026-10-01T00:00:00+00:00",
  registrationClosesAt: "2026-11-10T00:00:00+00:00",
  isPublic: false,
  visibility: "private",
  hasPrivateAccessPassword: false,
  gameSelectionEnabled: true,
  vodUrl: null,
  recapPostSlug: null,
  hasRecap: false,
  featuredOnHome: false,
  createdAt: "2026-09-01T00:00:00+00:00",
  updatedAt: "2026-09-01T00:00:00+00:00",
};

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement;
let root: Root;
const onTransition = jest.fn();
const onFeature = jest.fn();
const onConfigurePrivateAccess = jest.fn();

beforeEach(() => {
  container = document.createElement("div");
  document.body.appendChild(container);
  root = createRoot(container);
  act(() => {
    root.render(<EventRowMenu event={event} onConfigurePrivateAccess={onConfigurePrivateAccess} onFeature={onFeature} onTransition={onTransition} />);
  });
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
  jest.clearAllMocks();
});

function button(): HTMLButtonElement {
  const found = container.querySelector<HTMLButtonElement>('button[aria-haspopup="menu"]');
  if (!found) throw new Error("menu button missing");
  return found;
}

function items(): HTMLButtonElement[] {
  return Array.from(container.querySelectorAll<HTMLButtonElement>('[role="menuitem"]'));
}

function key(target: Element, name: string) {
  act(() => {
    target.dispatchEvent(new KeyboardEvent("keydown", { key: name, bubbles: true }));
  });
}

/** Story 3.17: the row menu works with the keyboard as well as the mouse. */
describe("the menu of an event row", () => {
  it("opens on the first item and moves with the arrows", () => {
    act(() => button().click());

    expect(button().getAttribute("aria-expanded")).toBe("true");
    expect(items().map((item) => item.textContent)).toEqual(["Ouvrir l'événement", "Dépublier", "Mettre en avant", "Configurer l'accès privé"]);
    expect(document.activeElement).toBe(items()[0]);

    key(items()[0], "ArrowDown");
    expect(document.activeElement).toBe(items()[1]);
    key(items()[1], "End");
    expect(document.activeElement).toBe(items()[3]);
    key(items()[3], "ArrowDown");
    expect(document.activeElement).toBe(items()[0]);
    key(items()[0], "ArrowUp");
    expect(document.activeElement).toBe(items()[3]);
  });

  it("closes on Escape and gives the focus back to its button", () => {
    act(() => button().click());
    key(items()[0], "Escape");

    expect(items()).toHaveLength(0);
    expect(button().getAttribute("aria-expanded")).toBe("false");
    expect(document.activeElement).toBe(button());
  });

  it("closes on a click outside", () => {
    act(() => button().click());
    act(() => {
      document.body.dispatchEvent(new Event("pointerdown", { bubbles: true }));
    });

    expect(items()).toHaveLength(0);
  });

  it("runs the chosen command", () => {
    act(() => button().click());
    act(() => items()[1].click());
    expect(onTransition).toHaveBeenCalledWith(event, "draft");

    act(() => button().click());
    act(() => items()[0].click());
    expect(push).toHaveBeenCalledWith("/admin/evenements/e1");

    act(() => button().click());
    act(() => items()[3].click());
    expect(onConfigurePrivateAccess).toHaveBeenCalledWith(event);
  });
});
