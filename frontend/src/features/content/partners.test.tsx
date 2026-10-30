import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";

import { ADMIN_PARTNERS_KEY, AdminPartners, displayHost, movedIds, partnerStateLabel, tooManyVisible } from "@/features/admin/admin-partners";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { isAdminPartner, isPartner, reorderPartners, savePartner, uploadPartnerLogo, type AdminPartner } from "./partners-api";

const BASE = TEST_API_BASE_URL;

const partner: AdminPartner = {
  id: "p1",
  name: "Ludothèque",
  description: "Des jeux à prêter.",
  url: "https://www.ludo.example/fr",
  logoUrl: "http://minio.test/media/content-logos/p1/logo-ab.png",
  active: true,
  visible: true,
};

function renderAdmin(partners: AdminPartner[] | null): string {
  const client = new QueryClient();
  client.setQueryData(ADMIN_PARTNERS_KEY, partners);
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <AdminPartners />
    </QueryClientProvider>,
  );
}

/** Story 45.1: the partners of the association, and their admin. */
describe("partners", () => {
  it("accepts a complete partner and refuses an incomplete one", () => {
    expect(isPartner({ id: "p1", name: "Ludo", description: "", url: "https://ludo.example", logoUrl: null })).toBe(true);
    expect(isPartner({ id: "p1", name: "Ludo", description: "", url: "https://ludo.example" })).toBe(false);
    expect(isAdminPartner(partner)).toBe(true);
    expect(isAdminPartner({ ...partner, visible: "yes" })).toBe(false);
  });

  it("moves a partner by one, and leaves the list as is at its ends", () => {
    expect(movedIds(["a", "b", "c"], 2, -1)).toEqual(["a", "c", "b"]);
    expect(movedIds(["a", "b", "c"], 0, 1)).toEqual(["b", "a", "c"]);
    expect(movedIds(["a", "b", "c"], 0, -1)).toEqual(["a", "b", "c"]);
    expect(movedIds(["a", "b", "c"], 2, 1)).toEqual(["a", "b", "c"]);
  });

  it("says why a partner is hidden, shows the bare host of its link, and warns past five visible", () => {
    expect(partnerStateLabel({ active: true, visible: true })).toBe("Affiché");
    expect(partnerStateLabel({ active: true, visible: false })).toBe("Masqué : pas de logo");
    expect(partnerStateLabel({ active: false, visible: false })).toBe("Masqué");
    expect(displayHost("https://www.ludo.example/fr?ref=1")).toBe("ludo.example");
    expect(displayHost("pas une url")).toBe("pas une url");
    const five = Array.from({ length: 5 }, () => ({ visible: true }));
    expect(tooManyVisible([...five, { visible: false }])).toBe(false);
    expect(tooManyVisible([...five, { visible: true }])).toBe(true);
  });

  it("renders the empty state, and the list with its state", () => {
    expect(renderAdmin([])).toContain("Aucun partenaire pour l&#x27;instant.");
    const html = renderAdmin([partner, { ...partner, id: "p2", name: "Boutique", logoUrl: null, visible: false }]);
    expect(html).toContain("Ludothèque");
    expect(html).toContain("ludo.example");
    expect(html).toContain("Masqué : pas de logo");
    expect(html).not.toContain("pensé pour 5 partenaires");
  });

  it("creates a partner and reads the API's message on a refusal", async () => {
    server.use(http.post(`${BASE}/admin/partners`, () => HttpResponse.json({ data: { id: "new" }, meta: {} }, { status: 201 })));
    expect(await savePartner({ name: "Ludo", description: "", url: "https://ludo.example", active: true }, null)).toEqual({ ok: true, id: "new" });

    server.use(
      http.put(`${BASE}/admin/partners/p1`, () =>
        HttpResponse.json({ error: { code: "partner_url_invalid", message: "Le lien commence par https://." } }, { status: 422 }),
      ),
    );
    expect(await savePartner({ name: "Ludo", description: "", url: "http://ludo.example", active: true }, "p1")).toEqual({
      ok: false,
      message: "Le lien commence par https://.",
    });
  });

  it("sends the full order, and the logo as multipart", async () => {
    let order: unknown = null;
    let hadFile = false;
    server.use(
      http.post(`${BASE}/admin/partners/reorder`, async ({ request }) => {
        order = await request.json();
        return new HttpResponse(null, { status: 204 });
      }),
      http.post(`${BASE}/admin/partners/p1/logo`, async ({ request }) => {
        hadFile = (await request.formData()).get("file") instanceof File;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    expect(await reorderPartners(["p2", "p1"])).toBeNull();
    expect(order).toEqual({ ids: ["p2", "p1"] });
    expect(await uploadPartnerLogo("p1", new File(["x"], "logo.png", { type: "image/png" }))).toBeNull();
    expect(hadFile).toBe(true);

    server.use(http.post(`${BASE}/admin/partners/p1/logo`, () => HttpResponse.json({ error: { message: "Type de fichier non supporté." } }, { status: 422 })));
    expect(await uploadPartnerLogo("p1", new File(["<svg/>"], "logo.svg", { type: "image/svg+xml" }))).toBe("Type de fichier non supporté.");
  });
});
