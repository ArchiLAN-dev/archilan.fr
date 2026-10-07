import { renderToStaticMarkup } from "react-dom/server";

import { contentFor, kudosTitle, sectionsOf, timeLabel } from "./notification-content";
import { NotificationRow } from "./notification-row";
import type { NotificationItem } from "./notifications-api";

const NOW = new Date(2026, 9, 7, 18, 0);

function item(over: Partial<NotificationItem> & { type: string }): NotificationItem {
  return { id: over.type, createdAt: new Date(2026, 9, 7, 17, 58).toISOString(), read: false, actor: null, data: {}, details: null, ...over };
}

const alice = { slug: "alice", displayName: "Alice", avatarUrl: null };
const bob = { slug: "bob", displayName: "Bob", avatarUrl: null };
const carol = { slug: "carol", displayName: "Carol", avatarUrl: null };

function row(items: NotificationItem[], fallback = "texte"): string {
  return renderToStaticMarkup(<NotificationRow fallback={fallback} href="/compte" items={items} onRead={() => undefined} onSelect={() => undefined} timeLabel="il y a 2 min" />);
}

/** Story 30.48: the notifications drawn with a visual, a family and the names standing out. */
describe("notification content", () => {
  test("an achievement shows its name, description and image, read by the API", () => {
    const achievement = item({
      type: "achievement_unlocked",
      data: { achievementKey: "witch_of_envy" },
      details: { achievement: { name: "Je t'aime", description: "Recevoir 3 000 items.", imageUrl: "https://m.test/a.webp" } },
    });

    const content = contentFor(achievement, "");
    expect(content).toMatchObject({ label: "Succès débloqué", tone: "reward", title: [{ text: "Je t'aime", strong: true }], detail: "Recevoir 3 000 items." });
    expect(content.visual).toEqual({ kind: "achievement", imageUrl: "https://m.test/a.webp" });
    expect(row([achievement])).toContain('src="https://m.test/a.webp"');
  });

  test("an achievement without its details still reads", () => {
    expect(contentFor(item({ type: "achievement_unlocked" }), "").title).toEqual([{ text: "Nouveau succès débloqué" }]);
  });

  test("a title won is drawn at its rarity, with where it comes from and a way to wear it", () => {
    const title = item({
      type: "cosmetic_unlocked",
      data: { type: "title", key: "phil", label: "Titre « Phil Connors »", source: "quest", sourceLabel: "Un jour sans fin" },
      details: { cosmetic: { name: "Phil Connors", rarity: "legendary", icon: "crown" } },
    });

    const content = contentFor(title, "");
    expect(content.titleBadge).toEqual({ label: "Phil Connors", rarity: "legendary", icon: "crown" });
    expect(content.detail).toBe("Gagné avec la quête « Un jour sans fin »");
    const html = row([title]);
    expect(html).toContain('data-rarity="legendary"');
    expect(html).toContain("Porter");
  });

  test("a frame won names itself from the stored label when the API has no preview", () => {
    const frame = item({ type: "cosmetic_unlocked", data: { type: "frame", key: "fire", label: "Cadre « Feu »", source: "achievement", sourceLabel: "Collectionneur" } });

    expect(contentFor(frame, "").title).toEqual([{ text: "Cadre " }, { text: "Feu", strong: true }, { text: " débloqué" }]);
    expect(row([frame])).toContain('src="/avatar-frames/fire-poster.webp"');
  });

  test("pelles show a signed amount and the reason", () => {
    const credit = contentFor(item({ type: "pelles_adjusted", data: { amount: 120, reason: "Merci" } }), "");
    expect(credit).toMatchObject({ label: "Pelles", tone: "pelles", amount: 120, detail: "« Merci »" });
    expect(row([item({ type: "pelles_adjusted", data: { amount: -50 } })])).toContain("−50");
  });

  test("a pending friend request is answered in place, an answered one is a plain line", () => {
    const pending = item({ type: "friend_request_received", actor: alice, details: { friendRequest: { id: "f1" } } });
    expect(contentFor(pending, "").friendRequestId).toBe("f1");
    expect(row([pending])).toContain("Accepter");

    const answered = item({ type: "friend_request_received", actor: alice });
    expect(row([answered])).not.toContain("Accepter");
  });

  test("a failed generation asks for an action and says when it is the member's config", () => {
    const failed = contentFor(item({ type: "generation_failed", data: { runTitle: "Hebdo", role: "player", slotName: "Kafei" } }), "");
    expect(failed).toMatchObject({ label: "Action requise", tone: "action", detail: "Ta config (slot « Kafei ») est en cause." });
    expect(failed.title).toEqual([{ text: "La génération de " }, { text: "Hebdo", strong: true }, { text: " a échoué" }]);
  });

  test("an unknown type falls back on the plain sentence", () => {
    expect(contentFor(item({ type: "something_new" }), "Nouvelle notification").title).toEqual([{ text: "Nouvelle notification" }]);
  });
});

describe("notification sections", () => {
  test("by period, the kudos of a same day on one row", () => {
    const today = (h: number) => new Date(2026, 9, 7, h).toISOString();
    const items = [
      item({ id: "k1", type: "kudos_received", actor: alice, createdAt: today(17) }),
      item({ id: "a", type: "achievement_unlocked", createdAt: today(16) }),
      item({ id: "k2", type: "kudos_received", actor: bob, createdAt: today(15) }),
      item({ id: "y", type: "moderation_reply", createdAt: new Date(2026, 9, 6, 12).toISOString() }),
      item({ id: "w", type: "quests_renewed", createdAt: new Date(2026, 9, 5, 9).toISOString() }),
      item({ id: "o", type: "quests_renewed", createdAt: new Date(2026, 8, 20, 9).toISOString() }),
    ];

    const sections = sectionsOf(items, NOW);

    expect(sections.map((s) => s.label)).toEqual(["Aujourd'hui", "Hier", "Cette semaine", "Plus ancien"]);
    expect(sections[0]?.rows.map((r) => r.items.map((i) => i.id))).toEqual([["k1", "k2"], ["a"]]);
  });

  test("several kudos name the first two and count the others", () => {
    const kudos = [alice, bob, carol, { ...carol, slug: "dan", displayName: "Dan" }].map((actor) => item({ type: "kudos_received", actor }));
    expect(kudosTitle(kudos)).toEqual([
      { text: "Alice", strong: true },
      { text: ", " },
      { text: "Bob", strong: true },
      { text: " et 2 autres" },
      { text: " t'ont envoyé un kudos" },
    ]);
    expect(kudosTitle(kudos.slice(0, 2)).map((s) => s.text).join("")).toBe("Alice et Bob t'ont envoyé un kudos");
  });

  test("times read from minutes to dates", () => {
    expect(timeLabel(new Date(2026, 9, 7, 17, 55).toISOString(), NOW)).toBe("il y a 5 min");
    expect(timeLabel(new Date(2026, 9, 7, 15, 0).toISOString(), NOW)).toBe("il y a 3 h");
    expect(timeLabel(new Date(2026, 9, 6, 15, 0).toISOString(), NOW)).toBe("hier");
    expect(timeLabel(new Date(2026, 9, 5, 15, 0).toISOString(), NOW)).toBe("lundi");
    expect(timeLabel(new Date(2026, 8, 3, 15, 0).toISOString(), NOW)).toBe("3 sept.");
  });
});
