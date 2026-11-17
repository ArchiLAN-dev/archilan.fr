import { apiFetch } from "../../lib/apiFetch";
import {
  checkTrackerPackUpdate,
  fetchTrackerPacks,
  formatPackSize,
  importTrackerPack,
  isAdminTrackerPack,
  rollbackTrackerPack,
  updateTrackerPack,
  upgradeTrackerPack,
} from "./admin-tracker-packs-api";

jest.mock("../../lib/apiFetch");

const mockApiFetch = jest.mocked(apiFetch);

const pack = {
  id: "p1",
  name: "Majora's Mask: Recompiled",
  author: "Seto10987, G4M3R L1F3",
  packageUid: "MM_AP",
  gameName: "The Legend of Zelda - Majoras Mask",
  sourceUrl: "https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack",
  licenseSpdx: "MIT",
  hasApVariant: true,
  versionId: "v1",
  packageVersion: "0.9.5.3",
  gitTag: "v0.9.5.3",
  sizeBytes: 14_000_000,
  variants: [{ uid: "standard", displayName: "Maps and items", flags: ["ap"] }],
  minPopTrackerVersion: "0.32.0",
  importedAt: "2026-11-16T10:00:00+00:00",
  enabled: false,
  isDefault: false,
  defaultVariantUid: null,
  licenseAcknowledged: false,
  availableUpdate: null,
  lastCheckedAt: null,
  lastCheckError: null,
  versions: [],
  rootPath: "",
  sha256: "abc",
  compatibility: [],
};

describe("isAdminTrackerPack (story 54.1)", () => {
  it("accepts a pack, nullable fields included", () => {
    expect(isAdminTrackerPack(pack)).toBe(true);
    expect(isAdminTrackerPack({ ...pack, author: null, licenseSpdx: null, packageVersion: null, gitTag: null })).toBe(true);
  });

  it("refuses a pack with malformed variants", () => {
    expect(isAdminTrackerPack({ ...pack, variants: [{ uid: "a", displayName: "A", flags: [1] }] })).toBe(false);
    expect(isAdminTrackerPack({ ...pack, sizeBytes: "big" })).toBe(false);
  });
});

describe("fetchTrackerPacks", () => {
  it("returns the game's packs", async () => {
    mockApiFetch.mockResolvedValue(new Response(JSON.stringify({ data: [pack] }), { status: 200 }));
    await expect(fetchTrackerPacks("g1")).resolves.toEqual([pack]);
    expect(mockApiFetch.mock.calls[0]?.[0]).toContain("/admin/games/g1/tracker-packs");
  });

  it("returns null on an error or a half-valid list", async () => {
    mockApiFetch.mockResolvedValue(new Response("{}", { status: 403 }));
    await expect(fetchTrackerPacks("g1")).resolves.toBeNull();
    mockApiFetch.mockResolvedValue(new Response(JSON.stringify({ data: [pack, { id: "x" }] }), { status: 200 }));
    await expect(fetchTrackerPacks("g1")).resolves.toBeNull();
  });
});

describe("importTrackerPack", () => {
  it("posts the link and returns the imported pack", async () => {
    mockApiFetch.mockResolvedValue(
      new Response(JSON.stringify({ data: { id: "p1", name: "MM", packageVersion: "1.0", hasApVariant: false } }), { status: 201 }),
    );

    await expect(importTrackerPack("g1", "https://github.com/o/r")).resolves.toEqual({
      ok: true,
      name: "MM",
      packageVersion: "1.0",
      hasApVariant: false,
    });
    const init = mockApiFetch.mock.calls.at(-1)?.[1];
    expect(init?.method).toBe("POST");
    expect(init?.body).toBe(JSON.stringify({ url: "https://github.com/o/r" }));
  });

  it("returns the API message on a refusal", async () => {
    mockApiFetch.mockResolvedValue(
      new Response(JSON.stringify({ error: { code: "tracker_pack_url_invalid", message: "Ce lien n'est pas un lien GitHub de pack." } }), { status: 422 }),
    );
    await expect(importTrackerPack("g1", "https://example.com")).resolves.toEqual({ ok: false, message: "Ce lien n'est pas un lien GitHub de pack." });
  });

  it("returns a generic message when the server cannot be reached", async () => {
    mockApiFetch.mockRejectedValue(new Error("offline"));
    await expect(importTrackerPack("g1", "https://github.com/o/r")).resolves.toEqual({
      ok: false,
      message: "L'import a échoué, réessaie dans un instant.",
    });
  });
});

