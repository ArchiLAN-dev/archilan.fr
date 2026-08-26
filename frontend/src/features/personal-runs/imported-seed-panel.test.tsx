import { renderToStaticMarkup } from "react-dom/server";

import { ImportedSeedPanel } from "./imported-seed-panel";
import type { ImportedSlot, PersonalRunParticipant } from "./types";

function render(node: React.ReactElement): string {
  return renderToStaticMarkup(node);
}

const participant = (userId: string, displayName: string): PersonalRunParticipant => ({
  userId,
  slug: null,
  displayName,
  avatarUrl: null,
  joinedAt: "2026-08-26T10:00:00.000Z",
  slotCount: 0,
  isMember: true,
  isAdmin: false,
  level: 1,
  playing: false,
});

const slot: ImportedSlot = {
  slotId: "gs-1",
  slot: 2,
  name: "Alice_MC",
  game: "Minecraft",
  assignedUserIds: [],
};

const base = {
  runId: "run-1",
  participants: [participant("u-alice", "Alice"), participant("u-bob", "Bob")],
  onChanged: () => undefined,
};

/**
 * Story 16.18. Importing a seed trades the detailed progression for the ability to host a
 * multiworld that already exists. The panel has to say that, because an empty progression tab
 * discovered later reads as a fault.
 */
describe("ImportedSeedPanel", () => {
  test("a run generated here offers the import and promises nothing else", () => {
    const html = render(<ImportedSeedPanel {...base} editable importedSeed={false} importedSlots={[]} />);

    expect(html).toContain("Importer une seed");
    expect(html).not.toContain("Progression détaillée");
    expect(html).not.toContain("n&#x27;est pas disponible");
  });

  test("an imported run says what it costs", () => {
    const html = render(<ImportedSeedPanel {...base} editable importedSeed importedSlots={[slot]} />);

    expect(html).toContain("Remplacer la seed");
    expect(html).toContain("configurations des joueurs");
    expect(html).toContain("Alice_MC");
    expect(html).toContain("Minecraft");
  });

  test("an unassigned slot says so rather than looking empty", () => {
    const html = render(<ImportedSeedPanel {...base} editable importedSeed importedSlots={[slot]} />);

    expect(html).toContain("Personne sur ce slot");
  });

  test("an assigned slot names everyone on it, in order", () => {
    const assigned = { ...slot, assignedUserIds: ["u-bob", "u-alice"] };
    const html = render(<ImportedSeedPanel {...base} editable importedSeed importedSlots={[assigned]} />);

    expect(html).toContain("Bob, Alice");
  });

  /** A launched run keeps its assignment visible; only changing it is closed. */
  test("a non-editable run shows the slots without controls", () => {
    const html = render(<ImportedSeedPanel {...base} editable={false} importedSeed importedSlots={[slot]} />);

    expect(html).toContain("Alice_MC");
    expect(html).not.toContain("Remplacer la seed");
    expect(html).not.toContain('aria-pressed');
  });
});
