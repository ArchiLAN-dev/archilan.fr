import {
  breadcrumbJsonLd,
  organizationJsonLd,
  publisherRef,
  serializeJsonLd,
  websiteJsonLd,
} from "./structured-data";

const APP_URL = "http://localhost:3000";

describe("serializeJsonLd", () => {
  it("escapes the script-breaking characters but still round-trips as JSON", () => {
    const data = { headline: "Tom & Jerry </script><script>alert(1)", nested: { x: "<b>" } };
    const out = serializeJsonLd(data);

    expect(out).not.toContain("<");
    expect(out).not.toContain(">");
    expect(out).not.toContain("&");
    expect(out).toContain("\\u003c");
    expect(out).toContain("\\u0026");
    expect(JSON.parse(out)).toEqual(data);
  });
});

describe("organizationJsonLd", () => {
  it("is an Organization with absolute logo/url, socials and the real siège address", () => {
    const org = organizationJsonLd();

    expect(org["@type"]).toBe("Organization");
    expect(org.name).toBe("ArchiLAN");
    expect(org.url).toBe(APP_URL);
    expect(org.logo).toBe(`${APP_URL}/images/logo.webp`);
    expect(Array.isArray(org.sameAs) ? org.sameAs.length : 0).toBeGreaterThan(0);

    const address = org.address as Record<string, unknown>;
    expect(address["@type"]).toBe("PostalAddress");
    expect(address.addressLocality).toBe("Clermont-Ferrand");
    expect(address.postalCode).toBe("63000");
    expect(address.addressCountry).toBe("FR");
  });
});

describe("websiteJsonLd", () => {
  it("is a WebSite with name and absolute url", () => {
    const site = websiteJsonLd();

    expect(site["@type"]).toBe("WebSite");
    expect(site.name).toBe("ArchiLAN");
    expect(site.url).toBe(APP_URL);
  });
});

describe("publisherRef", () => {
  it("is an Organization carrying an ImageObject logo (for Article publisher)", () => {
    const pub = publisherRef();
    const logo = pub.logo as Record<string, unknown>;

    expect(pub["@type"]).toBe("Organization");
    expect(logo["@type"]).toBe("ImageObject");
    expect(logo.url).toBe(`${APP_URL}/images/logo.webp`);
  });
});

describe("breadcrumbJsonLd", () => {
  it("builds an ordered BreadcrumbList with absolute item urls and 1-based positions", () => {
    const crumb = breadcrumbJsonLd([
      { name: "Accueil", path: "/" },
      { name: "Jeux", path: "/jeux" },
      { name: "A Link to the Past", path: "/jeux/alttp" },
    ]);

    expect(crumb["@type"]).toBe("BreadcrumbList");
    const items = crumb.itemListElement as Array<Record<string, unknown>>;
    expect(items).toHaveLength(3);
    expect(items[0]).toMatchObject({ "@type": "ListItem", position: 1, name: "Accueil", item: `${APP_URL}/` });
    expect(items[1]).toMatchObject({ position: 2, name: "Jeux", item: `${APP_URL}/jeux` });
    expect(items[2]).toMatchObject({ position: 3, item: `${APP_URL}/jeux/alttp` });
  });
});
