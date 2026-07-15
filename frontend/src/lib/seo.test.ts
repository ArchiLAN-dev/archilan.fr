import { buildPageMetadata, SITE_NAME } from "./seo";

describe("buildPageMetadata", () => {
  it("sets the canonical and OG url to the given path", () => {
    const meta = buildPageMetadata({ title: "Événements", description: "desc", path: "/evenements" });

    expect(meta.alternates?.canonical).toBe("/evenements");
    expect(meta.openGraph?.url).toBe("/evenements");
  });

  it("brands the OG and Twitter titles and reuses the description", () => {
    const meta = buildPageMetadata({ title: "Jeux", description: "Catalogue", path: "/jeux" });

    expect(meta.openGraph?.title).toBe(`Jeux | ${SITE_NAME}`);
    expect(meta.twitter?.title).toBe(`Jeux | ${SITE_NAME}`);
    expect(meta.openGraph?.description).toBe("Catalogue");
    expect(meta.twitter?.description).toBe("Catalogue");
  });

  it("keeps a string title so the %s | ArchiLAN template applies", () => {
    const meta = buildPageMetadata({ title: "Boutique", description: "d", path: "/boutique" });

    expect(meta.title).toBe("Boutique");
  });

  it("makes the home title absolute and its OG title unbranded (no doubled brand)", () => {
    const meta = buildPageMetadata({
      title: "ArchiLAN - Événements Archipelago multiworld en France",
      description: "d",
      path: "/",
      absoluteTitle: true,
    });

    expect(meta.title).toEqual({ absolute: "ArchiLAN - Événements Archipelago multiworld en France" });
    expect(meta.openGraph?.title).toBe("ArchiLAN - Événements Archipelago multiworld en France");
  });

  it("falls back to the default OG image and defaults the type to website", () => {
    const og = buildPageMetadata({ title: "Adhésion", description: "d", path: "/adhesion" }).openGraph;
    const twitter = buildPageMetadata({ title: "Adhésion", description: "d", path: "/adhesion" }).twitter;
    const images = og && "images" in og ? og.images : undefined;

    expect(Array.isArray(images) ? images.length : 0).toBe(1);
    expect(og && "type" in og ? og.type : undefined).toBe("website");
    expect(twitter && "card" in twitter ? twitter.card : undefined).toBe("summary_large_image");
  });

  it("uses caller-supplied images and og type when given", () => {
    const og = buildPageMetadata({
      title: "Article",
      description: "d",
      path: "/actualites/x",
      ogType: "article",
      images: [{ url: "/cover.webp", alt: "Cover" }],
    }).openGraph;
    const images = og && "images" in og ? og.images : undefined;

    expect(og && "type" in og ? og.type : undefined).toBe("article");
    expect(Array.isArray(images) ? images[0] : undefined).toEqual({ url: "/cover.webp", alt: "Cover" });
  });
});