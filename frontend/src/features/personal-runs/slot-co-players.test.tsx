import { renderToStaticMarkup } from "react-dom/server";

import { SlotCoPlayers } from "./slot-co-players";
import type { SlotCoPlayer } from "./types";

function render(node: React.ReactElement): string {
  return renderToStaticMarkup(node);
}

const alice: SlotCoPlayer = { userId: "u-alice", displayName: "Alice", slug: "alice", avatarUrl: null };
const bob: SlotCoPlayer = { userId: "u-bob", displayName: "Bob", slug: null, avatarUrl: null };

const base = {
  runId: "run-1",
  slotId: "slot-1",
  candidates: [
    { userId: "u-alice", displayName: "Alice" },
    { userId: "u-bob", displayName: "Bob" },
  ],
  onChanged: () => undefined,
};

/**
 * Story 16.17. Archipelago has one slot per world, so a Minecraft played by three people is one
 * slot and the platform only knew the member who declared it.
 */
describe("SlotCoPlayers", () => {
  test("names every player of a shared slot, to any participant", () => {
    const html = render(<SlotCoPlayers {...base} canManage={false} coPlayers={[alice, bob]} />);

    expect(html).toContain("Alice");
    expect(html).toContain("Bob");
    expect(html).toContain("Joué aussi par 2 joueurs");
  });

  /** Playing together is not private information, but managing it belongs to the run owner alone. */
  test("a participant who is not the run owner gets no controls", () => {
    const html = render(<SlotCoPlayers {...base} canManage={false} coPlayers={[alice]} />);

    expect(html).toContain("Alice");
    expect(html).not.toContain("Ajouter un co-joueur");
    expect(html).not.toContain("Retirer Alice");
  });

  test("the run owner can add and remove", () => {
    const html = render(<SlotCoPlayers {...base} canManage coPlayers={[alice]} />);

    expect(html).toContain("Retirer Alice de ce slot");
    // Alice is already on the slot, so only Bob is offered.
    expect(html).toContain("<option value=\"u-bob\">Bob</option>");
    expect(html).not.toContain("<option value=\"u-alice\">");
  });

  /** A solo slot stays silent for everyone but the person who could change it. */
  test("renders nothing on a solo slot when the viewer cannot manage it", () => {
    expect(render(<SlotCoPlayers {...base} canManage={false} coPlayers={[]} />)).toBe("");
  });

  test("offers the control on a solo slot when the viewer owns the run", () => {
    const html = render(<SlotCoPlayers {...base} canManage coPlayers={[]} />);

    expect(html).toContain("Joué en solo");
    expect(html).toContain("Ajouter un co-joueur");
  });
});
