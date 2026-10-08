import { renderToStaticMarkup } from "react-dom/server";

import { CENTRED_FRAMING } from "@/features/community/image-framing";
import type { TitleBadge } from "@/features/community/profile-title-catalog";
import { ProfileHeaderPreview, TryOnBody, VISITOR, currentLook, lookWith, type ShopShopper } from "./shop-try-on";

const worn: TitleBadge = { label: "Phil Connors", rarity: "rare", icon: null, access: "shop" };
const titles = (key: string): TitleBadge | null => (key === "phil" ? worn : null);

const member: ShopShopper = {
  avatarUrl: null,
  name: "Alice",
  framing: null,
  frame: "fire",
  banner: { presetKey: "aurora", imageUrl: "https://m.test/b.webp", framing: CENTRED_FRAMING, overlay: 40 },
  nameStyle: "legendary",
  rarityStyle: "legendary",
  title: "phil",
};

/** Story 41.32: an item tried on the member's whole profile header. */
describe("try-on", () => {
  const before = currentLook(member, titles);

  test("today's header carries the member's banner, frame, name style and title", () => {
    expect(before).toMatchObject({ frame: "fire", nameStyle: "legendary", title: worn, banner: { presetKey: "aurora" } });
  });

  test("only the item tried replaces what the member wears", () => {
    expect(lookWith(before, { type: "frame", cosmeticKey: "comet" }, null)).toEqual({ ...before, frame: "comet" });
    expect(lookWith(before, { type: "banner", cosmeticKey: "sunset" }, null).banner).toEqual({ presetKey: "sunset", imageUrl: null, framing: CENTRED_FRAMING, overlay: 50 });
    expect(lookWith(before, { type: "color", cosmeticKey: "emerald" }, null)).toEqual({ ...before, nameStyle: "color-emerald" });
    const title: TitleBadge = { label: "Groundhog", rarity: "epic", icon: null, access: "shop" };
    expect(lookWith(before, { type: "title", cosmeticKey: "groundhog" }, title)).toEqual({ ...before, title });
    expect(lookWith(before, { type: "title", cosmeticKey: "unknown" }, null).title?.label).toBe("unknown");
  });

  test("the header shows the banner, the name in its colour and the title", () => {
    const html = renderToStaticMarkup(<ProfileHeaderPreview look={{ ...before, nameStyle: "color-emerald" }} />);
    expect(html).toContain('data-name-style="color-emerald"');
    expect(html).toContain(">Alice<");
    expect(html).toContain("Phil Connors");
  });

  test("a colour under a winning rarity style says where to turn it off; before / after toggles", () => {
    const html = renderToStaticMarkup(<TryOnBody after item={{ type: "color" }} look={before} onToggle={() => undefined} shopper={member} />);
    expect(html).toContain("ton pseudo légendaire passe avant les couleurs");
    expect(html).toMatch(/aria-pressed="true"[^>]*>Avec l&#x27;objet/);
    expect(renderToStaticMarkup(<TryOnBody after={false} item={{ type: "color" }} look={before} onToggle={() => undefined} shopper={member} />)).not.toContain("passe avant");
    expect(renderToStaticMarkup(<TryOnBody after item={{ type: "color" }} look={before} onToggle={() => undefined} shopper={VISITOR} />)).not.toContain("passe avant");
  });
});
