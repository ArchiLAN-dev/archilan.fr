import { http, HttpResponse } from "msw";
import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { AdminMinimapperzCodes, assignmentSummary, importSummary } from "./admin-minimapperz-codes";
import { claimMinimapperzCode, importMinimapperzCodes, isMinimapperzCodes, type MinimapperzCodes } from "./minimapperz-api";

const overview: MinimapperzCodes = {
  imported: 3,
  assigned: 2,
  free: 1,
  registrantsWithoutCode: 2,
  codes: [
    { code: "MMZ-1", userId: "u1", displayName: "Alice", registered: true, assignedAt: "2026-11-02T20:00:00+00:00" },
    { code: "MMZ-2", userId: "u2", displayName: "Bob", registered: false, assignedAt: "2026-11-02T20:01:00+00:00" },
    { code: "MMZ-3", userId: null, displayName: null, registered: false, assignedAt: null },
  ],
};

describe("Minimapperz codes (story 49.2)", () => {
  it("reads the overview shape", () => {
    expect(isMinimapperzCodes(overview)).toBe(true);
    expect(isMinimapperzCodes({ ...overview, codes: [{ code: 1 }] })).toBe(false);
    expect(isMinimapperzCodes({ imported: 1 })).toBe(false);
  });

  it("tells an import and an assignment in words", () => {
    expect(importSummary({ added: 3, duplicates: 1, invalid: ["bad code"], overview })).toBe(
      "3 codes ajoutés, 1 déjà présent, 1 ligne ignorée (espace ou trop long) : bad code.",
    );
    expect(importSummary({ added: 1, duplicates: 0, invalid: [], overview })).toBe("1 code ajouté.");
    expect(assignmentSummary({ assigned: 2, withoutCode: 0, overview })).toBe("2 codes attribués.");
    expect(assignmentSummary({ assigned: 0, withoutCode: 1, overview })).toBe("Aucun code attribué. 1 inscrit sans code : il n'en reste plus de libre.");
  });

  it("relays a refused import", async () => {
    server.use(
      http.post(`${TEST_API_BASE_URL}/admin/events/e-1/minimapperz/codes`, () =>
        HttpResponse.json({ error: { code: "invalid_minimapperz_codes", message: "Aucun code valide." } }, { status: 422 }),
      ),
    );

    await expect(importMinimapperzCodes("e-1", "  ")).resolves.toEqual({ kind: "error", message: "Aucun code valide." });
  });

  it("gets a registrant's code, or nothing", async () => {
    server.use(
      http.post(`${TEST_API_BASE_URL}/events/e-1/minimapperz/code`, () => HttpResponse.json({ data: { code: "MMZ-1", lanUrl: "https://app.minimapperz.com/lan/a/" } })),
      http.post(`${TEST_API_BASE_URL}/events/e-2/minimapperz/code`, () => HttpResponse.json({ data: null })),
    );

    await expect(claimMinimapperzCode("e-1")).resolves.toEqual({ code: "MMZ-1", lanUrl: "https://app.minimapperz.com/lan/a/" });
    await expect(claimMinimapperzCode("e-2")).resolves.toBeNull();
  });

  it("lists who holds which code and warns when codes run short", () => {
    const client = new QueryClient();
    client.setQueryData(["admin-minimapperz-codes", "e-1"], overview);
    const html = renderToStaticMarkup(
      <QueryClientProvider client={client}>
        <AdminMinimapperzCodes eventId="e-1" />
      </QueryClientProvider>,
    );

    expect(html).toContain("MMZ-1");
    expect(html).toContain("Alice");
    expect(html).toContain("(inscription annulée)");
    expect(html).toContain("Libre");
    expect(html.match(/Reprendre/g)).toHaveLength(2);
    expect(html).toContain("Il manque 1 code pour couvrir tous les inscrits.");
  });
});
