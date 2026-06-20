import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { uploadTutorialImage } from "./tutorial-image-api";

const BASE = TEST_API_BASE_URL;

describe("uploadTutorialImage", () => {
  it("posts the file as multipart and returns key + url", async () => {
    let hadFile = false;
    server.use(
      http.post(`${BASE}/tutorial-images`, async ({ request }) => {
        const form = await request.formData();
        hadFile = form.get("file") instanceof File;
        return HttpResponse.json({ data: { key: "tutorials/abc.png", url: "http://minio.test/media/tutorials/abc.png?sig=1" } });
      }),
    );

    const result = await uploadTutorialImage(new File(["x"], "shot.png", { type: "image/png" }));

    expect(hadFile).toBe(true);
    expect(result).toEqual({ key: "tutorials/abc.png", url: "http://minio.test/media/tutorials/abc.png?sig=1" });
  });

  it("returns null on a rejected upload", async () => {
    server.use(http.post(`${BASE}/tutorial-images`, () => new HttpResponse(null, { status: 422 })));
    expect(await uploadTutorialImage(new File(["x"], "a.txt", { type: "text/plain" }))).toBeNull();
  });

  it("returns null on a malformed response", async () => {
    server.use(http.post(`${BASE}/tutorial-images`, () => HttpResponse.json({ data: { key: "x" } })));
    expect(await uploadTutorialImage(new File(["x"], "a.png", { type: "image/png" }))).toBeNull();
  });
});
