import { renderToStaticMarkup } from "react-dom/server";

import { isCollection, originText, type Collection } from "./collection-api";
import { CollectionView } from "./collection-page";

const collection: Collection = {
  owned: 1,
  total: 4,
  items: [
    {
      type: "title",
      key: "phil",
      label: "Phil Connors",
      access: "reward",
      rarity: "epic",
      icon: "flame",
      status: "owned",
      origin: { source: "achievement", label: "Un jour sans fin", acquiredAt: "2026-10-05T10:00:00+00:00" },
      unlock: [],
    },
    { type: "title", key: "pionnier", label: "Pionnier", access: "free", rarity: "common", icon: null, status: "available", origin: null, unlock: [] },
    {
      type: "color",
      key: "ruby",
      label: "Rubis",
      access: "shop",
      rarity: null,
      icon: null,
      status: "locked",
      origin: null,
      unlock: [{ kind: "shop", label: "En boutique", detail: null, price: 120 }],
    },
    {
      type: "title",
      key: "legende",
      label: "Légende",
      access: "reward",
      rarity: "legendary",
      icon: "crown",
      status: "locked",
      origin: null,
      unlock: [{ kind: "achievement", label: "Succès « It's over 9000 »", detail: "9 001 checks.", price: null }],
    },
  ],
};

const wearer = { name: "Alice", avatarUrl: null, framing: null };

/** Story 41.29: « Ma collection ». */
describe("collection", () => {
  test("the payload is checked", () => {
    expect(isCollection(collection)).toBe(true);
    expect(isCollection({ ...collection, items: [{ ...collection.items[0], status: "maybe" }] })).toBe(false);
    expect(isCollection({ items: [], owned: 0 })).toBe(false);
  });

  test("says what is owned and where from, what may be worn, and how to get the rest", () => {
    const html = renderToStaticMarkup(<CollectionView collection={collection} wearer={wearer} />);

    expect(html).toContain("1</span> / 4 cosmétiques obtenus");
    expect(html).toContain("Obtenu · Succès « Un jour sans fin »");
    expect(html).toContain("Tu peux le porter");
    expect(html).toContain("Succès « It&#x27;s over 9000 »");
    expect(html).toContain("9 001 checks.");
    expect(html).toContain('href="/boutique"');
    expect(html).toContain('data-rarity="legendary"');
    // The member's ones first.
    expect(html.indexOf("Phil Connors")).toBeLessThan(html.indexOf("Rubis"));
  });

  test("an origin in words", () => {
    expect(originText({ source: "quest", label: "Spécial LAN", acquiredAt: "" })).toBe("Quête « Spécial LAN »");
    expect(originText({ source: "shop", label: null, acquiredAt: "" })).toBe("Acheté en boutique");
  });
});
