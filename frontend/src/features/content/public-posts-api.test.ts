import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getPublicPosts, getPublicPostBySlugFromApi } from "./public-posts-api";

const BASE = TEST_API_BASE_URL;

const validPostPayload = {
  slug: "news-test",
  title: "Test news",
  type: "news",
  status: "published",
  excerpt: "A short excerpt",
  coverImageUrl: null,
  body: ["Paragraph one"],
  readingTime: "2 min",
  publishedAt: "2024-06-01T10:00:00Z",
  relatedEventSlug: null,
  vodUrl: null,
};

describe("getPublicPosts", () => {
  it("returns parsed posts on success", async () => {
    server.use(
      http.get(`${BASE}/posts`, () => HttpResponse.json({ data: [validPostPayload] })),
    );
    const result = await getPublicPosts();
    expect(result.some((p) => p.slug === "news-test")).toBe(true);
  });

  it("returns fallback posts on network error", async () => {
    server.use(http.get(`${BASE}/posts`, () => HttpResponse.error()));
    const result = await getPublicPosts();
    expect(Array.isArray(result)).toBe(true);
  });

  it("returns fallback posts when response fails type guard", async () => {
    server.use(http.get(`${BASE}/posts`, () => HttpResponse.json({ wrong: true })));
    const result = await getPublicPosts();
    expect(Array.isArray(result)).toBe(true);
  });
});

describe("getPublicPostBySlugFromApi", () => {
  // Use a slug that does not exist in mock data so fallback resolves to null
  const UNKNOWN_SLUG = "nonexistent-post-99999";

  it("returns parsed post on success", async () => {
    server.use(
      http.get(`${BASE}/posts/news-test`, () =>
        HttpResponse.json({ data: validPostPayload }),
      ),
    );
    const result = await getPublicPostBySlugFromApi("news-test");
    expect(result).not.toBeNull();
    expect(result?.slug).toBe("news-test");
    expect(result?.type).toBe("news");
  });

  it("returns null on network error for unknown slug", async () => {
    server.use(
      http.get(`${BASE}/posts/${UNKNOWN_SLUG}`, () => HttpResponse.error()),
    );
    expect(await getPublicPostBySlugFromApi(UNKNOWN_SLUG)).toBeNull();
  });

  it("returns null when response fails type guard for unknown slug", async () => {
    server.use(
      http.get(`${BASE}/posts/${UNKNOWN_SLUG}`, () =>
        HttpResponse.json({ data: { slug: "x", title: "y", type: "invalid-type" } }),
      ),
    );
    expect(await getPublicPostBySlugFromApi(UNKNOWN_SLUG)).toBeNull();
  });
});
