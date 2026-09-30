import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { removeCommunityAvatar, removeCommunityBanner, uploadCommunityAvatar, uploadCommunityBanner } from "./community-profile-api";

const BASE = TEST_API_BASE_URL;

describe("uploadCommunityAvatar", () => {
  it("posts the file as multipart and returns the resolved URL", async () => {
    let receivedFilename: string | null = null;
    server.use(
      http.post(`${BASE}/community/profile/avatar`, async ({ request }) => {
        const form = await request.formData();
        const file = form.get("file");
        receivedFilename = file instanceof File ? file.name : null;
        return HttpResponse.json({ data: { avatarUrl: "http://minio.test/media/community/avatars/x.png?sig" } });
      }),
    );

    const file = new File([new Uint8Array([1, 2, 3])], "me.png", { type: "image/png" });
    const result = await uploadCommunityAvatar(file);

    expect(receivedFilename).toBe("me.png");
    expect(result).toEqual({ ok: true, url: "http://minio.test/media/community/avatars/x.png?sig" });
  });

  it("says why the upload was refused (story 30.40)", async () => {
    server.use(
      http.post(`${BASE}/community/profile/avatar`, () =>
        HttpResponse.json({ error: { code: "image_gif_admin_only" } }, { status: 422 }),
      ),
    );

    const file = new File([new Uint8Array([1])], "a.gif", { type: "image/gif" });
    expect(await uploadCommunityAvatar(file)).toEqual({ ok: false, code: "image_gif_admin_only" });
  });

  it("has no reason on a network error", async () => {
    server.use(http.post(`${BASE}/community/profile/avatar`, () => HttpResponse.error()));
    const file = new File([new Uint8Array([1])], "me.png", { type: "image/png" });
    expect(await uploadCommunityAvatar(file)).toEqual({ ok: false, code: null });
  });
});

describe("removeCommunityAvatar", () => {
  it("returns the fallback URL (null when none)", async () => {
    server.use(
      http.delete(`${BASE}/community/profile/avatar`, () => HttpResponse.json({ data: { avatarUrl: null } })),
    );
    expect(await removeCommunityAvatar()).toEqual({ avatarUrl: null });
  });

  it("returns null on network error", async () => {
    server.use(http.delete(`${BASE}/community/profile/avatar`, () => HttpResponse.error()));
    expect(await removeCommunityAvatar()).toBeNull();
  });
});

describe("banner image (story 30.40)", () => {
  it("uploads the file and returns the image URL", async () => {
    server.use(
      http.post(`${BASE}/community/profile/banner`, () =>
        HttpResponse.json({ data: { bannerImageUrl: "http://minio.test/media/community/banners/b.png?sig" } }),
      ),
    );

    const file = new File([new Uint8Array([1])], "b.png", { type: "image/png" });
    expect(await uploadCommunityBanner(file)).toEqual({ ok: true, url: "http://minio.test/media/community/banners/b.png?sig" });
  });

  it("says why a banner is refused", async () => {
    server.use(
      http.post(`${BASE}/community/profile/banner`, () =>
        HttpResponse.json({ error: { code: "banner_not_allowed" } }, { status: 403 }),
      ),
    );

    const file = new File([new Uint8Array([1])], "b.png", { type: "image/png" });
    expect(await uploadCommunityBanner(file)).toEqual({ ok: false, code: "banner_not_allowed" });
  });

  it("removes the banner image", async () => {
    server.use(http.delete(`${BASE}/community/profile/banner`, () => HttpResponse.json({ data: { bannerImageUrl: null } })));
    expect(await removeCommunityBanner()).toBe(true);

    server.use(http.delete(`${BASE}/community/profile/banner`, () => HttpResponse.error()));
    expect(await removeCommunityBanner()).toBe(false);
  });
});
