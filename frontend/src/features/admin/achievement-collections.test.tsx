import { renderToStaticMarkup } from "react-dom/server";

import { CatalogueSectionView, ProfileCollections, catalogueSections, collectionRewardText } from "@/features/community/achievement-collections";
import { contentFor } from "@/features/community/notification-content";
import type { AchievementCollectionProgress, CatalogueAchievement } from "@/features/players/player-profile-api";
import type { AchievementDefinition, AdminAchievementCollection } from "./admin-achievements-api";
import { NO_FILTERS, filterAchievements } from "./admin-achievements-dashboard";
import { OTHERS, buildSections, collectionChanges, flattenSections, moveAcross, moveWithin, sectionDropId } from "./achievement-sections";

const collection = (id: string, name: string): AdminAchievementCollection => ({
  id, name, description: "", imageKey: null, imageUrl: null, position: 0, secret: false, reward: null, pelles: 0,
});
const definition = (id: string, collectionId: string | null = null): AchievementDefinition => ({
  id, key: id, name: id, description: "", rule: { op: "all", rules: [] }, active: true, position: 0, customImageKey: null, customImageUrl: null, reward: null, collectionId,
});

const reZero = collection("rz", "Re:Zero");
const lan = collection("lan", "LAN");
const definitions = [definition("envy", "rz"), definition("first"), definition("death", "rz"), definition("lan1", "lan"), definition("lost", "gone")];

/** Story 30.52: the admin list in sections, and a drag from one collection to another. */
describe("admin sections", () => {
  const sections = buildSections(definitions, [reZero, lan]);

  test("one section per collection in order, then the others (an unknown collection among them)", () => {
    expect(sections.map((s) => [s.id, s.ids])).toEqual([
      ["rz", ["envy", "death"]],
      ["lan", ["lan1"]],
      [OTHERS, ["first", "lost"]],
    ]);
    expect(flattenSections(sections)).toEqual(["envy", "death", "lan1", "first", "lost"]);
  });

  test("dragging over another section moves the line there, before the line it is over or at the end", () => {
    const over = moveAcross(sections, "first", "death");
    expect(over.map((s) => s.ids)).toEqual([["envy", "first", "death"], ["lan1"], ["lost"]]);
    const empty = moveAcross(sections, "envy", sectionDropId("lan"));
    expect(empty.map((s) => s.ids)).toEqual([["death"], ["lan1", "envy"], ["first", "lost"]]);
    expect(moveAcross(sections, "envy", "death")).toBe(sections);
  });

  test("dropping inside a section reorders it; only what changed collection is saved", () => {
    expect(moveWithin(sections, "death", "envy").map((s) => s.ids)[0]).toEqual(["death", "envy"]);
    expect(moveWithin(sections, "envy", "lan1")).toBe(sections);
    const moved = moveAcross(sections, "first", "death");
    expect(collectionChanges(definitions, moved)).toEqual({ first: "rz", lost: null });
  });

  test("the collection filter keeps one collection, or the others", () => {
    expect(filterAchievements(definitions, { ...NO_FILTERS, collection: "rz" }).map((d) => d.id)).toEqual(["envy", "death"]);
    expect(filterAchievements(definitions, { ...NO_FILTERS, collection: OTHERS }).map((d) => d.id)).toEqual(["first"]);
  });
});

const progress = (over: Partial<AchievementCollectionProgress>): AchievementCollectionProgress => ({
  id: "rz", name: "Re:Zero", description: "La sorcière.", imageUrl: null, secret: false, unlocked: 1, total: 2, complete: false, reward: null, pelles: 0, ...over,
});
const achievement = (key: string, unlocked: boolean, collectionId: string | null, unlockedAt: string | null = null): CatalogueAchievement => ({
  key, name: key, description: "", unlocked, unlockedAt, grantId: null, kudosCount: 0, customImageUrl: null, collectionId, rarity: { count: 0, percent: 0 },
});

describe("public catalogue", () => {
  test("collections first in admin order, the others last with the unlocked ones first", () => {
    const sections = catalogueSections(
      [achievement("envy", false, "rz"), achievement("a", false, null), achievement("death", true, "rz"), achievement("b", true, null, "2026-10-01")],
      [progress({}), progress({ id: "empty", name: "Vide" })],
    );
    expect(sections.map((s) => [s.collection?.name ?? "Autres", s.achievements.map((a) => a.key)])).toEqual([
      ["Re:Zero", ["envy", "death"]],
      ["Autres", ["b", "a"]],
    ]);
  });

  test("a section shows its progress, a secret mark, and gold once complete with what it gave", () => {
    const html = renderToStaticMarkup(
      <CatalogueSectionView slug="alice" section={{ collection: progress({ secret: true, unlocked: 2, complete: true, reward: "Cadre « Mains de l'Envie »", pelles: 50 }), achievements: [achievement("envy", true, "rz")] }} />,
    );
    expect(html).toContain("Secrète");
    expect(html).toContain("Collection complète");
    expect(html).toContain('aria-valuenow="2"');
    expect(html).toContain("Gagné : Cadre « Mains de l&#x27;Envie » · 50 pelles");
    expect(collectionRewardText({ reward: null, pelles: 0 })).toBeNull();
  });

  test("the profile lists the started collections, linked to the catalogue", () => {
    const html = renderToStaticMarkup(<ProfileCollections collections={[progress({})]} slug="alice" />);
    expect(html).toContain('href="/joueurs/alice/succes"');
    expect(html).toContain("1/2");
    expect(renderToStaticMarkup(<ProfileCollections collections={[]} slug="alice" />)).toBe("");
  });

  test("a completed collection is told in the bell with its pelles and its cosmetic", () => {
    const content = contentFor(
      { id: "n", type: "collection_completed", createdAt: "2026-10-08T10:00:00Z", read: false, actor: null, data: { name: "Re:Zero", pelles: 50, cosmetic: "Cadre « Mains de l'Envie »" }, details: null },
      "",
    );
    expect(content).toMatchObject({ label: "Collection complète", amount: 50, detail: "Gagné : Cadre « Mains de l'Envie »", title: [{ text: "Collection " }, { text: "Re:Zero", strong: true }, { text: " complète" }] });
  });
});
