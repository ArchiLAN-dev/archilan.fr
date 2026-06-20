import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { removeCommunityAvatar, uploadCommunityAvatar } from "./community-profile-api";

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
    expect(result).toEqual({ avatarUrl: "http://minio.test/media/community/avatars/x.png?sig" });
  });

  it("returns null when the upload is rejected", async () => {
    server.use(
      http.post(`${BASE}/community/profile/avatar`, () =>
        HttpResponse.json({ error: { code: "image_too_large" } }, { status: 422 }),
      ),
    );

    const file = new File([new Uint8Array([1])], "big.png", { type: "image/png" });
    expect(await uploadCommunityAvatar(file)).toBeNull();
  });

  it("returns null on network error", async () => {
    server.use(http.post(`${BASE}/community/profile/avatar`, () => HttpResponse.error()));
    const file = new File([new Uint8Array([1])], "me.png", { type: "image/png" });
    expect(await uploadCommunityAvatar(file)).toBeNull();
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
