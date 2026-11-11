import { renderToStaticMarkup } from "react-dom/server";

import { HelpGuideRow, suggestedSlug } from "@/features/admin/admin-help-guides";
import { guideHref, HubPellesCard } from "@/features/registrant-hub/hub-tools";
import { RegistrantHubView } from "@/features/registrant-hub/registrant-hub";
import { HelpGuideArticle, HelpGuideIndex } from "./help-guide-views";
import { isAdminHelpGuide, isHelpGuide, isHelpGuideCard, type AdminHelpGuide } from "./help-guides-api";

const pelles = { slug: "pelles", title: "Les pelles", summary: "La monnaie d'ArchiLAN." };

/** Story 53.5: the help guides. */
describe("the help index", () => {
  it("lists the Archipelago guide first, then the published guides", () => {
    const html = renderToStaticMarkup(<HelpGuideIndex guides={[pelles]} />);

    expect(html.indexOf('href="/aide/archipelago"')).toBeGreaterThan(-1);
    expect(html.indexOf('href="/aide/archipelago"')).toBeLessThan(html.indexOf('href="/aide/pelles"'));
    expect(html).toContain("La monnaie d&#x27;ArchiLAN.");
  });

  it("still offers the Archipelago guide without any other", () => {
    expect(renderToStaticMarkup(<HelpGuideIndex guides={[]} />)).toContain('href="/aide/archipelago"');
  });
});

describe("a guide page", () => {
  it("renders the orga's markdown under the title", () => {
    const html = renderToStaticMarkup(<HelpGuideArticle guide={{ ...pelles, body: "## Les pelles d'or\n\nPour la boutique.", updatedAt: "2026-11-11T10:00:00+00:00" }} />);

    expect(html).toContain("<h1");
    expect(html).toContain("Les pelles");
    // Authored headings start at h3, under the page's own h1.
    expect(html).toMatch(/<h3[^>]*>Les pelles d(&#x27;|')or<\/h3>/);
    expect(html).toContain('href="/aide"');
  });
});

describe("the guides payload", () => {
  it("accepts a card, a guide and an admin guide", () => {
    const guide = { ...pelles, body: "Texte", updatedAt: "2026-11-11T10:00:00+00:00" };
    expect(isHelpGuideCard(pelles)).toBe(true);
    expect(isHelpGuide(guide)).toBe(true);
    expect(isHelpGuide(pelles)).toBe(false);
    expect(isAdminHelpGuide({ ...guide, id: "g1", published: false })).toBe(true);
    expect(isAdminHelpGuide(guide)).toBe(false);
  });
});

describe("the admin of the guides", () => {
  const guide: AdminHelpGuide = { ...pelles, id: "g1", body: "Texte", published: true, updatedAt: "2026-11-11T10:00:00+00:00" };
  const row = (overrides: Partial<AdminHelpGuide>) =>
    renderToStaticMarkup(
      <ul>
        <HelpGuideRow guide={{ ...guide, ...overrides }} isFirst isLast={false} onDelete={() => undefined} onEdit={() => undefined} onMove={() => undefined} pending={false} />
      </ul>,
    );

  it("says whether a guide is published, and keeps a published one from deletion", () => {
    expect(row({})).toContain("Publié");
    expect(row({})).toMatch(/aria-label="Supprimer Les pelles"[^>]*disabled=""/);
    expect(row({ published: false })).toContain("Brouillon");
    expect(row({ published: false })).not.toMatch(/aria-label="Supprimer Les pelles"[^>]*disabled=""/);
  });

  it("suggests an address from the title", () => {
    expect(suggestedSlug("Ta première partie !")).toBe("ta-premiere-partie");
    expect(suggestedSlug("a".repeat(70))).toHaveLength(60);
  });
});

describe("the guides in the registrant space", () => {
  it("links a tool card to its guide only once the guide is published", () => {
    expect(guideHref([pelles], "pelles")).toBe("/aide/pelles");
    expect(guideHref([pelles], "passeur")).toBeNull();
    expect(guideHref(null, "pelles")).toBeNull();

    const wallet = { gold: 1, events: [], bronze: [], history: { items: [], page: 1, perPage: 20, total: 0 } };
    expect(renderToStaticMarkup(<HubPellesCard eventId="e1" helpHref="/aide/pelles" wallet={wallet} />)).toContain("C&#x27;est quoi ?");
    expect(renderToStaticMarkup(<HubPellesCard eventId="e1" wallet={wallet} />)).not.toContain("C&#x27;est quoi ?");
  });

  it("lists the guides in the space", () => {
    const html = renderToStaticMarkup(
      <RegistrantHubView
        event={{ id: "e1", slug: "archilan-4", title: "ArchiLAN 4", status: "closed", location: "Lille" }}
        guides={[pelles]}
        now={new Date("2026-11-11T10:00:00Z")}
        registration={{ registrationId: "r1", confirmed: true }}
      />,
    );

    expect(html).toContain("Les guides");
    expect(html).toContain('href="/aide/archipelago"');
    expect(html).toContain('href="/aide/pelles"');
  });
});
