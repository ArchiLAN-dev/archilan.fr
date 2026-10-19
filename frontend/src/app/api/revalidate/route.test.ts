/**
 * @jest-environment node
 */
import { revalidateTag } from "next/cache";

import { POST } from "./route";
import { MAX_TAGS, bearerMatches, readTags } from "@/lib/revalidate-request";

jest.mock("next/cache", () => ({ revalidateTag: jest.fn() }));

const SECRET = "s3cret-value";

function post(body: unknown, authorization: string | null = `Bearer ${SECRET}`): Request {
  const headers = new Headers({ "Content-Type": "application/json" });
  if (authorization !== null) headers.set("Authorization", authorization);
  return new Request("http://localhost:3000/api/revalidate", {
    method: "POST",
    headers,
    body: typeof body === "string" ? body : JSON.stringify(body),
  });
}

describe("POST /api/revalidate (story 34.10)", () => {
  const original = process.env.REVALIDATE_SECRET;

  beforeEach(() => {
    process.env.REVALIDATE_SECRET = SECRET;
    jest.mocked(revalidateTag).mockClear();
  });

  afterAll(() => {
    process.env.REVALIDATE_SECRET = original;
  });

  it("revalidates each tag so the next request waits for fresh data", async () => {
    const res = await POST(post({ tags: ["events", "event:fef2ae3e08cf7b41b7819123bb7dbee9", "events"] }));

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ revalidated: ["events", "event:fef2ae3e08cf7b41b7819123bb7dbee9"] });
    expect(jest.mocked(revalidateTag).mock.calls).toEqual([
      ["events", { expire: 0 }],
      ["event:fef2ae3e08cf7b41b7819123bb7dbee9", { expire: 0 }],
    ]);
  });

  it("is disabled without a configured secret", async () => {
    process.env.REVALIDATE_SECRET = "";

    const res = await POST(post({ tags: ["events"] }, "Bearer "));

    expect(res.status).toBe(503);
    expect(revalidateTag).not.toHaveBeenCalled();
  });

  it.each([
    ["no header", null],
    ["a wrong secret", "Bearer nope"],
    ["another scheme", `Basic ${SECRET}`],
  ])("refuses %s", async (_, authorization) => {
    const res = await POST(post({ tags: ["events"] }, authorization));

    expect(res.status).toBe(401);
    expect(revalidateTag).not.toHaveBeenCalled();
  });

  it.each([
    ["not JSON", "{"],
    ["no tags", {}],
    ["an empty list", { tags: [] }],
    ["a tag with a space", { tags: ["event:a b"] }],
    ["an uppercase kind", { tags: ["Events"] }],
    ["too many tags", { tags: Array.from({ length: MAX_TAGS + 1 }, (_, i) => `event:e${i}`) }],
  ])("refuses a body with %s", async (_, body) => {
    const res = await POST(post(body));

    expect(res.status).toBe(400);
    expect(revalidateTag).not.toHaveBeenCalled();
  });
});

describe("revalidate request rules", () => {
  it("accepts a post slug tag", () => {
    expect(readTags({ tags: ["posts", "post:retour-sur-archilan-3"] })).toEqual(["posts", "post:retour-sur-archilan-3"]);
  });

  it("never matches an empty secret", () => {
    expect(bearerMatches("Bearer ", "")).toBe(false);
  });
});
