import { renderToStaticMarkup } from "react-dom/server";

import { frameLockReason } from "./avatar-frame-catalog";
import { CosmeticRewardFields, isCosmeticReward, isCosmeticRewardView } from "./cosmetic-reward-picker";
import { hrefFor, messageFor } from "./notification-center";
import { ProfileTitleBadge } from "./profile-title-badge";
import { isTitleBadge, titleLockReason } from "./profile-title-catalog";

const nobody = { admin: false, member: false, owned: [] as string[] };

/** Story 41.28: cosmetics won through achievements and quests. */
describe("cosmetic rewards", () => {
  test("a reward cosmetic is won, never bought: locked until owned", () => {
    expect(titleLockReason("reward", "phil", nobody)).toBe("À gagner (succès ou quête)");
    expect(titleLockReason("reward", "phil", { ...nobody, owned: ["phil"] })).toBeNull();
    expect(frameLockReason({ key: "aura", legendary: false, shop: false }, "reward", nobody)).toBe("À gagner (succès ou quête)");
    expect(frameLockReason({ key: "aura", legendary: false, shop: false }, "reward", { ...nobody, admin: true })).toBe("À gagner (succès ou quête)");
  });

  test("the title's tooltip says the achievement or the quest it came from", () => {
    const badge = { label: "Phil Connors", rarity: "epic" as const, icon: null, access: "reward" as const, origin: "Succès « Un jour sans fin »" };
    expect(isTitleBadge(badge)).toBe(true);
    const html = renderToStaticMarkup(<ProfileTitleBadge title={badge} withTooltip />);
    expect(html).toContain("Succès « Un jour sans fin »");
    expect(html).not.toContain("À gagner");
    expect(renderToStaticMarkup(<ProfileTitleBadge title={{ ...badge, origin: null }} withTooltip />)).toContain("À gagner (succès ou quête)");
  });

  test("the picker offers the kinds, then the cosmetics with the reward ones first", () => {
    expect(renderToStaticMarkup(<CosmeticRewardFields id="r" onChange={() => undefined} options={[]} value={null} />)).toContain("Couleur de pseudo");
    const html = renderToStaticMarkup(
      <CosmeticRewardFields
        id="r"
        onChange={() => undefined}
        options={[
          { key: "shop-one", label: "En vente", access: "shop" },
          { key: "phil", label: "Phil Connors", access: "reward" },
        ]}
        value={{ type: "title", key: "phil" }}
      />,
    );
    expect(html.indexOf("Phil Connors (récompense)")).toBeLessThan(html.indexOf("En vente"));
  });

  test("an API reward is checked", () => {
    expect(isCosmeticReward({ type: "color", key: "ruby" })).toBe(true);
    expect(isCosmeticReward({ type: "hat", key: "x" })).toBe(false);
    expect(isCosmeticRewardView({ type: "title", key: "phil", label: "Titre « Phil Connors »" })).toBe(true);
    expect(isCosmeticRewardView({ type: "title", key: "phil" })).toBe(false);
  });

  test("the notification names the cosmetic and where it came from, and leads to the profile editor", () => {
    const item = {
      id: "n1",
      type: "cosmetic_unlocked",
      createdAt: "2026-10-07T10:00:00+00:00",
      read: false,
      actor: null,
      data: { type: "title", key: "phil", label: "Titre « Phil Connors »", source: "achievement", sourceLabel: "Un jour sans fin" },
    };
    expect(messageFor(item)).toBe("Débloqué : Titre « Phil Connors » (succès « Un jour sans fin »)");
    expect(messageFor({ ...item, data: { ...item.data, source: "quest", sourceLabel: "Spécial LAN" } })).toBe("Débloqué : Titre « Phil Connors » (quête « Spécial LAN »)");
    expect(hrefFor(item)).toBe("/compte/profil");
  });
});