describe("formatPackSize", () => {
  it("reads in kilobytes then megabytes", () => {
    expect(formatPackSize(10)).toBe("1 Ko");
    expect(formatPackSize(300 * 1024)).toBe("300 Ko");
    expect(formatPackSize(14_000_000)).toBe("13,4 Mo");
  });
});

describe("updateTrackerPack (story 54.2)", () => {
  it("rejects a pack without its sharing state", () => {
    const withoutEnabled: Record<string, unknown> = { ...pack };
    delete withoutEnabled.enabled;
    expect(isAdminTrackerPack(withoutEnabled)).toBe(false);
    expect(isAdminTrackerPack({ ...pack, defaultVariantUid: 3 })).toBe(false);
  });

  it("sends only the changed fields", async () => {
    mockApiFetch.mockResolvedValue(new Response(null, { status: 204 }));
    await expect(updateTrackerPack("p1", { enabled: true })).resolves.toEqual({ ok: true });
    expect(mockApiFetch.mock.lastCall?.[0]).toContain("/admin/tracker-packs/p1");
    expect(mockApiFetch.mock.lastCall?.[1]).toMatchObject({ body: '{"enabled":true}', method: "PATCH" });
  });

  it("relays the reason of a refusal", async () => {
    mockApiFetch.mockResolvedValue(
      new Response(JSON.stringify({ error: { code: "tracker_pack_license_unacknowledged", message: "Aucune licence détectée." } }), { status: 422 }),
    );
    await expect(updateTrackerPack("p1", { enabled: true })).resolves.toEqual({ ok: false, message: "Aucune licence détectée." });
  });

  it("falls back to a generic message when the API is unreachable", async () => {
    mockApiFetch.mockRejectedValue(new Error("offline"));
    const result = await updateTrackerPack("p1", { isDefault: true });
    expect(result.ok).toBe(false);
  });
});

describe("pack updates (story 54.3)", () => {
  it("accepts a pack with an update and kept versions, and refuses a malformed one", () => {
    const withUpdate = {
      ...pack,
      availableUpdate: { packageVersion: "0.9.5.4", downloadUrl: "https://x", sha256: null, changelog: ["a"], gitTag: null },
      versions: [{ id: "v1", packageVersion: "0.9.5.3", gitTag: null, sizeBytes: 1, importedAt: "2026-11-16T10:00:00+00:00", rootPath: "", sha256: "abc", variants: [], current: true }],
    };
    expect(isAdminTrackerPack(withUpdate)).toBe(true);
    expect(isAdminTrackerPack({ ...withUpdate, availableUpdate: { packageVersion: 3 } })).toBe(false);
    expect(isAdminTrackerPack({ ...withUpdate, versions: [{ id: "v1" }] })).toBe(false);
  });

  it("checks a pack now", async () => {
    mockApiFetch.mockResolvedValue(new Response(null, { status: 204 }));
    await expect(checkTrackerPackUpdate("p1")).resolves.toEqual({ ok: true });
    expect(mockApiFetch.mock.lastCall?.[0]).toContain("/admin/tracker-packs/p1/check-update");
  });

  it("installs the update, or a link, and reports a reset default variant", async () => {
    mockApiFetch.mockResolvedValue(new Response(JSON.stringify({ data: { versionId: "v3", packageVersion: "0.9.5.4", defaultVariantReset: true } }), { status: 200 }));
    await expect(upgradeTrackerPack("p1")).resolves.toEqual({ ok: true, packageVersion: "0.9.5.4", defaultVariantReset: true });
    expect(mockApiFetch.mock.lastCall?.[1]).toMatchObject({ body: "{}", method: "POST" });

    await upgradeTrackerPack("p1", "https://github.com/o/r/releases/tag/v2");
    expect(mockApiFetch.mock.lastCall?.[1]).toMatchObject({ body: '{"url":"https://github.com/o/r/releases/tag/v2"}' });
  });

  it("relays why an update or a way back was refused", async () => {
    mockApiFetch.mockResolvedValue(new Response(JSON.stringify({ error: { code: "tracker_pack_checksum_mismatch", message: "Empreinte fausse." } }), { status: 422 }));
    await expect(upgradeTrackerPack("p1")).resolves.toEqual({ ok: false, message: "Empreinte fausse." });

    mockApiFetch.mockResolvedValue(new Response(JSON.stringify({ error: { code: "tracker_pack_version_not_found", message: "Plus conservée." } }), { status: 404 }));
    await expect(rollbackTrackerPack("p1", "v0")).resolves.toEqual({ ok: false, message: "Plus conservée." });
    expect(mockApiFetch.mock.lastCall?.[0]).toContain("/admin/tracker-packs/p1/rollback");
  });
});
