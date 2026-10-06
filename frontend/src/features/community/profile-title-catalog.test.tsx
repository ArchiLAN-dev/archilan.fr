import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { AdminProfileTitlesView } from "@/features/admin/admin-profile-titles";
import { ShopCosmeticPreview } from "@/features/wallet/shop-cosmetics";
import { ProfileTitleField } from "./community-profile-customization-form";
import { fetchProfileTitleCatalog, titleKeyFrom, titleLockReason, writeProfileTitle, type ProfileTitle } from "./profile-title-catalog";

const catalog: ProfileTitle[] = [
  { key: "chasseur", label: "Chasseur de goals", access: "shop" },
  { key: "ancien", label: "Ancien", access: "members" },
  { key: "pionnier", label: "Pionnier", access: "free" },
];

const nobody = { admin: false, member: false, owned: [] as string[] };

/** Story 41.22: profile titles, written by the admins, sold in the shop, worn under the name. */
describe("profile titles", () => {
  test("a title is locked by its access, unless bought, member or admin", () => {
    expect(titleLockReason("free", "pionnier", nobody)).toBeNull();
    expect(titleLockReason("shop", "chasseur", nobody)).toBe("À acheter en boutique");
    expect(titleLockReason("shop", "chasseur", { ...nobody, owned: ["chasseur"] })).toBeNull();
    expect(titleLockReason("members", "ancien", nobody)).toBe("Réservé aux adhérents");
    expect(titleLockReason("members", "ancien", { ...nobody, member: true })).toBeNull();
    expect(titleLockReason("admins", "x", { ...nobody, member: true })).toBe("Réservé aux admins");
  });

  test("a key comes from the label", () => {
    expect(titleKeyFrom("Chasseur de goals")).toBe("chasseur-de-goals");
    expect(titleKeyFrom("  Élu·e !! ")).toBe("elu-e");
    expect(titleKeyFrom("a".repeat(50))).toHaveLength(32);
  });

  test("reads the catalog and writes a title", async () => {
    let body: unknown = null;
    server.use(
      http.get(`${TEST_API_BASE_URL}/profile-titles`, () => HttpResponse.json({ titles: catalog })),
      http.post(`${TEST_API_BASE_URL}/admin/profile-titles`, async ({ request }) => {
        body = await request.json();
        return new HttpResponse(null, { status: 201 });
      }),
    );

    expect(await fetchProfileTitleCatalog()).toEqual(catalog);
    expect(await writeProfileTitle(catalog[0])).toBeNull();
    expect(body).toEqual(catalog[0]);
  });

  test("the member picks among the titles they may wear, the others locked with why", () => {
    const html = renderToStaticMarkup(<ProfileTitleField catalog={catalog} onChange={() => undefined} rights={nobody} value="pionnier" />);

    expect(html).toContain("Titre de profil");
    expect(html).toContain("Chasseur de goals - À acheter en boutique");
    expect(html).toContain("Ancien - Réservé aux adhérents");
    expect(html).toContain("Pionnier");
    expect(html).toContain('href="/boutique"');

    const retired = renderToStaticMarkup(<ProfileTitleField catalog={catalog} onChange={() => undefined} rights={nobody} value="disparu" />);
    expect(retired).toContain("Titre retiré");
  });

  test("the shop shows a title as its text under the member's name", () => {
    const html = renderToStaticMarkup(<ShopCosmeticPreview cosmeticKey="chasseur" label="Chasseur de goals" name="Alice" type="title" />);

    expect(html).toContain("Alice");
    expect(html).toContain("Chasseur de goals");
  });

  test("the admin page lists each title with its access and state", () => {
    const html = renderToStaticMarkup(
      <AdminProfileTitlesView
        onChange={async (error: string | null) => error}
        titles={[
          { ...catalog[0], retired: false, position: 0 },
          { ...catalog[1], retired: true, position: 1 },
        ]}
      />,
    );

    expect(html).toContain("Chasseur de goals");
    expect(html).toContain("Boutique");
    expect(html).toContain("Retiré");
    expect(html).toContain("Rétablir");
    expect(html).toContain("Nouveau titre");
  });
});
