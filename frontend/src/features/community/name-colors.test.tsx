import { renderToStaticMarkup } from "react-dom/server";

import { ShopCosmeticPreview } from "@/features/wallet/shop-cosmetics";
import { cosmeticLabel } from "@/features/wallet/shop-api";
import { NameColorField } from "./community-profile-customization-form";
import { isNameColorStyle, nameColorOfStyle } from "./name-colors";
import { TitledName, isNameStyle } from "./titled-name";

/** Story 41.23: a colour bought for the name, sent by the API as the name style `color-<key>`. */
describe("name colours", () => {
  test("a colour style is a name style, from the palette only", () => {
    expect(isNameColorStyle("color-emerald")).toBe(true);
    expect(isNameColorStyle("color-gold")).toBe(false);
    expect(isNameStyle("color-ruby")).toBe(true);
    expect(isNameStyle("epic")).toBe(true);
    expect(nameColorOfStyle("color-azure")).toBe("#38bdf8");
  });

  test("the name is only tinted: no title, no emblem, on a card as on the profile", () => {
    const card = renderToStaticMarkup(<TitledName style="color-emerald" variant="card">Alice</TitledName>);
    const profile = renderToStaticMarkup(<TitledName style="color-emerald" variant="profile">Alice</TitledName>);

    expect(card).toContain("color:#34d399");
    expect(card).not.toContain("data-emblem");
    expect(profile).not.toContain("Administrateur");
    expect(renderToStaticMarkup(<TitledName style="legendary" variant="profile">Alice</TitledName>)).toContain("Administrateur");
  });

  test("the picker offers the colours bought, the others locked, and says when the rarity comes first", () => {
    const html = renderToStaticMarkup(<NameColorField name="Alice" onChange={() => undefined} owned={["emerald"]} rarityWins={false} value="emerald" />);

    expect(html).toContain("Couleur du pseudo");
    expect(html).toContain('aria-label="Émeraude"');
    expect(html).toContain('aria-label="Rubis - à acheter en boutique"');
    expect(html).toContain('href="/boutique"');
    expect(renderToStaticMarkup(<NameColorField name="Alice" onChange={() => undefined} owned={[]} rarityWins value={null} />)).toContain("décoche-le");
  });

  test("the shop names and previews a colour", () => {
    expect(cosmeticLabel("color", "amethyst")).toBe("Améthyste");
    expect(renderToStaticMarkup(<ShopCosmeticPreview cosmeticKey="amber" name="Alice" type="color" />)).toContain("color:#fbbf24");
  });
});
