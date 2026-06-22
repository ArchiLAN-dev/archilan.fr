import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { uploadAchievementImage } from "./admin-achievements-api";

const BASE = TEST_API_BASE_URL;
const URL = `${BASE}/admin/community/achievements/image`;

describe("uploadAchievementImage", () => {
  const file = new File(["x"], "badge.png", { type: "image/png" });

  it("returns key and url on success", async () => {
    server.use(
      http.post(URL, () =>
        HttpResponse.json({ data: { key: "community/achievement-images/a.png", imageUrl: "http://minio.test/a.png" } }),
      ),
    );
    const result = await uploadAchievementImage(file);
    expect(result).toEqual({ key: "community/achievement-images/a.png", imageUrl: "http://minio.test/a.png" });
  });

  it("returns null on an error response", async () => {
    server.use(http.post(URL, () => new HttpResponse(null, { status: 422 })));
    expect(await uploadAchievementImage(file)).toBeNull();
  });

  it("returns null on a malformed payload", async () => {
    server.use(http.post(URL, () => HttpResponse.json({ data: { key: 1 } })));
    expect(await uploadAchievementImage(file)).toBeNull();
  });
});